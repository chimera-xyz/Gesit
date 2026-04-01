<?php

namespace App\Http\Controllers\API\Submissions;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Workflow;
use App\Models\ApprovalStep;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CreateController extends Controller
{
    /**
     * Show form list for submission
     */
    public function create()
    {
        try {
            $forms = Form::with('workflow')->get();

            if ($forms->isEmpty()) {
                return response()->json([
                    'error' => 'Tidak ada form tersedia. Silahkan hubungi admin untuk membuat form.',
                ], 404);
            }

            return response()->json([
                'forms' => $forms,
            ]);
        } catch (\Exception $e) {
            Log::error('Get Forms Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Store a new submission
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'form_id' => 'required|exists:forms,id',
                'form_data' => 'required|array',
            ]);

            $form = Form::findOrFail($validated['form_id']);
            $workflow = Workflow::findOrFail($form->workflow_id);

            // Create submission
            $submission = FormSubmission::create([
                'form_id' => $validated['form_id'],
                'user_id' => auth()->id(),
                'form_data' => $validated['form_data'],
                'current_status' => 'submitted',
                'workflow_id' => $form->workflow_id,
            ]);

            // Create approval steps based on workflow
            $workflowSteps = $workflow->workflow_config['steps'] ?? [];
            foreach ($workflowSteps as $index => $step) {
                ApprovalStep::create([
                    'submission_id' => $submission->id,
                    'step_number' => $index + 1,
                    'step_name' => $step['name'],
                    'approver_id' => $step['approver_id'] ?? null,
                    'role_required' => $step['role_required'] ?? null,
                    'status' => $index === 0 ? 'pending' : 'not_started',
                ]);
            }

            // Create notification for first approver
            if (isset($workflowSteps[0])) {
                $firstStep = $workflowSteps[0];
                Notification::create([
                    'user_id' => $firstStep['approver_id'] ?? null,
                    'title' => 'New Submission for Approval',
                    'message' => "Form '{$form->name}' requires your approval",
                    'type' => 'submission',
                    'link' => "/submissions/{$submission->id}",
                    'is_read' => false,
                ]);
            }

            return response()->json([
                'success' => true,
                'submission' => $submission,
                'message' => 'Form berhasil dikirim untuk approval',
            ]);
        } catch (\Exception $e) {
            Log::error('Create Submission Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Show create form page
     */
    public function show($id)
    {
        try {
            $form = Form::with('workflow')->findOrFail($id);

            // Check if user has permission to submit forms
            if (!auth()->user()->can('submit forms')) {
                return response()->json([
                    'error' => 'Anda tidak memiliki izin untuk submit forms',
                ], 403);
            }

            return response()->json([
                'form' => $form,
                'form_config' => $form->form_config,
            ]);
        } catch (\Exception $e) {
            Log::error('Get Create Page Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}