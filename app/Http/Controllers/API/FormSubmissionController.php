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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FormSubmissionController extends Controller
{
    public function __construct(
        private readonly SubmissionPdfService $pdfService,
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
                'workflow' => $submission->form?->workflow?->workflow_config,
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

            if (!$form->is_active) {
                return response()->json([
                    'error' => 'Form tidak aktif dan belum bisa digunakan.',
                ], 422);
            }

            $submission = DB::transaction(function () use ($request, $form) {
                $submission = FormSubmission::create([
                    'form_id' => $form->id,
                    'user_id' => $request->user()->id,
                    'form_data' => [],
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
                'notes' => $validated['notes'] ?? null,
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

            $currentStep->fill([
                'notes' => $validated['rejection_reason'],
                'status' => 'rejected',
                'approver_id' => auth()->id(),
                'approved_at' => now(),
            ])->save();

            $submission->update([
                'current_status' => 'rejected',
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

        $data = $submission->toArray();
        $data['available_actions'] = $this->getAvailableActions($submission);
        $data['current_pending_step'] = $this->getCurrentPendingApprovalStep($submission)?->toArray();
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

        $firstStep = $steps[0];

        if (($firstStep['action'] ?? null) === 'submit') {
            $this->storeApprovalStepFromConfig($submission, $firstStep, [
                'status' => 'approved',
                'approver_id' => $submission->user_id,
                'approved_at' => $submission->created_at,
                'notes' => 'Pengajuan dibuat oleh pemohon.',
            ]);
        }

        $nextStep = $this->getNextActionableStep($submission, $firstStep['step_number'] ?? 0);

        if ($nextStep) {
            $this->storeApprovalStepFromConfig($submission, $nextStep, [
                'status' => 'pending',
            ]);

            $submission->update([
                'current_status' => $this->getStatusForStep($nextStep),
                'current_step' => $nextStep['step_number'],
            ]);

            return;
        }

        $submission->update([
            'current_status' => 'completed',
            'current_step' => $firstStep['step_number'] ?? 1,
        ]);
    }

    private function advanceWorkflow(FormSubmission $submission, ApprovalStep $currentStep): void
    {
        $nextStep = $this->getNextActionableStep($submission, $currentStep->step_number);

        if ($nextStep) {
            $this->storeApprovalStepFromConfig($submission, $nextStep, [
                'status' => 'pending',
            ]);

            $submission->update([
                'current_status' => $this->getStatusForStep($nextStep),
                'current_step' => $nextStep['step_number'],
            ]);

            return;
        }

        $completionStep = $this->getNextWorkflowStep($submission, $currentStep->step_number);

        if ($completionStep && ($completionStep['action'] ?? null) === 'complete') {
            $this->storeApprovalStepFromConfig($submission, $completionStep, [
                'status' => 'approved',
                'approved_at' => now(),
                'notes' => 'Workflow selesai secara otomatis.',
            ]);

            $submission->update([
                'current_status' => $this->getStatusForStep($completionStep, 'completed'),
                'current_step' => $completionStep['step_number'],
            ]);

            return;
        }

        $submission->update([
            'current_status' => 'completed',
            'current_step' => $currentStep->step_number,
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
                'step_name' => $stepConfig['name'] ?? 'Workflow Step',
                'approver_role' => $stepConfig['role'] ?? 'System',
                'status' => 'pending',
                'notes' => null,
                'signature_id' => null,
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

    private function getWorkflowSteps(FormSubmission $submission): array
    {
        $steps = $submission->form?->workflow?->workflow_config['steps'] ?? [];

        usort($steps, fn (array $left, array $right) => ($left['step_number'] ?? 0) <=> ($right['step_number'] ?? 0));

        return $steps;
    }

    private function getNextActionableStep(FormSubmission $submission, int $afterStepNumber): ?array
    {
        $steps = $this->getWorkflowSteps($submission);

        foreach ($steps as $step) {
            if (($step['step_number'] ?? 0) <= $afterStepNumber) {
                continue;
            }

            if (!$this->isAutoStep($step)) {
                return $step;
            }
        }

        return null;
    }

    private function getNextWorkflowStep(FormSubmission $submission, int $afterStepNumber): ?array
    {
        foreach ($this->getWorkflowSteps($submission) as $step) {
            if (($step['step_number'] ?? 0) > $afterStepNumber) {
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

    private function getStatusForStep(array $step, string $fallback = 'pending'): string
    {
        return (string) ($step['status'] ?? $fallback);
    }

    private function getAvailableActions(FormSubmission $submission): array
    {
        $currentStep = $this->getCurrentPendingApprovalStep($submission);

        if (!$currentStep || !$this->canActOnStep($currentStep, auth()->user(), 'approve forms')) {
            return [];
        }

        $stepConfig = $this->getWorkflowStepConfig($submission, $currentStep->step_number);

        return [[
            'action' => $stepConfig['action'] ?? 'approve',
            'step_number' => $currentStep->step_number,
            'step_name' => $currentStep->step_name,
            'label' => $stepConfig['cta_label'] ?? 'Setujui',
            'notes_placeholder' => $stepConfig['notes_placeholder'] ?? 'Tambahkan catatan untuk langkah ini.',
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

    private function canActOnStep(ApprovalStep $step, User $user, string $permission): bool
    {
        if (!$user->can($permission)) {
            return false;
        }

        if ($user->hasRole('Admin')) {
            return true;
        }

        return $user->hasRole($step->approver_role);
    }

    private function userCanViewSubmission(FormSubmission $submission, User $user): bool
    {
        if ($user->hasRole('Admin')) {
            return true;
        }

        if ((int) $submission->user_id === (int) $user->id) {
            return true;
        }

        $roles = $user->roles->pluck('name')->all();

        if ($roles === []) {
            return false;
        }

        return $submission->approvalSteps()
            ->where(function (Builder $query) use ($roles, $user) {
                $query->whereIn('approver_role', $roles)
                    ->orWhere('approver_id', $user->id);
            })
            ->exists();
    }

    private function scopeVisibleSubmissions(Builder $query, User $user): void
    {
        if ($user->hasRole('Admin')) {
            return;
        }

        $roles = $user->roles->pluck('name')->all();

        $query->where(function (Builder $submissionQuery) use ($user, $roles) {
            $submissionQuery->where('user_id', $user->id);

            if ($roles !== []) {
                $submissionQuery->orWhereHas('approvalSteps', function (Builder $approvalQuery) use ($roles, $user) {
                    $approvalQuery->whereIn('approver_role', $roles)
                        ->orWhere('approver_id', $user->id);
                });
            }
        });
    }

    private function getFormFields(Form $form): array
    {
        return $form->form_config['fields'] ?? [];
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
        $fields = $this->getFormFields($submission->form);
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

        return in_array($stepConfig['action'] ?? null, ['review', 'approve', 'mark_paid'], true);
    }

    private function canReviseFormData(ApprovalStep $currentStep, User $user): bool
    {
        return $currentStep->approver_role === 'IT Staff'
            && ($user->hasRole('Admin') || $user->hasRole('IT Staff'));
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
            $this->notifyRoleUsers(
                [$currentStep->approver_role],
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
            $this->notifyRoleUsers(
                [$currentStep->approver_role],
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

    private function notifyRoleUsers(array $roles, string $type, string $title, string $message, string $link): void
    {
        User::query()
            ->whereHas('roles', fn (Builder $query) => $query->whereIn('name', $roles))
            ->get()
            ->each(fn (User $user) => $this->createNotification($user->id, $title, $message, $type, $link));
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
