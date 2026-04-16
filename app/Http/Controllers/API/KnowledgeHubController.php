<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeBookmark;
use App\Models\KnowledgeConversation;
use App\Models\KnowledgeConversationMessage;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeSection;
use App\Models\KnowledgeSpace;
use App\Support\KnowledgeAttachmentService;
use App\Support\KnowledgeAssistantService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class KnowledgeHubController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = $request->user()->loadMissing('roles');
            $entries = KnowledgeEntry::query()
                ->with(['section.space', 'roles'])
                ->visibleTo($user)
                ->whereHas('section.space', fn ($query) => $query->where('show_in_hub', true))
                ->orderBy('sort_order')
                ->orderByDesc('updated_at')
                ->get();

            $bookmarkedIds = KnowledgeBookmark::query()
                ->where('user_id', $user->id)
                ->pluck('knowledge_entry_id')
                ->all();

            $spaces = KnowledgeSpace::query()
                ->with(['sections.entries' => function ($query) use ($user) {
                    $query->with('roles')
                        ->visibleTo($user)
                        ->orderBy('sort_order')
                        ->orderByDesc('updated_at');
                }])
                ->where('is_active', true)
                ->where('show_in_hub', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

            return response()->json([
                'spaces' => $spaces->map(fn (KnowledgeSpace $space) => $this->transformSpace($space, $bookmarkedIds))->values(),
                'entries' => $entries->map(fn (KnowledgeEntry $entry) => $this->transformEntry($entry, $bookmarkedIds))->values(),
                'bookmarked_ids' => $bookmarkedIds,
                'suggested_questions' => [
                    'Cara closing MKBD harian gimana?',
                    'SOP reimburse makan dinas ada di mana?',
                    'Istilah settlement dan kliring itu apa?',
                    'Dokumen onboarding divisi IT yang wajib dibaca apa saja?',
                ],
                'filters' => [
                    'types' => [
                        ['value' => 'all', 'label' => 'Semua tipe'],
                        ['value' => 'sop', 'label' => 'SOP'],
                        ['value' => 'troubleshooting', 'label' => 'Troubleshooting'],
                        ['value' => 'onboarding', 'label' => 'Onboarding'],
                        ['value' => 'form', 'label' => 'Form'],
                        ['value' => 'policy', 'label' => 'Kebijakan'],
                        ['value' => 'jobdesk', 'label' => 'Jobdesk'],
                        ['value' => 'faq', 'label' => 'FAQ'],
                    ],
                    'scopes' => [
                        ['value' => 'all', 'label' => 'Semua mode'],
                        ['value' => 'internal', 'label' => 'Internal'],
                        ['value' => 'securities_domain', 'label' => 'Domain Sekuritas'],
                    ],
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::error('Knowledge Hub Index Error: '.$exception->getMessage());

            return response()->json([
                'error' => 'Knowledge hub gagal dimuat.',
            ], 500);
        }
    }

    public function ask(Request $request, KnowledgeAssistantService $assistant)
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer'],
        ]);

        try {
            $user = $request->user();
            $conversationId = $validated['conversation_id'] ?? null;

            [$conversation, $userMessage, $assistantMessage] = DB::transaction(function () use ($assistant, $conversationId, $user, $validated) {
                $conversation = $conversationId
                    ? $this->findConversationForUser($user->id, (int) $conversationId)->load('messages')
                    : KnowledgeConversation::query()->create([
                        'user_id' => $user->id,
                        'title' => $this->makeConversationTitle($validated['question']),
                    ]);

                $history = $conversation->messages
                    ->map(fn (KnowledgeConversationMessage $message) => [
                        'role' => $message->role,
                        'content' => $message->content,
                        'scope' => $message->scope,
                        'sources' => $message->sources ?? [],
                    ])
                    ->values()
                    ->all();

                $payload = $assistant->answer($user, $validated['question'], $history);

                $userMessage = $conversation->messages()->create([
                    'role' => 'user',
                    'content' => $validated['question'],
                ]);

                $assistantMessage = $conversation->messages()->create([
                    'role' => 'assistant',
                    'content' => $payload['answer'],
                    'scope' => $payload['scope'],
                    'provider' => $payload['provider'],
                    'sources' => $payload['sources'],
                ]);

                $conversation->forceFill([
                    'last_message_at' => $assistantMessage->created_at,
                ])->save();

                return [$conversation->fresh(['latestMessage'])->loadCount('messages'), $userMessage, $assistantMessage];
            });

            return response()->json([
                'conversation' => $this->transformConversationSummary($conversation),
                'user_message' => $this->transformConversationMessage($userMessage),
                'assistant_message' => $this->transformConversationMessage($assistantMessage),
                'scope' => $assistantMessage->scope,
                'answer' => $assistantMessage->content,
                'sources' => $assistantMessage->sources ?? [],
                'provider' => $assistantMessage->provider,
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'error' => 'Obrolan tidak ditemukan.',
            ], 404);
        } catch (\Throwable $exception) {
            Log::error('Knowledge Assistant Error: '.$exception->getMessage());

            return response()->json([
                'error' => 'Knowledge assistant gagal memproses pertanyaan.',
            ], 500);
        }
    }

    public function conversations(Request $request)
    {
        try {
            $search = trim((string) $request->query('search', ''));
            $query = KnowledgeConversation::query()
                ->where('user_id', $request->user()->id)
                ->with('latestMessage')
                ->withCount('messages');

            if ($search !== '') {
                $query->where(function ($builder) use ($search) {
                    $builder
                        ->where('title', 'like', '%'.$search.'%')
                        ->orWhereHas('messages', function ($messageQuery) use ($search) {
                            $messageQuery->where('content', 'like', '%'.$search.'%');
                        });
                });
            }

            $conversations = $query
                ->orderByDesc('last_message_at')
                ->orderByDesc('updated_at')
                ->get();

            return response()->json([
                'conversations' => $conversations->map(fn (KnowledgeConversation $conversation) => $this->transformConversationSummary($conversation))->values(),
            ]);
        } catch (\Throwable $exception) {
            Log::error('Knowledge Conversation Index Error: '.$exception->getMessage());

            return response()->json([
                'error' => 'Riwayat obrolan gagal dimuat.',
            ], 500);
        }
    }

    public function showConversation(Request $request, int $id)
    {
        try {
            $conversation = $this->findConversationForUser($request->user()->id, $id)
                ->loadCount('messages')
                ->load(['latestMessage', 'messages']);

            return response()->json([
                'conversation' => $this->transformConversationSummary($conversation),
                'messages' => $conversation->messages
                    ->map(fn (KnowledgeConversationMessage $message) => $this->transformConversationMessage($message))
                    ->values(),
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'error' => 'Obrolan tidak ditemukan.',
            ], 404);
        } catch (\Throwable $exception) {
            Log::error('Knowledge Conversation Show Error: '.$exception->getMessage());

            return response()->json([
                'error' => 'Detail obrolan gagal dimuat.',
            ], 500);
        }
    }

    public function updateConversation(Request $request, int $id)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
        ]);
        $title = $this->makeConversationTitle($validated['title']);

        if ($title === '') {
            return response()->json([
                'error' => 'Nama obrolan wajib diisi.',
            ], 422);
        }

        try {
            $conversation = $this->findConversationForUser($request->user()->id, $id);
            $conversation->forceFill([
                'title' => $title,
            ])->save();

            return response()->json([
                'conversation' => $this->transformConversationSummary(
                    $conversation->fresh(['latestMessage'])->loadCount('messages')
                ),
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'error' => 'Obrolan tidak ditemukan.',
            ], 404);
        } catch (\Throwable $exception) {
            Log::error('Knowledge Conversation Update Error: '.$exception->getMessage());

            return response()->json([
                'error' => 'Nama obrolan gagal diperbarui.',
            ], 500);
        }
    }

    public function destroyConversation(Request $request, int $id)
    {
        try {
            $conversation = $this->findConversationForUser($request->user()->id, $id);
            $conversation->delete();

            return response()->json([
                'deleted' => true,
                'conversation_id' => $id,
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'error' => 'Obrolan tidak ditemukan.',
            ], 404);
        } catch (\Throwable $exception) {
            Log::error('Knowledge Conversation Delete Error: '.$exception->getMessage());

            return response()->json([
                'error' => 'Obrolan gagal dihapus.',
            ], 500);
        }
    }

    public function toggleBookmark(Request $request, int $id)
    {
        try {
            $entry = KnowledgeEntry::query()
                ->with(['section.space'])
                ->visibleTo($request->user())
                ->findOrFail($id);

            $bookmark = KnowledgeBookmark::query()->where([
                'user_id' => $request->user()->id,
                'knowledge_entry_id' => $entry->id,
            ])->first();

            if ($bookmark) {
                $bookmark->delete();

                return response()->json([
                    'bookmarked' => false,
                    'entry_id' => $entry->id,
                ]);
            }

            KnowledgeBookmark::query()->create([
                'user_id' => $request->user()->id,
                'knowledge_entry_id' => $entry->id,
            ]);

            return response()->json([
                'bookmarked' => true,
                'entry_id' => $entry->id,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Knowledge Bookmark Error: '.$exception->getMessage());

            return response()->json([
                'error' => 'Bookmark knowledge gagal diperbarui.',
            ], 500);
        }
    }

    public function storeFolder(Request $request, int $spaceId)
    {
        try {
            $space = $this->findWritableDivisionSpace($spaceId);
            $validated = $request->validate([
                'name' => [
                    'required',
                    'string',
                    'max:120',
                    Rule::unique('knowledge_sections', 'name')->where(
                        fn ($query) => $query->where('knowledge_space_id', $space->id)
                    ),
                ],
                'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            ]);

            $section = KnowledgeSection::query()->create([
                'knowledge_space_id' => $space->id,
                'name' => trim((string) $validated['name']),
                'description' => $this->nullableTrim($validated['description'] ?? null),
                'sort_order' => (int) (KnowledgeSection::query()
                    ->where('knowledge_space_id', $space->id)
                    ->where('is_default', false)
                    ->max('sort_order') ?? 0) + 1,
                'is_active' => true,
                'is_default' => false,
            ]);

            return response()->json([
                'success' => true,
                'folder' => $this->transformSection($section),
            ], 201);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (ModelNotFoundException) {
            return response()->json([
                'error' => 'Divisi tidak ditemukan atau tidak tersedia.',
            ], 404);
        } catch (\Throwable $exception) {
            Log::error('Knowledge Hub Store Folder Error: '.$exception->getMessage());

            return response()->json([
                'error' => 'Folder gagal dibuat.',
            ], 500);
        }
    }

    public function storeEntry(Request $request, int $spaceId, KnowledgeAttachmentService $attachmentService)
    {
        try {
            $space = $this->findWritableDivisionSpace($spaceId);
            $validated = $request->validate([
                'knowledge_section_id' => ['sometimes', 'nullable', Rule::exists('knowledge_sections', 'id')],
                'title' => ['required', 'string', 'max:180'],
                'summary' => ['sometimes', 'nullable', 'string', 'max:500'],
                'body' => ['sometimes', 'nullable', 'string'],
                'type' => ['required', Rule::in(['sop', 'troubleshooting', 'onboarding', 'form', 'policy', 'jobdesk', 'faq'])],
                'attachment' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,png,jpg,jpeg,webp,txt'],
            ]);

            $section = $this->resolveWritableSection(
                $space,
                $validated['knowledge_section_id'] ?? null
            );

            $entry = DB::transaction(function () use ($request, $validated, $attachmentService, $section) {
                $attachmentPayload = $attachmentService->store($request->file('attachment'));
                $body = $this->nullableTrim($validated['body'] ?? null);
                $attachmentText = $attachmentPayload['attachment_text'] ?? null;
                $summary = $this->nullableTrim($validated['summary'] ?? null);

                if ($summary === null && $attachmentText) {
                    $summary = Str::limit(
                        preg_replace('/\s+/', ' ', trim($attachmentText)) ?: '',
                        180,
                        '...'
                    ) ?: null;
                }

                return KnowledgeEntry::query()->create([
                    'knowledge_section_id' => $section->id,
                    'title' => trim((string) $validated['title']),
                    'summary' => $summary,
                    'body' => $body,
                    'scope' => 'internal',
                    'type' => (string) $validated['type'],
                    'source_kind' => $body ? 'hybrid' : 'file',
                    'owner_name' => $request->user()->name,
                    'access_mode' => 'all',
                    'sort_order' => (int) (KnowledgeEntry::query()
                        ->where('knowledge_section_id', $section->id)
                        ->max('sort_order') ?? 0) + 1,
                    'is_active' => true,
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                    ...$attachmentPayload,
                ])->load(['section.space', 'roles']);
            });

            return response()->json([
                'success' => true,
                'document' => $this->transformEntry($entry, []),
            ], 201);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (ModelNotFoundException) {
            return response()->json([
                'error' => 'Folder tujuan tidak ditemukan atau tidak tersedia.',
            ], 404);
        } catch (\Throwable $exception) {
            Log::error('Knowledge Hub Store Entry Error: '.$exception->getMessage());

            return response()->json([
                'error' => 'Upload dokumen gagal diproses.',
            ], 500);
        }
    }

    private function transformSpace(KnowledgeSpace $space, array $bookmarkedIds): array
    {
        $entries = $space->sections
            ->where('is_active', true)
            ->flatMap(function ($section) use ($bookmarkedIds) {
                return $section->entries
                    ->where('is_active', true)
                    ->map(fn (KnowledgeEntry $entry) => $this->transformEntry($entry, $bookmarkedIds));
            })
            ->values();

        $defaultSection = $space->sections
            ->first(fn (KnowledgeSection $section) => $section->is_default);
        $visibleSections = $space->sections
            ->filter(fn (KnowledgeSection $section) => $section->is_active && ! $section->is_default)
            ->map(fn (KnowledgeSection $section) => $this->transformSection($section))
            ->values();

        return [
            'id' => $space->id,
            'name' => $space->name,
            'description' => $space->description,
            'icon' => $space->icon ?: 'folder',
            'kind' => $space->kind,
            'is_active' => (bool) $space->is_active,
            'default_section_id' => $defaultSection?->id,
            'root_entry_count' => $entries->where('section_is_default', true)->count(),
            'entry_count' => $entries->count(),
            'sections' => $visibleSections,
        ];
    }

    private function transformEntry(KnowledgeEntry $entry, array $bookmarkedIds): array
    {
        $content = $this->entryContent($entry);
        $attachmentUrl = $this->publicAttachmentUrl($entry->attachment_path);
        $previewableMimePrefixes = ['image/', 'application/pdf', 'text/plain'];
        $attachmentPreviewable = $attachmentUrl && collect($previewableMimePrefixes)->contains(function (string $prefix) use ($entry) {
            return Str::startsWith((string) $entry->attachment_mime, $prefix);
        });
        $sectionIsDefault = (bool) ($entry->section?->is_default);
        $directoryName = $sectionIsDefault ? 'Root' : ($entry->section?->name ?? 'Folder');

        return [
            'id' => $entry->id,
            'title' => $entry->title,
            'summary' => $entry->summary ?: Str::limit(strip_tags($content), 180, '...'),
            'body' => Str::limit($content, 12000, "\n\n..."),
            'scope' => $entry->scope,
            'scope_label' => $entry->scope === 'securities_domain' ? 'Domain Sekuritas' : 'Internal',
            'type' => $entry->type,
            'type_label' => $this->typeLabel($entry->type),
            'source_kind' => $entry->source_kind,
            'source_kind_label' => $this->sourceKindLabel($entry->source_kind),
            'space_id' => $entry->section?->space?->id,
            'space_name' => $entry->section?->space?->name,
            'section_id' => $entry->section?->id,
            'section_name' => $directoryName,
            'section_is_default' => $sectionIsDefault,
            'path_label' => trim(($entry->section?->space?->name ?? '').' / '.$directoryName),
            'owner_name' => $entry->owner_name ?: '-',
            'reviewer_name' => $entry->reviewer_name ?: '-',
            'version_label' => $entry->version_label ?: 'Belum diisi',
            'effective_date' => optional($entry->effective_date)?->toDateString(),
            'effective_date_label' => optional($entry->effective_date)?->format('d M Y') ?: 'Belum diisi',
            'reference_notes' => $entry->reference_notes ?: 'Referensi halaman belum dicatat',
            'source_link' => $entry->source_link,
            'tags' => $entry->tags ?? [],
            'access_mode' => $entry->access_mode,
            'attachment_url' => $attachmentUrl,
            'attachment_name' => $entry->attachment_name,
            'attachment_mime' => $entry->attachment_mime,
            'attachment_previewable' => $attachmentPreviewable,
            'is_bookmarked' => in_array($entry->id, $bookmarkedIds, true),
            'updated_at' => optional($entry->updated_at)?->toISOString(),
        ];
    }

    private function transformSection(KnowledgeSection $section): array
    {
        return [
            'id' => $section->id,
            'knowledge_space_id' => $section->knowledge_space_id,
            'name' => $section->name,
            'description' => $section->description,
            'sort_order' => (int) $section->sort_order,
            'is_active' => (bool) $section->is_active,
            'is_default' => (bool) $section->is_default,
            'entry_count' => $section->entries
                ->where('is_active', true)
                ->count(),
        ];
    }

    private function entryContent(KnowledgeEntry $entry): string
    {
        $parts = collect([
            trim((string) $entry->body),
            trim((string) $entry->attachment_text),
        ])->filter();

        return $parts->implode("\n\n");
    }

    private function resolveWritableSection(KnowledgeSpace $space, ?int $sectionId = null): KnowledgeSection
    {
        if ($sectionId === null) {
            return $space->ensureDefaultSection();
        }

        return KnowledgeSection::query()
            ->where('knowledge_space_id', $space->id)
            ->where('id', $sectionId)
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function findWritableDivisionSpace(int $spaceId): KnowledgeSpace
    {
        $space = KnowledgeSpace::query()
            ->where('id', $spaceId)
            ->where('kind', 'division')
            ->where('is_active', true)
            ->where('show_in_hub', true)
            ->firstOrFail();

        $space->ensureDefaultSection();

        return $space;
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

    private function transformConversationSummary(KnowledgeConversation $conversation): array
    {
        $latestMessage = $conversation->latestMessage;

        return [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'preview' => $latestMessage ? Str::limit(trim((string) $latestMessage->content), 120, '...') : '',
            'message_count' => (int) ($conversation->messages_count ?? $conversation->messages()->count()),
            'updated_at' => optional($conversation->updated_at)->toISOString(),
            'last_message_at' => optional($conversation->last_message_at)->toISOString(),
        ];
    }

    private function transformConversationMessage(KnowledgeConversationMessage $message): array
    {
        $sources = $message->sources ?? [];
        [$sourceIntro, $sourceClosing] = $this->splitSourceMessageContent($message->content, $sources);

        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'source_intro' => $sourceIntro,
            'source_closing' => $sourceClosing,
            'sources' => $sources,
            'scopeLabel' => $message->role === 'assistant' ? $this->scopeLabel($message->scope) : null,
            'created_at' => optional($message->created_at)->toISOString(),
        ];
    }

    private function splitSourceMessageContent(string $content, array $sources): array
    {
        if ($sources === []) {
            return [null, null];
        }

        $parts = preg_split('/\[\[DOCUMENT_CARDS\]\]/', $content, 2);

        if (is_array($parts) && count($parts) === 2) {
            return [
                trim($parts[0]) ?: null,
                trim($parts[1]) ?: null,
            ];
        }

        return [
            trim($content) ?: null,
            null,
        ];
    }

    private function makeConversationTitle(string $question): string
    {
        return (string) Str::of($question)
            ->squish()
            ->trim()
            ->limit(72, '...');
    }

    private function findConversationForUser(int $userId, int $conversationId): KnowledgeConversation
    {
        return KnowledgeConversation::query()
            ->where('user_id', $userId)
            ->findOrFail($conversationId);
    }

    private function scopeLabel(?string $scope): ?string
    {
        return match ($scope) {
            'internal' => 'Internal',
            'securities_domain' => 'Domain Sekuritas',
            'error' => 'Error',
            default => null,
        };
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'sop' => 'SOP',
            'troubleshooting' => 'Troubleshooting',
            'onboarding' => 'Onboarding',
            'form' => 'Form',
            'policy' => 'Kebijakan',
            'jobdesk' => 'Jobdesk',
            'faq' => 'FAQ',
            default => Str::headline($type),
        };
    }

    private function sourceKindLabel(string $sourceKind): string
    {
        return match ($sourceKind) {
            'file' => 'Dokumen upload',
            'hybrid' => 'Dokumen + ringkasan',
            default => 'Artikel internal',
        };
    }
}
