<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ApprovalStep;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Notification;
use App\Models\Signature;
use App\Models\User;
use App\Support\SubmissionPdfService;
use App\Support\WorkflowConfigService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FormSubmissionController extends Controller
{
    public function __construct(
        private readonly SubmissionPdfService $pdfService,
        private readonly WorkflowConfigService $workflowConfigService,
    ) {
    }

    /**
     * Get all form submissions with role-aware visibility.
     */
    public function index(Request $request)
    {
        try {
            $query = FormSubmission::query()
                ->with([
                    'form.workflow',
                    'user',
                    'approvalSteps.approver',
                    'approvalSteps.signature.user',
                ])
                ->orderByDesc('created_at');

            $this->scopeVisibleSubmissions($query, $request->user());

            if ($request->filled('status')) {
                $query->where('current_status', $request->string('status')->value());
            }

            if ($request->filled('form_id')) {
                $query->where('form_id', $request->integer('form_id'));
            }

            if ($request->filled('search')) {
                $this->applySearchFilter($query, trim((string) $request->string('search')->value()));
            }

            $submissions = $query->paginate(15);

            return response()->json([
                'submissions' => collect($submissions->items())
                    ->map(fn (FormSubmission $submission) => $this->transformSubmission($submission))
                    ->values(),
                'pagination' => [
                    'current_page' => $submissions->currentPage(),
                    'per_page' => $submissions->perPage(),
                    'total' => $submissions->total(),
                    'last_page' => $submissions->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get Submissions Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get single form submission details.
     */
    public function show($id)
    {
        try {
            $submission = FormSubmission::with([
                'form.workflow',
                'user',
                'approvalSteps.approver',
                'approvalSteps.signature.user',
            ])->findOrFail($id);

            if (!$this->userCanViewSubmission($submission, auth()->user())) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            return response()->json([
                'submission' => $this->transformSubmission($submission),
                'workflow' => $submission->resolvedWorkflowConfig(),
                'available_actions' => $this->getAvailableActions($submission),
            ]);
        } catch (\Exception $e) {
            Log::error('Get Submission Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Create new form submission.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'form_id' => 'required|exists:forms,id',
            ]);

            $form = Form::with('workflow')->findOrFail($validated['form_id']);
            $workflowSnapshot = $form->workflow
                ? $this->workflowConfigService->normalize((array) $form->workflow->workflow_config)
                : [];

            if (!$form->is_active) {
                return response()->json([
                    'error' => 'Form tidak aktif dan belum bisa digunakan.',
                ], 422);
            }

            $submission = DB::transaction(function () use ($request, $form, $workflowSnapshot) {
                $submission = FormSubmission::create([
                    'form_id' => $form->id,
                    'user_id' => $request->user()->id,
                    'form_data' => [],
                    'form_snapshot' => $form->form_config,
                    'workflow_snapshot' => $workflowSnapshot,
                    'current_status' => 'submitted',
                    'current_step' => 1,
                    'created_by' => $request->user()->id,
                ]);

                $formData = $this->extractValidatedFormData($request, $form, $submission);
                $submission->form_data = $formData;
                $submission->save();

                $this->initializeWorkflow($submission);

                return $submission->fresh([
                    'form.workflow',
                    'user',
                    'approvalSteps.approver',
                    'approvalSteps.signature.user',
                ]);
            });

            $this->notifySubmissionCreated($submission);
            $this->pdfService->generate($submission);

            return response()->json([
                'success' => true,
                'submission' => $this->transformSubmission($submission->fresh([
                    'form.workflow',
                    'user',
                    'approvalSteps.approver',
                    'approvalSteps.signature.user',
                ])),
            ], 201);
        } catch (\Exception $e) {
            Log::error('Create Submission Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Approve or process the current workflow step.
     */
    public function approve(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'notes' => 'nullable|string|max:2000',
                'signature_id' => 'nullable|exists:signatures,id',
                'form_data' => 'sometimes|array',
            ]);

            $submission = FormSubmission::with([
                'form.workflow',
                'user',
                'approvalSteps.approver',
                'approvalSteps.signature.user',
            ])->findOrFail($id);

            $currentStep = $this->getCurrentPendingApprovalStep($submission);

            if (!$currentStep || !$this->canActOnStep($currentStep, auth()->user(), 'approve forms')) {
                return response()->json(['error' => 'Unauthorized or invalid approval step'], 403);
            }

            $stepConfig = $this->getWorkflowStepConfig($submission, $currentStep->step_number) ?? [];

            if ($this->canReviseFormData($currentStep, auth()->user()) && $request->has('form_data')) {
                $submission->form_data = $this->validateRevisionFormData($request, $submission);
                $submission->save();
            }

            if ($this->stepNotesRequired($stepConfig) && blank($validated['notes'] ?? null)) {
                return response()->json([
                    'error' => 'Catatan wajib diisi untuk langkah ini.',
                ], 422);
            }

            if ($this->stepRequiresSignature($stepConfig) && empty($validated['signature_id'])) {
                return response()->json([
                    'error' => 'Signature is required for this approval step',
                ], 422);
            }

            if (!empty($validated['signature_id'])) {
                $signature = Signature::findOrFail($validated['signature_id']);

                if ((int) $signature->user_id !== (int) auth()->id()) {
                    return response()->json(['error' => 'Signature does not belong to the current user'], 422);
                }

                if ((int) ($signature->metadata['approval_step_id'] ?? 0) !== (int) $currentStep->id) {
                    return response()->json(['error' => 'Signature does not match the current approval step'], 422);
                }
            }

            $currentStep->fill([
                'notes' => filled($validated['notes'] ?? null) ? trim((string) $validated['notes']) : null,
                'status' => 'approved',
                'approver_id' => auth()->id(),
                'approved_at' => now(),
                'signature_id' => $validated['signature_id'] ?? null,
            ])->save();

            $this->advanceWorkflow($submission, $currentStep);

            $submission = $submission->fresh([
                'form.workflow',
                'user',
                'approvalSteps.approver',
                'approvalSteps.signature.user',
            ]);

            $this->pdfService->generate($submission);
            $this->notifyWorkflowProgress($submission, $currentStep);

            return response()->json([
                'success' => true,
                'submission' => $this->transformSubmission($submission->fresh([
                    'form.workflow',
                    'user',
                    'approvalSteps.approver',
                    'approvalSteps.signature.user',
                ])),
            ]);
        } catch (\Exception $e) {
            Log::error('Approve Submission Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Reject the current workflow step.
     */
    public function reject(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'rejection_reason' => 'required|string|max:2000',
            ]);

            $submission = FormSubmission::with([
                'form.workflow',
                'user',
                'approvalSteps.approver',
                'approvalSteps.signature.user',
            ])->findOrFail($id);

            $currentStep = $this->getCurrentPendingApprovalStep($submission);

            if (!$currentStep || !$this->canActOnStep($currentStep, auth()->user(), 'reject forms')) {
                return response()->json(['error' => 'Unauthorized or invalid rejection step'], 403);
            }

            $stepConfig = $this->stepConfigForApprovalStep($currentStep);

            $currentStep->fill([
                'notes' => $validated['rejection_reason'],
                'status' => 'rejected',
                'approver_id' => auth()->id(),
                'approved_at' => now(),
            ])->save();

            $submission->update([
                'current_status' => $this->stepRejectStatus($stepConfig),
                'current_step' => $currentStep->step_number,
                'rejection_reason' => $validated['rejection_reason'],
            ]);

            $submission = $submission->fresh([
                'form.workflow',
                'user',
                'approvalSteps.approver',
                'approvalSteps.signature.user',
            ]);

            $this->pdfService->generate($submission);
            $this->notifySubmissionRejected($submission, $currentStep);

            return response()->json([
                'success' => true,
                'submission' => $this->transformSubmission($submission),
            ]);
        } catch (\Exception $e) {
            Log::error('Reject Submission Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function transformSubmission(FormSubmission $submission): array
    {
        $submission->loadMissing([
            'form.workflow',
            'user',
            'approvalSteps.approver',
            'approvalSteps.signature.user',
        ]);

        $currentPendingStep = $this->getCurrentPendingApprovalStep($submission);
        $data = $submission->toArray();
        $data['form']['form_config'] = $submission->resolvedFormConfig();

        if ($submission->form?->workflow) {
            $data['form']['workflow'] = array_merge(
                $data['form']['workflow'] ?? [],
                ['workflow_config' => $this->getWorkflowConfig($submission)]
            );
        }

        $data['workflow_snapshot'] = $this->getWorkflowConfig($submission);
        $data['available_actions'] = $this->getAvailableActions($submission);
        $data['current_pending_step'] = $currentPendingStep
            ? array_merge($currentPendingStep->toArray(), [
                'actor_label' => $this->getStepActorLabel($currentPendingStep),
            ])
            : null;
        $data['pdf_preview_url'] = $this->pdfService->previewUrl($submission);
        $data['pdf_download_url'] = $this->pdfService->downloadUrl($submission);
        $data['can_preview_pdf'] = $submission->pdf_path !== null || $submission->current_status !== 'rejected';

        return $data;
    }

    private function extractValidatedFormData(Request $request, Form $form, FormSubmission $submission): array
    {
        $preparedFormData = (array) $request->input('form_data', []);
        $fields = $this->getFormFields($form);

        foreach ($fields as $field) {
            $fieldId = (string) ($field['id'] ?? '');
            $autoFill = $field['auto_fill'] ?? null;

            if ($fieldId === '' || $autoFill === null) {
                continue;
            }

            $preparedFormData[$fieldId] = $this->resolveAutoFillValue($autoFill, $request->user());
        }

        $request->merge(['form_data' => $preparedFormData]);
        $validated = $request->validate($this->buildFormValidationRules($fields));
        $formData = [];

        foreach ($fields as $field) {
            $fieldId = (string) ($field['id'] ?? '');

            if ($fieldId === '') {
                continue;
            }

            if (($field['type'] ?? 'text') === 'file') {
                $uploadedFile = $request->file("form_data.{$fieldId}");

                if ($uploadedFile) {
                    $filename = $fieldId . '_' . now()->format('YmdHis') . '.' . $uploadedFile->getClientOriginalExtension();
                    $storedPath = $uploadedFile->storeAs("submissions/{$submission->id}", $filename, 'public');
                    $formData[$fieldId] = $storedPath;
                } else {
                    $formData[$fieldId] = null;
                }

                continue;
            }

            $value = data_get($validated, "form_data.{$fieldId}");

            if (($field['type'] ?? null) === 'number' && $value !== null && $value !== '') {
                $value = is_numeric($value) ? $value + 0 : $value;
            }

            if (($field['type'] ?? null) === 'checkbox' && $value === null) {
                $value = [];
            }

            $formData[$fieldId] = $value;
        }

        return $formData;
    }

    private function buildFormValidationRules(array $fields): array
    {
        $rules = [
            'form_data' => ['array'],
        ];

        foreach ($fields as $field) {
            $fieldId = (string) ($field['id'] ?? '');
            $fieldType = $field['type'] ?? 'text';

            if ($fieldId === '') {
                continue;
            }

            $fieldRules = [$field['required'] ?? false ? 'required' : 'nullable'];
            $fieldKey = "form_data.{$fieldId}";
            $options = $this->normalizeOptions($field['options'] ?? []);

            switch ($fieldType) {
                case 'email':
                    $fieldRules[] = 'email';
                    $fieldRules[] = 'max:255';
                    break;

                case 'number':
                    $fieldRules[] = 'numeric';
                    break;

                case 'date':
                    $fieldRules[] = 'date';
                    break;

                case 'select':
                case 'radio':
                    $fieldRules[] = 'string';

                    if ($options !== []) {
                        $fieldRules[] = 'in:' . implode(',', $options);
                    }
                    break;

                case 'checkbox':
                    $fieldRules[] = 'array';

                    if ($options !== []) {
                        $rules["{$fieldKey}.*"] = ['in:' . implode(',', $options)];
                    }
                    break;

                case 'file':
                    $fieldRules[] = 'file';
                    $fieldRules[] = 'max:5120';
                    break;

                default:
                    $fieldRules[] = 'string';
                    break;
            }

            foreach ($this->explodeValidationRules($field['validation'] ?? null) as $validationRule) {
                $fieldRules[] = $validationRule;
            }

            $rules[$fieldKey] = array_values(array_unique($fieldRules));
        }

        return $rules;
    }

    private function initializeWorkflow(FormSubmission $submission): void
    {
        $steps = $this->getWorkflowSteps($submission);

        if ($steps === []) {
            $submission->update([
                'current_status' => 'submitted',
                'current_step' => 1,
            ]);

            return;
        }

        $this->activateWorkflowStepSequence($submission, $steps[0], $submission->created_at);
    }

    private function advanceWorkflow(FormSubmission $submission, ApprovalStep $currentStep): void
    {
        $stepConfig = $this->stepConfigForApprovalStep($currentStep);
        $nextStep = $this->resolveNextWorkflowStepConfig($submission, $stepConfig);

        $this->activateWorkflowStepSequence($submission, $nextStep);
    }

    private function activateWorkflowStepSequence(FormSubmission $submission, ?array $stepConfig, mixed $referenceTime = null): void
    {
        while ($stepConfig) {
            if ($this->isAutoStep($stepConfig)) {
                $this->storeApprovalStepFromConfig($submission, $stepConfig, [
                    'status' => 'approved',
                    'approver_id' => $this->resolveAutoApproverId($submission, $stepConfig),
                    'approved_at' => $referenceTime ?? now(),
                    'notes' => $this->defaultAutoStepNotes($stepConfig),
                ]);

                $submission->update([
                    'current_status' => $this->stepApproveStatus($stepConfig),
                    'current_step' => $stepConfig['step_number'],
                ]);

                $referenceTime = now();
                $stepConfig = $this->resolveNextWorkflowStepConfig($submission, $stepConfig);
                continue;
            }

            $this->storeApprovalStepFromConfig($submission, $stepConfig, [
                'status' => 'pending',
            ]);

            $submission->update([
                'current_status' => $this->stepEntryStatus($stepConfig),
                'current_step' => $stepConfig['step_number'],
            ]);

            return;
        }

        $submission->update([
            'current_status' => 'completed',
            'current_step' => $submission->approvalSteps()->max('step_number') ?: 1,
        ]);
    }

    private function storeApprovalStepFromConfig(FormSubmission $submission, array $stepConfig, array $attributes = []): ApprovalStep
    {
        return ApprovalStep::updateOrCreate(
            [
                'form_submission_id' => $submission->id,
                'step_number' => $stepConfig['step_number'],
            ],
            array_merge([
                'step_key' => $stepConfig['step_key'] ?? null,
                'step_name' => $stepConfig['name'] ?? 'Workflow Step',
                'approver_role' => $this->resolveApproverRoleLabel($stepConfig),
                'actor_type' => $stepConfig['actor_type'] ?? null,
                'actor_value' => $stepConfig['actor_value'] ?? null,
                'actor_label' => $this->resolveActorLabelFromConfig($stepConfig),
                'status' => 'pending',
                'notes' => null,
                'signature_id' => null,
                'config_snapshot' => $stepConfig,
                'approver_id' => null,
                'approved_at' => null,
            ], $attributes)
        );
    }

    private function getCurrentPendingApprovalStep(FormSubmission $submission): ?ApprovalStep
    {
        $submission->loadMissing('approvalSteps');

        return $submission->approvalSteps
            ->where('status', 'pending')
            ->sortBy('step_number')
            ->first();
    }

    private function getWorkflowConfig(FormSubmission $submission): array
    {
        return $this->workflowConfigService->normalize($submission->resolvedWorkflowConfig());
    }

    private function getWorkflowSteps(FormSubmission $submission): array
    {
        return $this->getWorkflowConfig($submission)['steps'] ?? [];
    }

    private function resolveNextWorkflowStepConfig(FormSubmission $submission, array $stepConfig): ?array
    {
        $nextStepKey = trim((string) ($stepConfig['next_step_key'] ?? ''));

        if ($nextStepKey !== '') {
            foreach ($this->getWorkflowSteps($submission) as $step) {
                if (($step['step_key'] ?? null) === $nextStepKey) {
                    return $step;
                }
            }
        }

        foreach ($this->getWorkflowSteps($submission) as $step) {
            if ((int) ($step['step_number'] ?? 0) > (int) ($stepConfig['step_number'] ?? 0)) {
                return $step;
            }
        }

        return null;
    }

    private function isAutoStep(array $step): bool
    {
        return (bool) ($step['auto_complete'] ?? false)
            || in_array($step['action'] ?? null, ['submit', 'complete'], true);
    }

    private function stepEntryStatus(array $stepConfig): string
    {
        return (string) ($stepConfig['entry_status'] ?? 'pending');
    }

    private function stepApproveStatus(array $stepConfig): string
    {
        return (string) ($stepConfig['approve_status'] ?? 'completed');
    }

    private function stepRejectStatus(array $stepConfig): string
    {
        return (string) ($stepConfig['reject_status'] ?? 'rejected');
    }

    private function getAvailableActions(FormSubmission $submission): array
    {
        $currentStep = $this->getCurrentPendingApprovalStep($submission);

        if (!$currentStep || !$this->canActOnStep($currentStep, auth()->user(), 'approve forms')) {
            return [];
        }

        $stepConfig = $this->stepConfigForApprovalStep($currentStep);

        return [[
            'action' => $stepConfig['action'] ?? 'approve',
            'step_number' => $currentStep->step_number,
            'step_name' => $currentStep->step_name,
            'actor_label' => $this->getStepActorLabel($currentStep),
            'label' => $stepConfig['cta_label'] ?? 'Setujui',
            'reject_label' => $stepConfig['reject_label'] ?? 'Tolak',
            'notes_placeholder' => $stepConfig['notes_placeholder'] ?? 'Tambahkan catatan untuk langkah ini.',
            'notes_required' => $this->stepNotesRequired($stepConfig),
            'can_reject' => auth()->user()?->can('reject forms') ?? false,
            'requires_signature' => $this->stepRequiresSignature($stepConfig),
            'can_edit_form' => $this->canReviseFormData($currentStep, auth()->user()),
        ]];
    }

    private function getWorkflowStepConfig(FormSubmission $submission, int $stepNumber): ?array
    {
        foreach ($this->getWorkflowSteps($submission) as $step) {
            if ((int) ($step['step_number'] ?? 0) === $stepNumber) {
                return $step;
            }
        }

        return null;
    }

    private function stepConfigForApprovalStep(ApprovalStep $step): array
    {
        if (is_array($step->config_snapshot) && $step->config_snapshot !== []) {
            return $step->config_snapshot;
        }

        $step->loadMissing('formSubmission.form.workflow');

        return $step->formSubmission
            ? ($this->getWorkflowStepConfig($step->formSubmission, $step->step_number) ?? [])
            : [];
    }

    private function canActOnStep(ApprovalStep $step, User $user, string $permission): bool
    {
        if (!$user->can($permission)) {
            return false;
        }

        if ($user->hasRole('Admin')) {
            return true;
        }

        return $this->userMatchesStepActor($step, $user);
    }

    private function userMatchesStepActor(ApprovalStep $step, User $user): bool
    {
        $step->loadMissing('formSubmission');

        $actorType = $this->stepActorType($step);
        $actorValue = $this->stepActorValue($step);

        return match ($actorType) {
            'requester' => (int) $step->formSubmission?->user_id === (int) $user->id,
            'role' => $actorValue !== null && $user->hasRole($actorValue),
            'user' => (int) $actorValue === (int) $user->id,
            default => $step->approver_role !== null && $user->hasRole($step->approver_role),
        };
    }

    private function userCanViewSubmission(FormSubmission $submission, User $user): bool
    {
        if ($user->hasRole('Admin')) {
            return true;
        }

        if ((int) $submission->user_id === (int) $user->id) {
            return true;
        }

        $submission->loadMissing('approvalSteps.formSubmission');

        return $submission->approvalSteps
            ->contains(fn (ApprovalStep $step) => $this->userMatchesStepActor($step, $user) || (int) $step->approver_id === (int) $user->id);
    }

    private function scopeVisibleSubmissions(Builder $query, User $user): void
    {
        if ($user->hasRole('Admin')) {
            return;
        }

        $roles = $user->roles->pluck('name')->all();

        $query->where(function (Builder $submissionQuery) use ($user, $roles) {
            $submissionQuery->where('user_id', $user->id)
                ->orWhereHas('approvalSteps', function (Builder $approvalQuery) use ($user, $roles) {
                    $approvalQuery->where('approver_id', $user->id)
                        ->orWhere(function (Builder $actorQuery) use ($user) {
                            $actorQuery->where('actor_type', 'user')
                                ->where('actor_value', (string) $user->id);
                        });

                    if ($roles !== []) {
                        $approvalQuery->orWhere(function (Builder $actorQuery) use ($roles) {
                            $actorQuery->where('actor_type', 'role')
                                ->whereIn('actor_value', $roles);
                        })->orWhere(function (Builder $legacyQuery) use ($roles) {
                            $legacyQuery->whereNull('actor_type')
                                ->whereIn('approver_role', $roles);
                        });
                    }
                });
        });
    }

    private function applySearchFilter(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $matchingStatuses = $this->matchingStatusesForSearch($search);

        $query->where(function (Builder $submissionQuery) use ($search, $matchingStatuses) {
            $submissionQuery->whereHas('form', function (Builder $formQuery) use ($search) {
                $formQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhereHas('workflow', function (Builder $workflowQuery) use ($search) {
                        $workflowQuery->where('name', 'like', "%{$search}%");
                    });
            })->orWhereHas('user', function (Builder $userQuery) use ($search) {
                $userQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%")
                    ->orWhere('employee_id', 'like', "%{$search}%");
            })->orWhereHas('approvalSteps', function (Builder $approvalQuery) use ($search) {
                $approvalQuery->where('step_name', 'like', "%{$search}%")
                    ->orWhere('actor_label', 'like', "%{$search}%")
                    ->orWhere('approver_role', 'like', "%{$search}%");
            });

            if (ctype_digit($search)) {
                $submissionQuery->orWhere('form_submissions.id', (int) $search);
            }

            if ($matchingStatuses !== []) {
                $submissionQuery->orWhereIn('current_status', $matchingStatuses);
            }
        });
    }

    private function matchingStatusesForSearch(string $search): array
    {
        $normalizedSearch = mb_strtolower(trim($search));

        if ($normalizedSearch === '') {
            return [];
        }

        return FormSubmission::query()
            ->distinct()
            ->pluck('current_status')
            ->filter()
            ->filter(fn (string $status) => $this->statusMatchesSearch($status, $normalizedSearch))
            ->values()
            ->all();
    }

    private function statusMatchesSearch(string $status, string $search): bool
    {
        $statusAliases = [
            $status,
            str_replace(['_', '-'], ' ', $status),
        ];

        if ($status === 'submitted') {
            $statusAliases[] = 'dikirim';
        }

        if ($status === 'completed') {
            $statusAliases[] = 'selesai';
        }

        if ($status === 'rejected') {
            $statusAliases[] = 'ditolak';
            $statusAliases[] = 'tolak';
        }

        foreach ($statusAliases as $alias) {
            $normalizedAlias = mb_strtolower(trim($alias));

            if (str_contains($normalizedAlias, $search) || str_contains($search, $normalizedAlias)) {
                return true;
            }
        }

        return false;
    }

    private function getFormFields(Form $form): array
    {
        return $form->form_config['fields'] ?? [];
    }

    private function getSubmissionFields(FormSubmission $submission): array
    {
        return $submission->resolvedFormConfig()['fields'] ?? [];
    }

    private function normalizeOptions(array|string|null $options): array
    {
        if (is_string($options)) {
            return collect(explode(',', $options))
                ->map(fn (string $option) => trim($option))
                ->filter()
                ->values()
                ->all();
        }

        if (!is_array($options)) {
            return [];
        }

        return collect($options)
            ->map(fn ($option) => trim((string) $option))
            ->filter()
            ->values()
            ->all();
    }

    private function explodeValidationRules(null|string|array $validation): array
    {
        if (is_array($validation)) {
            return $validation;
        }

        if (!is_string($validation) || trim($validation) === '') {
            return [];
        }

        return collect(explode('|', $validation))
            ->map(fn (string $rule) => trim($rule))
            ->filter()
            ->values()
            ->all();
    }

    private function resolveAutoFillValue(string $autoFill, User $user): mixed
    {
        return match ($autoFill) {
            'user.name' => $user->name,
            'user.email' => $user->email,
            'user.department' => $user->department,
            'user.employee_id' => $user->employee_id,
            'today' => now()->toDateString(),
            default => null,
        };
    }

    private function validateRevisionFormData(Request $request, FormSubmission $submission): array
    {
        $fields = $this->getSubmissionFields($submission);
        $existingFormData = $submission->form_data ?? [];
        $incomingData = (array) $request->input('form_data', []);
        $mergedData = array_merge($existingFormData, $incomingData);

        foreach ($fields as $field) {
            $fieldId = (string) ($field['id'] ?? '');

            if ($fieldId === '') {
                continue;
            }

            if (!empty($field['auto_fill'])) {
                $mergedData[$fieldId] = $this->resolveAutoFillValue((string) $field['auto_fill'], $submission->user);
            }
        }

        $request->merge([
            'form_data' => $mergedData,
        ]);

        $validated = $request->validate($this->buildFormValidationRules($fields));

        return (array) ($validated['form_data'] ?? []);
    }

    private function stepRequiresSignature(?array $stepConfig): bool
    {
        if (!$stepConfig) {
            return false;
        }

        if (array_key_exists('requires_signature', $stepConfig)) {
            return (bool) $stepConfig['requires_signature'];
        }

        return false;
    }

    private function stepNotesRequired(?array $stepConfig): bool
    {
        return (bool) ($stepConfig['notes_required'] ?? false);
    }

    private function canReviseFormData(ApprovalStep $currentStep, User $user): bool
    {
        return $this->canActOnStep($currentStep, $user, 'approve forms')
            && (bool) ($this->stepConfigForApprovalStep($currentStep)['allow_form_edit'] ?? false);
    }

    private function resolveAutoApproverId(FormSubmission $submission, array $stepConfig): ?int
    {
        return match ($stepConfig['actor_type'] ?? null) {
            'requester' => $submission->user_id,
            'user' => (int) ($stepConfig['actor_value'] ?? 0) ?: null,
            default => null,
        };
    }

    private function defaultAutoStepNotes(array $stepConfig): string
    {
        return match ($stepConfig['action'] ?? null) {
            'submit' => 'Pengajuan dibuat oleh pemohon.',
            'complete' => 'Workflow selesai secara otomatis.',
            default => 'Langkah otomatis diproses sistem.',
        };
    }

    private function stepActorType(ApprovalStep $step): ?string
    {
        return $step->actor_type
            ?? ($step->config_snapshot['actor_type'] ?? null)
            ?? ($step->approver_role ? 'role' : null);
    }

    private function stepActorValue(ApprovalStep $step): ?string
    {
        return $step->actor_value
            ?? ($step->config_snapshot['actor_value'] ?? null)
            ?? $step->approver_role;
    }

    private function getStepActorLabel(ApprovalStep $step): string
    {
        return $step->actor_label
            ?? ($step->config_snapshot['actor_label'] ?? null)
            ?? $step->approver_role
            ?? 'System';
    }

    private function resolveApproverRoleLabel(array $stepConfig): string
    {
        return match ($stepConfig['actor_type'] ?? null) {
            'role' => (string) ($stepConfig['actor_value'] ?? 'System'),
            default => $this->resolveActorLabelFromConfig($stepConfig),
        };
    }

    private function resolveActorLabelFromConfig(array $stepConfig): string
    {
        return (string) ($stepConfig['actor_label']
            ?? $this->workflowConfigService->actorLabel($stepConfig));
    }

    private function notifySubmissionCreated(FormSubmission $submission): void
    {
        $currentStep = $this->getCurrentPendingApprovalStep($submission);

        $this->createNotification(
            $submission->user_id,
            'Pengajuan berhasil dibuat',
            "Pengajuan {$submission->form->name} sudah masuk ke sistem dan sedang menunggu proses berikutnya.",
            'form_submitted',
            "/submissions/{$submission->id}"
        );

        if ($currentStep) {
            $this->notifyStepActors(
                $currentStep,
                'approval_needed',
                'Approval baru menunggu diproses',
                "{$submission->user->name} mengajukan {$submission->form->name}. Silakan review pada langkah {$currentStep->step_name}.",
                "/submissions/{$submission->id}"
            );
        }
    }

    private function notifyWorkflowProgress(FormSubmission $submission, ApprovalStep $completedStep): void
    {
        $currentStep = $this->getCurrentPendingApprovalStep($submission);

        $this->createNotification(
            $submission->user_id,
            'Status pengajuan diperbarui',
            "Langkah {$completedStep->step_name} telah diproses. Status saat ini: {$submission->current_status}.",
            'status_changed',
            "/submissions/{$submission->id}"
        );

        if ($submission->current_status === 'completed') {
            $this->createNotification(
                $submission->user_id,
                'Pengajuan selesai',
                "Pengajuan {$submission->form->name} telah selesai diproses.",
                'status_changed',
                "/submissions/{$submission->id}"
            );

            return;
        }

        if ($currentStep) {
            $this->notifyStepActors(
                $currentStep,
                'approval_needed',
                'Ada langkah baru yang menunggu tindakan',
                "{$submission->form->name} sekarang ada di tahap {$currentStep->step_name}.",
                "/submissions/{$submission->id}"
            );
        }
    }

    private function notifySubmissionRejected(FormSubmission $submission, ApprovalStep $rejectedStep): void
    {
        $this->createNotification(
            $submission->user_id,
            'Pengajuan ditolak',
            "Pengajuan {$submission->form->name} ditolak pada langkah {$rejectedStep->step_name}.",
            'status_changed',
            "/submissions/{$submission->id}"
        );
    }

    private function notifyStepActors(ApprovalStep $step, string $type, string $title, string $message, string $link): void
    {
        foreach ($this->resolveNotificationRecipients($step) as $userId) {
            $this->createNotification($userId, $title, $message, $type, $link);
        }
    }

    private function resolveNotificationRecipients(ApprovalStep $step): array
    {
        $step->loadMissing('formSubmission');

        return match ($this->stepActorType($step)) {
            'role' => User::query()
                ->where('is_active', true)
                ->whereHas('roles', fn (Builder $query) => $query->where('name', $this->stepActorValue($step)))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
            'user' => User::query()
                ->whereKey((int) $this->stepActorValue($step))
                ->where('is_active', true)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
            'requester' => $step->formSubmission ? [(int) $step->formSubmission->user_id] : [],
            default => [],
        };
    }

    private function createNotification(int $userId, string $title, string $message, string $type, string $link): void
    {
        Notification::create([
            'user_id' => $userId,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'link' => $link,
            'is_read' => false,
        ]);
    }
}
