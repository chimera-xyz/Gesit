<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeSection;
use App\Models\KnowledgeSpace;
use App\Support\KnowledgeAttachmentService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class AdminKnowledgeController extends Controller
{
    public function index()
    {
        try {
            $general = $this->loadSpaceWithRelations($this->ensureGeneralSpace());
            $divisions = KnowledgeSpace::query()
                ->where('kind', 'division')
                ->with(['sections.entries.roles'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

            return response()->json([
                'general' => $this->transformAdminSpace($general),
                'divisions' => $divisions
                    ->map(fn (KnowledgeSpace $space) => $this->transformAdminSpace($space))
                    ->values(),
                'roles' => Role::query()
                    ->where('is_active', true)
                    ->orderByRaw("CASE WHEN name = 'Admin' THEN 0 ELSE 1 END")
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->name])
                    ->values(),
                'catalogs' => [
                    'types' => [
                        ['value' => 'sop', 'label' => 'SOP'],
                        ['value' => 'troubleshooting', 'label' => 'Troubleshooting'],
                        ['value' => 'onboarding', 'label' => 'Onboarding'],
                        ['value' => 'form', 'label' => 'Form'],
                        ['value' => 'policy', 'label' => 'Kebijakan'],
                        ['value' => 'jobdesk', 'label' => 'Jobdesk'],
                        ['value' => 'faq', 'label' => 'FAQ'],
                    ],
                    'scopes' => [
                        ['value' => 'internal', 'label' => 'Internal'],
                        ['value' => 'securities_domain', 'label' => 'Domain Sekuritas'],
                    ],
                    'source_kinds' => [
                        ['value' => 'article', 'label' => 'Teks internal'],
                        ['value' => 'file', 'label' => 'Dokumen upload'],
                        ['value' => 'hybrid', 'label' => 'Dokumen + catatan'],
                    ],
                    'access_modes' => [
                        ['value' => 'all', 'label' => 'Semua pengguna Knowledge Hub'],
                        ['value' => 'role_based', 'label' => 'Batasi ke role tertentu'],
                    ],
                    'ai_providers' => [
                        ['value' => 'zai', 'label' => 'GLM / Z.ai'],
                        ['value' => 'local', 'label' => 'AI Local'],
                    ],
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::error('Admin Knowledge Index Error: '.$exception->getMessage());

            return response()->json([
                'error' => 'Data Knowledge AI gagal dimuat.',
            ], 500);
        }
    }

    public function updateGeneral(Request $request)
    {
        try {
            $general = $this->ensureGeneralSpace();
            $validated = $request->validate($this->generalRules());
            $payload = $this->normalizeGeneralPayload($request, $validated);

            $general->fill([
                ...$payload,
                'kind' => 'general',
                'show_in_hub' => false,
                'icon' => $payload['icon'] ?? $general->icon ?? 'sparkles',
            ]);
            $general->updated_by = $request->user()->id;
            $general->save();

            return response()->json([
                'success' => true,
                'general' => $this->transformAdminSpace($this->loadSpaceWithRelations($general)),
            ]);
        } catch (ValidationException|HttpResponseException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Update General Knowledge Error: '.$exception->getMessage());

            return response()->json([
                'error' => 'General knowledge gagal diperbarui.',
            ], 500);
        }
    }

    public function storeSpace(Request $request)
    {
        try {
            $validated = $request->validate($this->divisionRules());

            $space = KnowledgeSpace::query()->create([
                ...$validated,
                'kind' => 'division',
                'show_in_hub' => true,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            $space->ensureDefaultSection();

            return response()->json([
                'success' => true,
                'division' => $this->transformAdminSpace($this->loadSpaceWithRelations($space)),
            ], 201);
        } catch (ValidationException|HttpResponseException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Store Knowledge Division Error: '.$exception->getMessage());

            return response()->json([
                'error' => 'Divisi knowledge gagal dibuat.',
            ], 500);
        }
    }

    public function updateSpace(Request $request, int $id)
    {
        try {
            $space = KnowledgeSpace::query()->findOrFail($id);

            if ($space->kind !== 'division') {
                return response()->json([
                    'error' => 'General knowledge dikelola dari menu khusus.',
                ], 422);
            }

            $validated = $request->validate($this->divisionRules(true));

            $space->fill([
                ...$validated,
                'kind' => 'division',
                'show_in_hub' => true,
            ]);
            $space->updated_by = $request->user()->id;
            $space->save();
            $space->ensureDefaultSection();

            return response()->json([
                'success' => true,
                'division' => $this->transformAdminSpace($this->loadSpaceWithRelations($space)),
            ]);
        } catch (ValidationException|HttpResponseException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Update Knowledge Division Error: '.$exception->getMessage());

            return response()->json([
                'error' => 'Divisi knowledge gagal diperbarui.',
            ], 500);
        }
    }

    public function destroySpace(int $id)
    {
        try {
            $space = KnowledgeSpace::query()->with('sections.entries')->findOrFail($id);

            if ($space->kind !== 'division') {
                return response()->json([
                    'error' => 'General knowledge tidak dapat dihapus.',
                ], 422);
            }

            $this->deleteAttachmentsForEntries($space->sections->flatMap->entries);
            $space->delete();

            return response()->json([
                'success' => true,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Delete Knowledge Division Error: '.$exception->getMessage());

            return response()->json([
                'error' => 'Divisi knowledge gagal dihapus.',
            ], 500);
        }
    }

    public function storeSection(Request $request)
    {
        try {
            $validated = $request->validate($this->sectionRules());
            $section = KnowledgeSection::query()->create([
                ...$validated,
                'is_default' => false,
            ]);

            return response()->json([
                'success' => true,
                'section' => $this->transformSection($section->load('entries.roles')),
            ], 201);
        } catch (ValidationException|HttpResponseException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Store Knowledge Section Error: '.$exception->getMessage());

            return response()->json([
                'error' => 'Section internal gagal dibuat.',
            ], 500);
        }
    }

    public function updateSection(Request $request, int $id)
    {
        try {
            $section = KnowledgeSection::query()->findOrFail($id);
            $validated = $request->validate($this->sectionRules(true));

            $section->fill($validated);
            $section->save();

            return response()->json([
                'success' => true,
                'section' => $this->transformSection($section->load('entries.roles')),
            ]);
        } catch (ValidationException|HttpResponseException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Update Knowledge Section Error: '.$exception->getMessage());

            return response()->json([
                'error' => 'Section internal gagal diperbarui.',
            ], 500);
        }
    }

    public function destroySection(int $id)
    {
        try {
            $section = KnowledgeSection::query()->with('entries')->findOrFail($id);

             if ($section->is_default) {
                return response()->json([
                    'error' => 'Folder root internal tidak dapat dihapus.',
                ], 422);
            }

            $this->deleteAttachmentsForEntries($section->entries);
            $section->delete();

            return response()->json([
                'success' => true,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Delete Knowledge Section Error: '.$exception->getMessage());

            return response()->json([
                'error' => 'Section internal gagal dihapus.',
            ], 500);
        }
    }

    public function storeEntry(Request $request, KnowledgeAttachmentService $attachmentService)
    {
        try {
            $validated = $request->validate($this->entryRules());

            $entry = DB::transaction(function () use ($request, $validated, $attachmentService) {
                $entry = KnowledgeEntry::query()->create([
                    ...$this->normalizeEntryPayload($validated),
                    ...$this->resolveAttachmentPayload($request, $attachmentService),
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);

                $entry->roles()->sync($this->resolveRoleIds($validated));

                return $entry->load(['section.space', 'roles']);
            });

            return response()->json([
                'success' => true,
                'document' => $this->transformEntry($entry),
            ], 201);
        } catch (ValidationException|HttpResponseException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Store Knowledge Document Error: '.$exception->getMessage());

            return response()->json([
                'error' => 'Dokumen knowledge gagal dibuat.',
            ], 500);
        }
    }

    public function updateEntry(Request $request, int $id, KnowledgeAttachmentService $attachmentService)
    {
        try {
            $entry = KnowledgeEntry::query()->with(['roles', 'section.space'])->findOrFail($id);
            $validated = $request->validate($this->entryRules(true));

            DB::transaction(function () use ($request, $entry, $validated, $attachmentService) {
                if ($request->boolean('remove_attachment') && $entry->attachment_path) {
                    $entry->fill($attachmentService->clear($entry->attachment_path));
                }

                $attachmentPayload = $this->resolveAttachmentPayload($request, $attachmentService, $entry);

                $entry->fill([
                    ...$this->normalizeEntryPayload($validated, $entry),
                    ...$attachmentPayload,
                    'updated_by' => $request->user()->id,
                ]);
                $entry->save();
                $entry->roles()->sync($this->resolveRoleIds($validated));
            });

            return response()->json([
                'success' => true,
                'document' => $this->transformEntry($entry->fresh(['section.space', 'roles'])),
            ]);
        } catch (ValidationException|HttpResponseException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Update Knowledge Document Error: '.$exception->getMessage());

            return response()->json([
                'error' => 'Dokumen knowledge gagal diperbarui.',
            ], 500);
        }
    }

    public function destroyEntry(int $id)
    {
        try {
            $entry = KnowledgeEntry::query()->findOrFail($id);
            $entry->delete();

            return response()->json([
                'success' => true,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Delete Knowledge Document Error: '.$exception->getMessage());

            return response()->json([
                'error' => 'Dokumen knowledge gagal dihapus.',
            ], 500);
        }
    }

    private function generalRules(): array
    {
        return [
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ai_instruction' => ['sometimes', 'nullable', 'string'],
            'knowledge_text' => ['sometimes', 'nullable', 'string'],
            'ai_provider' => ['sometimes', Rule::in(['zai', 'local'])],
            'ai_local_base_url' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ai_local_api_key' => ['sometimes', 'nullable', 'string'],
            'ai_local_model' => ['sometimes', 'nullable', 'string', 'max:120'],
            'ai_local_timeout' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:300'],
            'clear_ai_local_api_key' => ['sometimes', 'boolean'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function normalizeGeneralPayload(Request $request, array $validated): array
    {
        $payload = $validated;

        foreach ([
            'description',
            'ai_instruction',
            'knowledge_text',
            'ai_local_base_url',
            'ai_local_model',
            'icon',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = $this->nullableTrim($payload[$field]);
            }
        }

        if ($request->boolean('clear_ai_local_api_key')) {
            $payload['ai_local_api_key'] = null;
        } elseif (array_key_exists('ai_local_api_key', $validated)) {
            $normalizedApiKey = $this->nullableTrim($validated['ai_local_api_key']);

            if ($normalizedApiKey === null) {
                unset($payload['ai_local_api_key']);
            } else {
                $payload['ai_local_api_key'] = $normalizedApiKey;
            }
        }

        unset($payload['clear_ai_local_api_key']);

        return $payload;
    }

    private function divisionRules(bool $isUpdate = false): array
    {
        return [
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ai_instruction' => ['sometimes', 'nullable', 'string'],
            'knowledge_text' => ['sometimes', 'nullable', 'string'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:40'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function sectionRules(bool $isUpdate = false): array
    {
        return [
            'knowledge_space_id' => [$isUpdate ? 'sometimes' : 'required', Rule::exists('knowledge_spaces', 'id')],
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function entryRules(bool $isUpdate = false): array
    {
        return [
            'knowledge_space_id' => ['sometimes', Rule::exists('knowledge_spaces', 'id')],
            'knowledge_section_id' => ['sometimes', Rule::exists('knowledge_sections', 'id')],
            'title' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:180'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:500'],
            'body' => ['sometimes', 'nullable', 'string'],
            'scope' => [$isUpdate ? 'sometimes' : 'required', Rule::in(['internal', 'securities_domain'])],
            'type' => [$isUpdate ? 'sometimes' : 'required', Rule::in(['sop', 'troubleshooting', 'onboarding', 'form', 'policy', 'jobdesk', 'faq'])],
            'source_kind' => [$isUpdate ? 'sometimes' : 'required', Rule::in(['article', 'file', 'hybrid'])],
            'owner_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'reviewer_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'version_label' => ['sometimes', 'nullable', 'string', 'max:60'],
            'effective_date' => ['sometimes', 'nullable', 'date'],
            'reference_notes' => ['sometimes', 'nullable', 'string', 'max:120'],
            'source_link' => ['sometimes', 'nullable', 'url', 'max:255'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:40'],
            'access_mode' => [$isUpdate ? 'sometimes' : 'required', Rule::in(['all', 'role_based'])],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'attachment' => ['sometimes', 'nullable', 'file', 'max:20480', 'mimes:pdf,doc,docx,png,jpg,jpeg,webp,txt'],
            'remove_attachment' => ['sometimes', 'boolean'],
        ];
    }

    private function normalizeEntryPayload(array $validated, ?KnowledgeEntry $entry = null): array
    {
        $payload = [
            'knowledge_section_id' => $this->resolveKnowledgeSectionId($validated, $entry),
            'title' => trim((string) ($validated['title'] ?? $entry?->title)),
            'summary' => $this->nullableTrim($validated['summary'] ?? $entry?->summary),
            'body' => $this->nullableTrim($validated['body'] ?? $entry?->body),
            'scope' => (string) ($validated['scope'] ?? $entry?->scope ?? 'internal'),
            'type' => (string) ($validated['type'] ?? $entry?->type ?? 'sop'),
            'source_kind' => (string) ($validated['source_kind'] ?? $entry?->source_kind ?? 'article'),
            'owner_name' => $this->nullableTrim($validated['owner_name'] ?? $entry?->owner_name),
            'reviewer_name' => $this->nullableTrim($validated['reviewer_name'] ?? $entry?->reviewer_name),
            'version_label' => $this->nullableTrim($validated['version_label'] ?? $entry?->version_label),
            'effective_date' => $validated['effective_date'] ?? optional($entry?->effective_date)?->toDateString(),
            'reference_notes' => $this->nullableTrim($validated['reference_notes'] ?? $entry?->reference_notes),
            'source_link' => $this->nullableTrim($validated['source_link'] ?? $entry?->source_link),
            'tags' => collect($validated['tags'] ?? $entry?->tags ?? [])
                ->map(fn ($tag) => trim((string) $tag))
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'access_mode' => (string) ($validated['access_mode'] ?? $entry?->access_mode ?? 'all'),
            'sort_order' => (int) ($validated['sort_order'] ?? $entry?->sort_order ?? 0),
            'is_active' => (bool) ($validated['is_active'] ?? $entry?->is_active ?? true),
        ];

        if (
            $payload['access_mode'] === 'role_based'
            && empty($validated['role_ids'] ?? $entry?->roles->pluck('id')->all())
        ) {
            throw new HttpResponseException(response()->json([
                'errors' => [
                    'role_ids' => ['Pilih minimal satu role jika akses dibatasi.'],
                ],
            ], 422));
        }

        return $payload;
    }

    private function resolveKnowledgeSectionId(array $validated, ?KnowledgeEntry $entry = null): int
    {
        $sectionId = $validated['knowledge_section_id'] ?? null;

        if ($sectionId !== null) {
            return (int) $sectionId;
        }

        $spaceId = $validated['knowledge_space_id'] ?? null;

        if ($spaceId !== null) {
            $space = KnowledgeSpace::query()->findOrFail((int) $spaceId);

            return (int) $space->ensureDefaultSection()->id;
        }

        if ($entry) {
            return (int) $entry->knowledge_section_id;
        }

        throw new HttpResponseException(response()->json([
            'errors' => [
                'knowledge_space_id' => ['Pilih general knowledge atau salah satu divisi.'],
            ],
        ], 422));
    }

    private function resolveAttachmentPayload(
        Request $request,
        KnowledgeAttachmentService $attachmentService,
        ?KnowledgeEntry $entry = null
    ): array
    {
        if (! $request->hasFile('attachment')) {
            return [];
        }

        return $attachmentService->store(
            $request->file('attachment'),
            $entry?->attachment_path
        );
    }

    private function resolveRoleIds(array $validated): array
    {
        if (($validated['access_mode'] ?? 'all') === 'all') {
            return [];
        }

        return collect($validated['role_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function ensureGeneralSpace(): KnowledgeSpace
    {
        $space = KnowledgeSpace::query()->firstOrCreate(
            ['kind' => 'general'],
            [
                'name' => 'General Knowledge',
                'description' => 'Knowledge umum perusahaan untuk AI Assistant.',
                'icon' => 'sparkles',
                'sort_order' => 0,
                'is_active' => true,
                'show_in_hub' => false,
                'ai_provider' => 'zai',
            ]
        );

        $space->ensureDefaultSection();

        $needsSave = false;

        if ($space->show_in_hub) {
            $space->show_in_hub = false;
            $needsSave = true;
        }

        if (! $space->ai_provider) {
            $space->ai_provider = 'zai';
            $needsSave = true;
        }

        if ($needsSave) {
            $space->save();
        }

        return $space;
    }

    private function loadSpaceWithRelations(KnowledgeSpace $space): KnowledgeSpace
    {
        $space->load(['sections.entries.roles']);

        return $space;
    }

    private function transformAdminSpace(KnowledgeSpace $space): array
    {
        $documents = $this->documentsFromSections($space->sections)
            ->map(fn (KnowledgeEntry $entry) => $this->transformEntry($entry))
            ->values();

        return [
            'id' => $space->id,
            'name' => $space->name,
            'kind' => $space->kind,
            'description' => $space->description,
            'ai_instruction' => $space->ai_instruction,
            'knowledge_text' => $space->knowledge_text,
            'ai_provider' => $space->ai_provider ?: 'zai',
            'ai_local_base_url' => $space->ai_local_base_url,
            'ai_local_model' => $space->ai_local_model,
            'ai_local_timeout' => $space->ai_local_timeout,
            'has_ai_local_api_key' => filled($space->ai_local_api_key),
            'icon' => $space->icon ?: ($space->kind === 'general' ? 'sparkles' : 'folder'),
            'sort_order' => (int) $space->sort_order,
            'is_active' => (bool) $space->is_active,
            'show_in_hub' => (bool) $space->show_in_hub,
            'document_count' => $documents->count(),
            'documents' => $documents,
        ];
    }

    private function transformSection(KnowledgeSection $section): array
    {
        $documents = $section->entries
            ->sortBy('sort_order')
            ->values()
            ->map(fn (KnowledgeEntry $entry) => $this->transformEntry($entry))
            ->values();

        return [
            'id' => $section->id,
            'knowledge_space_id' => $section->knowledge_space_id,
            'name' => $section->name,
            'description' => $section->description,
            'sort_order' => (int) $section->sort_order,
            'is_active' => (bool) $section->is_active,
            'document_count' => $documents->count(),
            'documents' => $documents,
        ];
    }

    private function transformEntry(KnowledgeEntry $entry): array
    {
        $entry->loadMissing(['section.space', 'roles']);

        return [
            'id' => $entry->id,
            'knowledge_section_id' => $entry->knowledge_section_id,
            'knowledge_space_id' => $entry->section?->knowledge_space_id,
            'title' => $entry->title,
            'summary' => $entry->summary,
            'body' => $entry->body,
            'scope' => $entry->scope,
            'type' => $entry->type,
            'source_kind' => $entry->source_kind,
            'owner_name' => $entry->owner_name,
            'reviewer_name' => $entry->reviewer_name,
            'version_label' => $entry->version_label,
            'effective_date' => optional($entry->effective_date)?->toDateString(),
            'reference_notes' => $entry->reference_notes,
            'source_link' => $entry->source_link,
            'tags' => $entry->tags ?? [],
            'access_mode' => $entry->access_mode,
            'role_ids' => $entry->roles->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'role_names' => $entry->roles->pluck('name')->values()->all(),
            'sort_order' => (int) $entry->sort_order,
            'is_active' => (bool) $entry->is_active,
            'attachment_name' => $entry->attachment_name,
            'attachment_mime' => $entry->attachment_mime,
            'attachment_url' => $this->publicAttachmentUrl($entry->attachment_path),
            'space_name' => $entry->section?->space?->name,
            'section_name' => $entry->section?->name,
            'updated_at' => optional($entry->updated_at)?->toISOString(),
        ];
    }

    private function documentsFromSections(Collection $sections): Collection
    {
        return $sections
            ->flatMap(fn (KnowledgeSection $section) => $section->entries)
            ->sortBy('sort_order')
            ->values();
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function publicAttachmentUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return '/storage/'.ltrim($path, '/');
    }

    private function deleteAttachmentsForEntries($entries): void
    {
        foreach ($entries as $entry) {
            if ($entry->attachment_path) {
                Storage::disk('public')->delete($entry->attachment_path);
            }
        }
    }
}
