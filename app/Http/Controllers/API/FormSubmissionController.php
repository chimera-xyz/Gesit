<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\FormSubmission;
use App\Models\Form;
use App\Models\Workflow;
use App\Models\ApprovalStep;
use App\Models\Signature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class FormSubmissionController extends Controller
{
    /**
     * Get all form submissions (with filtering)
     */
    public function index(Request $request)
    {
        try {
            $query = FormSubmission::with(['form', 'user', 'approvalSteps.signature'])
                ->orderBy('created_at', 'desc');

            // Filter by status
            if ($request->has('status')) {
                $query->where('current_status', $request->status);
            }

            // Filter by form
            if ($request->has('form_id')) {
                $query->where('form_id', $request->form_id);
            }

            $submissions = $query->paginate(10);

            return response()->json([
                'submissions' => $submissions->items(),
                'pagination' => [
                    'current_page' => $submissions->currentPage(),
                    'per_page' => $submissions->perPage(),
                    'total' => $submissions->total(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get Submissions Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get single form submission details
     */
    public function show($id)
    {
        try {
            $submission = FormSubmission::with(['form', 'user', 'approvalSteps.signature'])
                ->findOrFail($id);

            // Check permission
            if (!auth()->user()->can('view submissions')) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            // Get workflow configuration
            $workflow = $submission->form->workflow;
            $workflowConfig = $workflow ? $workflow->workflow_config : null;

            return response()->json([
                'submission' => $submission,
                'workflow' => $workflowConfig,
                'available_actions' => $this->getAvailableActions($submission),
            ]);
        } catch (\Exception $e) {
            Log::error('Get Submission Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Create new form submission
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'form_id' => 'required|exists:forms,id',
                'form_data' => 'required|array',
            ]);

            $form = Form::with('workflow')->findOrFail($validated['form_id']);

            // Get workflow configuration
            $workflow = $form->workflow;
            $workflowConfig = $workflow ? $workflow->workflow_config : null;

            // Create submission
            $submission = new FormSubmission([
                'form_id' => $validated['form_id'],
                'user_id' => auth()->id(),
                'form_data' => $validated['form_data'],
                'current_status' => $this->getInitialStatus($workflowConfig),
                'current_step' => 1,
            ]);

            $submission->save();

            // Create initial approval step
            $this->createApprovalStep($submission, 1, 'Initial Submission');

            return response()->json([
                'success' => true,
                'submission' => $submission,
            ]);
        } catch (\Exception $e) {
            Log::error('Create Submission Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Approve form submission
     */
    public function approve(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'notes' => 'sometimes|string|max:1000',
                'signature_id' => 'sometimes|exists:signatures,id',
            ]);

            $submission = FormSubmission::with(['form', 'user', 'approvalSteps'])->findOrFail($id);

            // Check permission
            if (!$this->canApproveStep($submission)) {
                return response()->json(['error' => 'Unauthorized or invalid approval step'], 403);
            }

            // Get current step
            $currentStep = $submission->approvalSteps->where('status', '!=', 'completed')->orderBy('step_number')->first();

            if (!$currentStep) {
                return response()->json(['error' => 'No pending approval step found'], 400);
            }

            // Update approval step
            $currentStep->notes = $validated['notes'] ?? null;
            $currentStep->status = 'approved';
            $currentStep->approver_id = auth()->id();
            $currentStep->approved_at = now();

            if (isset($validated['signature_id'])) {
                $currentStep->signature_id = $validated['signature_id'];
            }

            $currentStep->save();

            // Move to next step
            $this->moveToNextStep($submission, $currentStep->step_number);

            return response()->json([
                'success' => true,
                'submission' => $submission->load('form', 'user', 'approvalSteps.signature'),
            ]);
        } catch (\Exception $e) {
            Log::error('Approve Submission Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Reject form submission
     */
    public function reject(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'rejection_reason' => 'required|string|max:1000',
            ]);

            $submission = FormSubmission::findOrFail($id);

            // Check permission
            if (!auth()->user()->can('reject forms')) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            // Update all pending approval steps
            $submission->approvalSteps->where('status', 'pending')->update([
                'status' => 'rejected',
                'approver_id' => auth()->id(),
                'approved_at' => now(),
            ]);

            // Update submission status
            $submission->current_status = 'rejected';
            $submission->rejection_reason = $validated['rejection_reason'];
            $submission->save();

            return response()->json([
                'success' => true,
                'submission' => $submission,
            ]);
        } catch (\Exception $e) {
            Log::error('Reject Submission Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Check if user can approve current step
     */
    private function canApproveStep($submission)
    {
        $currentStep = $submission->approvalSteps->where('status', 'pending')->orderBy('step_number')->first();

        if (!$currentStep) {
            return false;
        }

        // Check if user has the required role
        $user = auth()->user();
        $hasPermission = $user->can('approve forms');

        return $hasPermission && $user->hasRole($currentStep->approver_role);
    }

    /**
     * Move submission to next workflow step
     */
    private function moveToNextStep($submission, $currentStepNumber)
    {
        $workflow = $submission->form->workflow;
        $workflowConfig = $workflow ? $workflow->workflow_config : null;

        if ($workflowConfig && isset($workflowConfig['steps'])) {
            $steps = $workflowConfig['steps'];
            $nextStepIndex = array_search($steps, fn($step) => $step['step_number'] === $currentStepNumber + 1);

            if ($nextStepIndex !== false) {
                $nextStep = $steps[$nextStepIndex];

                // Create next approval step
                $this->createApprovalStep($submission, $nextStep['step_number'], $nextStep['name']);

                // Update submission status
                $submission->current_status = $this->getStatusForStep($nextStep['step_number'], $workflowConfig);
                $submission->current_step = $nextStep['step_number'];
                $submission->save();
            } else {
                // No more steps - mark as completed
                $submission->current_status = 'completed';
                $submission->current_step = count($steps);
                $submission->save();
            }
        }
    }

    /**
     * Create approval step
     */
    private function createApprovalStep($submission, $stepNumber, $stepName)
    {
        ApprovalStep::create([
            'form_submission_id' => $submission->id,
            'step_number' => $stepNumber,
            'step_name' => $stepName,
            'approver_role' => $this->getApproverRoleForStep($stepNumber, $submission),
            'status' => 'pending',
        ]);
    }

    /**
     * Get approver role for specific step
     */
    private function getApproverRoleForStep($stepNumber, $submission)
    {
        $workflow = $submission->form->workflow;
        $workflowConfig = $workflow ? $workflow->workflow_config : null;

        if ($workflowConfig && isset($workflowConfig['steps'][$stepNumber - 1])) {
            return $workflowConfig['steps'][$stepNumber - 1]['role'];
        }

        return 'all';
    }

    /**
     * Get initial status based on workflow
     */
    private function getInitialStatus($workflowConfig)
    {
        if ($workflowConfig && isset($workflowConfig['steps'][1])) {
            return $this->getStatusForStep(1, $workflowConfig);
        }

        return 'submitted';
    }

    /**
     * Get status for specific step
     */
    private function getStatusForStep($stepNumber, $workflowConfig)
    {
        if ($stepNumber === 1) {
            return 'submitted';
        }

        if ($workflowConfig && isset($workflowConfig['steps'][$stepNumber])) {
            $stepConfig = $workflowConfig['steps'][$stepNumber];
            return strtolower(str_replace(' ', '_', $stepConfig['action'] ?? 'pending'));
        }

        return 'pending';
    }

    /**
     * Get available actions for submission
     */
    private function getAvailableActions($submission)
    {
        $actions = [];

        $user = auth()->user();

        // Check if user can approve
        $pendingSteps = $submission->approvalSteps->where('status', 'pending');

        foreach ($pendingSteps as $step) {
            if ($user->can('approve forms') && $user->hasRole($step->approver_role)) {
                $actions[] = [
                    'action' => 'approve',
                    'step' => $step->step_number,
                    'label' => "Approve: {$step->step_name}",
                    'description' => "Review and approve {$step->step_name}",
                ];
            }
        }

        return $actions;
    }
}