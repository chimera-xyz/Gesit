<?php

namespace App\Http\Controllers\API\Submissions;

use App\Http\Controllers\Controller;
use App\Models\FormSubmission;
use App\Models\Form;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class IndexController extends Controller
{
    /**
     * Get all submissions with filtering
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
                    'last_page' => $submissions->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get Submissions Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}