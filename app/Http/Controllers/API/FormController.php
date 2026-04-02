<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Form;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class FormController extends Controller
{
    /**
     * Get all forms
     */
    public function index()
    {
        try {
            $query = Form::with('workflow')->orderBy('name');

            if (!auth()->user()?->can('create forms')) {
                $query->where('is_active', true);
            }

            $forms = $query->get();

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
                'description' => 'sometimes|string|max:1000',
                'form_config' => 'required|array',
                'workflow_id' => 'nullable|exists:workflows,id',
                'is_active' => 'sometimes|boolean',
            ]);

            $validated['slug'] = $this->generateUniqueSlug($request->string('slug')->value() ?: $validated['name']);
            $validated['created_by'] = auth()->id();
            $form = Form::create($validated);

            return response()->json([
                'success' => true,
                'form' => $form->load('workflow'),
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
                'description' => 'sometimes|string|max:1000',
                'form_config' => 'sometimes|array',
                'workflow_id' => 'nullable|exists:workflows,id',
                'is_active' => 'sometimes|boolean',
            ]);

            $form = Form::findOrFail($id);

            if ($request->filled('slug') || array_key_exists('name', $validated)) {
                $validated['slug'] = $this->generateUniqueSlug(
                    $request->string('slug')->value() ?: ($validated['name'] ?? $form->name),
                    $form->id
                );
            }

            $form->update($validated);

            return response()->json([
                'success' => true,
                'form' => $form->load('workflow'),
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

    private function generateUniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($source);
        $slugBase = $baseSlug !== '' ? $baseSlug : 'form';
        $slug = $slugBase;
        $counter = 2;

        while (
            Form::query()
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
