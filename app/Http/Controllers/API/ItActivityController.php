<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Support\ItActivityExcelExporter;
use App\Support\ItActivityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ItActivityController extends Controller
{
    public function __construct(
        private readonly ItActivityService $itActivityService,
        private readonly ItActivityExcelExporter $itActivityExcelExporter,
    ) {}

    public function index(Request $request)
    {
        try {
            $validated = $request->validate([
                'search' => ['sometimes', 'nullable', 'string', 'max:255'],
                'module' => ['sometimes', 'nullable', 'string', 'in:all,helpdesk,submission'],
                'date_from' => ['sometimes', 'nullable', 'date'],
                'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
                'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'nullable', 'integer', 'min:10', 'max:100'],
            ]);

            return response()->json(
                $this->itActivityService->paginate(
                    $validated,
                    (int) ($validated['page'] ?? 1),
                    (int) ($validated['per_page'] ?? 25),
                )
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('List IT Activities Error: '.$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function export(Request $request)
    {
        try {
            $validated = $request->validate([
                'search' => ['sometimes', 'nullable', 'string', 'max:255'],
                'module' => ['sometimes', 'nullable', 'string', 'in:all,helpdesk,submission'],
                'date_from' => ['sometimes', 'nullable', 'date'],
                'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            ]);

            $payload = $this->itActivityService->exportPayload($validated);
            $filename = 'it-activities-'.now()->format('Ymd_His').'.xlsx';
            $binary = $this->itActivityExcelExporter->generate($payload);

            return response($binary, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
                'Pragma' => 'public',
                'Expires' => '0',
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Export IT Activities Error: '.$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
