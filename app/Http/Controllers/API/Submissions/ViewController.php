<?php

namespace App\Http\Controllers\API\Submissions;

use App\Http\Controllers\Controller;
use App\Models\FormSubmission;
use App\Models\Form;
use App\Models\Workflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ViewController extends Controller
{
    /**
     * Show submission details
     */
    public function view($id)
    {
        try {
            $submission = FormSubmission::with([
                'form',
                'user',
                'approvalSteps.signature',
                'form.workflow',
            ])->findOrFail($id);

            // Check permission
            if (!auth()->user()->can('view submissions')) {
                return response()->json([
                    'error' => 'Anda tidak memiliki izin',
                ], 403);
            }

            return response()->json($submission->load(['form', 'user', 'approvalSteps.signature', 'form.workflow']));
        } catch (\Exception $e) {
            Log::error('View Submission Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}