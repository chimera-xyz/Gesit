<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\FormSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PDF;

class PDFController extends Controller
{
    /**
     * Generate PDF for form submission
     */
    public function generate(Request $request, $id)
    {
        try {
            $submission = FormSubmission::with(['form', 'user', 'approvalSteps.signature'])->findOrFail($id);

            // Validate that user has permission
            if (!auth()->user()->can('view submissions')) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $pdf = $this->generateProfessionalPDF($submission);

            $submission->pdf_path = $pdf['relative_path'];
            $submission->save();

            return response()->json([
                'success' => true,
                'pdf_path' => $pdf['relative_path'],
                'url' => url($pdf['public_path']),
            ]);
        } catch (\Exception $e) {
            Log::error('PDF Generation Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Generate professional PDF matching company template
     */
    private function generateProfessionalPDF($submission)
    {
        $pdf = PDF::loadView('pdf.template', [
            'submission' => $submission,
            'company' => [
                'name' => 'Yuli Sekuritas Indonesia',
                'logo' => public_path('images/company-logo.png'),
                'address' => 'Jakarta, Indonesia',
            ],
        ])->setPaper('a4', 'portrait');

        $filename = "GESIT_{$submission->id}_" . now()->format('Y-m-d_His') . '.pdf';
        $directory = storage_path('app/public/pdfs');
        $absolutePath = "{$directory}/{$filename}";

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $pdf->save($absolutePath);

        return [
            'filename' => $filename,
            'absolute_path' => $absolutePath,
            'relative_path' => "pdfs/{$filename}",
            'public_path' => "storage/pdfs/{$filename}",
        ];
    }

    /**
     * Get PDF preview URL
     */
    public function preview(Request $request, $id)
    {
        try {
            $submission = FormSubmission::findOrFail($id);

            if (!auth()->user()->can('view submissions')) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $path = storage_path("app/public/{$submission->pdf_path}");

            if (!$submission->pdf_path || !file_exists($path)) {
                return response()->json(['error' => 'PDF not found'], 404);
            }

            return response()->json([
                'success' => true,
                'url' => url("storage/{$submission->pdf_path}"),
            ]);
        } catch (\Exception $e) {
            Log::error('PDF Preview Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Download PDF
     */
    public function download(Request $request, $id)
    {
        try {
            $submission = FormSubmission::findOrFail($id);

            if (!auth()->user()->can('view submissions')) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $path = storage_path("app/public/{$submission->pdf_path}");

            if (!$submission->pdf_path || !file_exists($path)) {
                return response()->json(['error' => 'PDF not found'], 404);
            }

            $filename = "GESIT_{$submission->id}_" . now()->format('Y-m-d_His') . '.pdf';

            return response()->download($path, $filename);
        } catch (\Exception $e) {
            Log::error('PDF Download Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
