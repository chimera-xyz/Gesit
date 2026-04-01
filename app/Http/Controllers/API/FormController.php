<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\Workflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FormController extends Controller
{
    /**
     * Get all forms
     */
    public function index()
    {
        try {
            $forms = Form::with('workflow')->get();

            return response()->json([
                'forms' => $forms,
            ]);
        } catch (\Exception $e) {
            Log::error('Get Forms Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get single form details
     */
    public function show($id)
    {
        try {
            $form = Form::with(['workflow', 'submissions'])->findOrFail($id);

            return response()->json([
                'form' => $form,
            ]);
        } catch (\Exception $e) {
            Log::error('Get Form Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Create new form
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:forms',
                'slug' => 'required|string|max:255|unique:forms',
                'description' => 'sometimes|string|max:1000',
                'form_config' => 'required|array',
                'workflow_id' => 'required|exists:workflows,id',
            ]);

            $form = Form::create($validated);

            return response()->json([
                'success' => true,
                'form' => $form,
            ]);
        } catch (\Exception $e) {
            Log::error('Create Form Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update existing form
     */
    public function update(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'name' => 'sometimes|string|max:255|unique:forms,name,' . $id,
                'slug' => 'sometimes|string|max:255|unique:forms,slug,' . $id,
                'description' => 'sometimes|string|max:1000',
                'form_config' => 'required|array',
                'workflow_id' => 'sometimes|exists:workflows,id',
                'is_active' => 'sometimes|boolean',
            ]);

            $form = Form::findOrFail($id);
            $form->update($validated);

            return response()->json([
                'success' => true,
                'form' => $form,
            ]);
        } catch (\Exception $e) {
            Log::error('Update Form Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete form
     */
    public function destroy($id)
    {
        try {
            $form = Form::findOrFail($id);

            // Check if form has submissions
            if ($form->submissions()->count() > 0) {
                return response()->json([
                    'error' => 'Cannot delete form with existing submissions',
                ], 400);
            }

            $form->delete();

            return response()->json([
                'success' => true,
                'message' => 'Form deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Delete Form Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}