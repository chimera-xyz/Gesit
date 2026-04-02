<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Workflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WorkflowController extends Controller
{
    /**
     * Get all workflows
     */
    public function index()
    {
        try {
            $workflows = Workflow::with('forms')->orderBy('created_at', 'desc')->get();

            return response()->json([
                'workflows' => $workflows,
            ]);
        } catch (\Exception $e) {
            Log::error('Get Workflows Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get single workflow details
     */
    public function show($id)
    {
        try {
            $workflow = Workflow::with(['forms', 'creator'])->findOrFail($id);

            return response()->json([
                'workflow' => $workflow,
            ]);
        } catch (\Exception $e) {
            Log::error('Get Workflow Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Create new workflow
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'slug' => 'required|string|max:255|unique:workflows',
                'description' => 'sometimes|string|max:1000',
                'workflow_config' => 'required|array',
            ]);

            // Validate workflow configuration structure
            $this->validateWorkflowConfig($validated['workflow_config']);

            $validated['created_by'] = auth()->id();

            $workflow = Workflow::create($validated);

            return response()->json([
                'success' => true,
                'workflow' => $workflow,
            ]);
        } catch (\Exception $e) {
            Log::error('Create Workflow Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update existing workflow
     */
    public function update(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'name' => 'sometimes|string|max:255|unique:workflows,name,' . $id,
                'slug' => 'sometimes|string|max:255|unique:workflows,slug,' . $id,
                'description' => 'sometimes|string|max:1000',
                'workflow_config' => 'sometimes|array',
                'is_active' => 'sometimes|boolean',
            ]);

            $workflow = Workflow::findOrFail($id);

            // Validate workflow configuration structure
            if (isset($validated['workflow_config'])) {
                $this->validateWorkflowConfig($validated['workflow_config']);
            }

            $workflow->update($validated);

            return response()->json([
                'success' => true,
                'workflow' => $workflow,
            ]);
        } catch (\Exception $e) {
            Log::error('Update Workflow Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete workflow
     */
    public function destroy($id)
    {
        try {
            $workflow = Workflow::findOrFail($id);

            // Check if workflow has forms
            if ($workflow->forms()->count() > 0) {
                return response()->json([
                    'error' => 'Cannot delete workflow with existing forms',
                ], 400);
            }

            $workflow->delete();

            return response()->json([
                'success' => true,
                'message' => 'Workflow deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Delete Workflow Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Validate workflow configuration structure
     */
    private function validateWorkflowConfig($workflowConfig)
    {
        $requiredKeys = ['steps', 'statuses'];

        foreach ($requiredKeys as $key) {
            if (!isset($workflowConfig[$key]) || !is_array($workflowConfig[$key])) {
                throw new \Exception("Invalid workflow configuration: {$key} must be an array");
            }

            if ($key === 'steps') {
                $this->validateWorkflowSteps($workflowConfig['steps']);
            }

            if ($key === 'statuses') {
                $this->validateWorkflowStatuses($workflowConfig['statuses']);
            }
        }
    }

    /**
     * Validate workflow steps structure
     */
    private function validateWorkflowSteps($steps)
    {
        $requiredStepKeys = ['step_number', 'name', 'role', 'action', 'status'];

        foreach ($steps as $index => $step) {
            foreach ($requiredStepKeys as $key) {
                if (!isset($step[$key])) {
                    throw new \Exception("Step {$index}: Missing required field: {$key}");
                }
            }

            if (!is_numeric($step['step_number'])) {
                throw new \Exception("Step {$index}: step_number must be numeric");
            }

            if (!is_string($step['action']) || trim($step['action']) === '') {
                throw new \Exception("Step {$index}: action must be a non-empty string");
            }

            if (!is_string($step['status']) || trim($step['status']) === '') {
                throw new \Exception("Step {$index}: status must be a non-empty string");
            }
        }
    }

    /**
     * Validate workflow statuses structure
     */
    private function validateWorkflowStatuses($statuses)
    {
        foreach ($statuses as $index => $status) {
            if (!is_string($status) || trim($status) === '') {
                throw new \Exception("Status {$index}: status must be a non-empty string");
            }
        }
    }
}
