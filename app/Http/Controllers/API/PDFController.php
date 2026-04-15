<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\FormSubmission;
use App\Support\SubmissionPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PDFController extends Controller
{
    public function __construct(
        private readonly SubmissionPdfService $pdfService,
    ) {
    }

    /**
     * Generate or regenerate PDF for a submission.
     */
    public function generate(Request $request, $id)
    {
        try {
            $submission = FormSubmission::with([
                'form.workflow',
                'user.roles',
                'approvalSteps.approver.roles',
                'approvalSteps.signature.user',
            ])->findOrFail($id);

            if (!$this->canView($request, $submission)) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $pdf = $this->pdfService->generate($submission);

            return response()->json([
                'success' => true,
                'pdf_path' => $pdf['relative_path'],
                'url' => $pdf['preview_url'],
                'download_url' => $pdf['download_url'],
            ]);
        } catch (\Exception $e) {
            Log::error('PDF Generation Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Return preview URL for the generated PDF. Generates on demand if missing.
     */
    public function preview(Request $request, $id)
    {
        try {
            $submission = FormSubmission::with([
                'form.workflow',
                'user.roles',
                'approvalSteps.approver.roles',
                'approvalSteps.signature.user',
            ])->findOrFail($id);

            if (!$this->canView($request, $submission)) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            if (!$this->pdfService->exists($submission)) {
                $this->pdfService->generate($submission);
            }

            return response()->json([
                'success' => true,
                'url' => $this->pdfService->previewUrl($submission),
                'download_url' => $this->pdfService->downloadUrl($submission),
            ]);
        } catch (\Exception $e) {
            Log::error('PDF Preview Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Stream PDF inline for iframe/browser preview.
     */
    public function stream(Request $request, $id)
    {
        try {
            $submission = FormSubmission::with([
                'form.workflow',
                'user.roles',
                'approvalSteps.approver.roles',
                'approvalSteps.signature.user',
            ])->findOrFail($id);

            if (!$this->canView($request, $submission)) {
                abort(403);
            }

            if (!$this->pdfService->exists($submission)) {
                $this->pdfService->generate($submission);
            }

            $path = $this->pdfService->absolutePath($submission);

            if (!$path || !file_exists($path)) {
                abort(404);
            }

            return response()->file($path, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
            ]);
        } catch (\Exception $e) {
            Log::error('PDF Stream Error: ' . $e->getMessage());
            abort(500);
        }
    }

    /**
     * Download generated PDF.
     */
    public function download(Request $request, $id)
    {
        try {
            $submission = FormSubmission::with([
                'form.workflow',
                'user.roles',
                'approvalSteps.approver.roles',
                'approvalSteps.signature.user',
            ])->findOrFail($id);

            if (!$this->canView($request, $submission)) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            if (!$this->pdfService->exists($submission)) {
                $this->pdfService->generate($submission);
            }

            $path = $this->pdfService->absolutePath($submission);

            if (!$path || !file_exists($path)) {
                return response()->json(['error' => 'PDF not found'], 404);
            }

            return response()->download($path, basename($path));
        } catch (\Exception $e) {
            Log::error('PDF Download Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function canView(Request $request, FormSubmission $submission): bool
    {
        $user = $request->user();

        if (!$user || !$user->can('view submissions')) {
            return false;
        }

        if ($user->hasRole('Admin')) {
            return true;
        }

        if ((int) $submission->user_id === (int) $user->id) {
            return true;
        }

        $roles = $user->roles->pluck('name')->all();
        $submission->loadMissing('approvalSteps');

        return $submission->approvalSteps->contains(function ($step) use ($roles, $user) {
            if ((int) $step->approver_id === (int) $user->id) {
                return true;
            }

            if (($step->actor_type ?? null) === 'user') {
                return (int) ($step->actor_value ?? 0) === (int) $user->id;
            }

            if (($step->actor_type ?? null) === 'role') {
                return in_array($step->actor_value, $roles, true);
            }

            return in_array($step->approver_role, $roles, true);
        });
    }
}
