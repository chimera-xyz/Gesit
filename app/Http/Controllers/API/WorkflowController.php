<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Workflow;
use App\Support\WorkflowConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WorkflowController extends Controller
{
    public function __construct(
        private readonly WorkflowConfigService $workflowConfigService,
    ) {
    }

    /**
     * Get all workflows
     */
    public function index()
    {
        try {
            $workflows = Workflow::with('forms')
                ->orderByDesc('updated_at')
                ->get()
                ->map(fn (Workflow $workflow) => $this->transformWorkflow($workflow))
                ->values();

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
                'workflow' => $this->transformWorkflow($workflow),
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
                'slug' => 'nullable|string|max:255|unique:workflows',
                'description' => 'sometimes|string|max:1000',
                'workflow_config' => 'required|array',
                'is_active' => 'sometimes|boolean',
            ]);

            $validated['slug'] = $this->generateUniqueSlug($request->string('slug')->value() ?: $validated['name']);
            $validated['workflow_config'] = $this->workflowConfigService->normalizeForStorage($validated['workflow_config']);

            $validated['created_by'] = auth()->id();

            $workflow = Workflow::create($validated);

            return response()->json([
                'success' => true,
                'workflow' => $this->transformWorkflow($workflow->fresh(['forms', 'creator'])),
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

            if (isset($validated['workflow_config'])) {
                $validated['workflow_config'] = $this->workflowConfigService->normalizeForStorage($validated['workflow_config']);
            }

            if ($request->filled('slug') || array_key_exists('name', $validated)) {
                $validated['slug'] = $this->generateUniqueSlug(
                    $request->string('slug')->value() ?: ($validated['name'] ?? $workflow->name),
                    $workflow->id
                );
            }

            $workflow->update($validated);

            return response()->json([
                'success' => true,
                'workflow' => $this->transformWorkflow($workflow->fresh(['forms', 'creator'])),
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
                    'error' => 'Workflow yang masih dipakai form tidak bisa dihapus.',
                ], 400);
            }

            $workflow->delete();

            return response()->json([
                'success' => true,
                'message' => 'Workflow berhasil dihapus.',
            ]);
        } catch (\Exception $e) {
            Log::error('Delete Workflow Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function transformWorkflow(Workflow $workflow): array
    {
        $workflow->loadMissing(['forms', 'creator']);
        $steps = $workflow->workflow_config['steps'] ?? [];

        return [
            'id' => $workflow->id,
            'name' => $workflow->name,
            'slug' => $workflow->slug,
            'description' => $workflow->description,
            'is_active' => (bool) $workflow->is_active,
            'workflow_config' => $workflow->workflow_config,
            'steps_count' => count($steps),
            'forms_count' => $workflow->forms->count(),
            'created_by' => $workflow->created_by,
            'creator_name' => $workflow->creator?->name,
            'created_at' => optional($workflow->created_at)?->toISOString(),
            'updated_at' => optional($workflow->updated_at)?->toISOString(),
        ];
    }

    private function generateUniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($source);
        $slugBase = $baseSlug !== '' ? $baseSlug : 'workflow';
        $slug = $slugBase;
        $counter = 2;

        while (
            Workflow::query()
                ->where('slug', $slug)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = "{$slugBase}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
