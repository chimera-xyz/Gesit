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
use App\Support\S21PlusAccountService;
use App\Support\S21PlusHelpdeskEscalationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class KnowledgeHubController extends Controller
{
    private const ACTION_S21PLUS_UNLOCK_CONFIRM = 's21plus_unlock_confirm';

    private const ACTION_S21PLUS_CONTACT_IT = 's21plus_contact_it';

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

    public function ask(Request $request, KnowledgeAssistantService $assistant, S21PlusAccountService $s21plusAccountService)
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer'],
        ]);

        try {
            $user = $request->user();
            $conversationId = $validated['conversation_id'] ?? null;

            [$conversation, $userMessage, $assistantMessage] = DB::transaction(function () use ($assistant, $conversationId, $user, $validated, $s21plusAccountService) {
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
                        'provider' => $message->provider,
                        'sources' => $message->sources ?? [],
                        'actions' => $message->actions ?? [],
                    ])
                    ->values()
                    ->all();

                $userMessage = $conversation->messages()->create([
                    'role' => 'user',
                    'content' => $validated['question'],
                ]);

                $payload = $this->matchesS21PlusSupportIntent($validated['question'], $history)
                    ? $this->buildS21PlusInspectionPayload(
                        $s21plusAccountService->inspectOwnAccount($user, [
                            'conversation_id' => $conversation->id,
                            'message_id' => $userMessage->id,
                        ]),
                        $user
                    )
                    : $assistant->answer($user, $validated['question'], $history);

                $assistantMessage = $conversation->messages()->create([
                    'role' => 'assistant',
                    'content' => $payload['answer'],
                    'scope' => $payload['scope'],
                    'provider' => $payload['provider'],
                    'sources' => $payload['sources'],
                    'actions' => $payload['actions'] ?? [],
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

    public function performConversationAction(
        Request $request,
        int $id,
        S21PlusAccountService $s21plusAccountService,
        S21PlusHelpdeskEscalationService $s21plusHelpdeskEscalationService
    )
    {
        $validated = $request->validate([
            'message_id' => ['required', 'integer'],
            'action_key' => ['required', 'string', 'max:80'],
        ]);

        try {
            $user = $request->user();

            [$conversation, $actionMessage, $userMessage, $assistantMessage] = DB::transaction(function () use (
                $validated,
                $user,
                $id,
                $s21plusAccountService,
                $s21plusHelpdeskEscalationService
            ) {
                $conversation = $this->findConversationForUser($user->id, $id)
                    ->load('messages');

                $actionMessage = $conversation->messages()
                    ->whereKey((int) $validated['message_id'])
                    ->where('role', 'assistant')
                    ->firstOrFail();

                $action = collect($actionMessage->actions ?? [])
                    ->first(fn (array $candidate) => ($candidate['key'] ?? null) === $validated['action_key']);

                if (! is_array($action)) {
                    throw ValidationException::withMessages([
                        'action_key' => 'Aksi tidak tersedia atau sudah tidak berlaku.',
                    ]);
                }

                $userMessage = $conversation->messages()->create([
                    'role' => 'user',
                    'content' => (string) ($action['label'] ?? 'Menjalankan aksi percakapan'),
                ]);

                $payload = $this->buildConversationActionPayload(
                    $action,
                    $user,
                    $conversation,
                    $userMessage,
                    $s21plusAccountService,
                    $s21plusHelpdeskEscalationService
                );

                $actionMessage->forceFill([
                    'actions' => !empty($payload['retain_source_actions'])
                        ? ($actionMessage->actions ?? [])
                        : [],
                ])->save();

                $assistantMessage = $conversation->messages()->create([
                    'role' => 'assistant',
                    'content' => $payload['answer'],
                    'scope' => $payload['scope'],
                    'provider' => $payload['provider'],
                    'sources' => $payload['sources'],
                    'actions' => $payload['actions'] ?? [],
                ]);

                $conversation->forceFill([
                    'last_message_at' => $assistantMessage->created_at,
                ])->save();

                return [
                    $conversation->fresh(['latestMessage'])->loadCount('messages'),
                    $actionMessage->fresh(),
                    $userMessage,
                    $assistantMessage,
                ];
            });

            return response()->json([
                'conversation' => $this->transformConversationSummary($conversation),
                'updated_message' => $this->transformConversationMessage($actionMessage),
                'user_message' => $this->transformConversationMessage($userMessage),
                'assistant_message' => $this->transformConversationMessage($assistantMessage),
                'scope' => $assistantMessage->scope,
                'answer' => $assistantMessage->content,
                'sources' => $assistantMessage->sources ?? [],
                'provider' => $assistantMessage->provider,
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (ModelNotFoundException) {
            return response()->json([
                'error' => 'Obrolan atau aksi tidak ditemukan.',
            ], 404);
        } catch (\Throwable $exception) {
            Log::error('Knowledge Conversation Action Error: '.$exception->getMessage());

            return response()->json([
                'error' => 'Aksi percakapan gagal dijalankan.',
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
            'actions' => $message->actions ?? [],
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

    private function matchesS21PlusSupportIntent(string $question, array $conversationHistory = []): bool
    {
        $normalized = $this->normalizeIntentText($question);
        $s21Markers = ['s21plus', 's21 plus', 's21+', 's21 +', 's21'];
        $blockedMarkers = ['keblok', 'keblokir', 'terblokir', 'ter block', 'terblock', 'unblock', 'buka blokir', 'unlock'];
        $accountContextMarkers = [
            'akun', 'account', 'aplikasi', 'app', 'login', 'masuk', 'akses', 'userid', 'user id',
            'id user', 'id akun', 'password', 'sign in', 'signin',
        ];
        $loginIssueMarkers = [
            'gabisa login', 'ga bisa login', 'gak bisa login', 'ngga bisa login', 'nggak bisa login',
            'tidak bisa login', 'gagal login', 'login gagal', 'salah password', 'lupa password',
            'gabisa masuk', 'ga bisa masuk', 'gak bisa masuk', 'ngga bisa masuk', 'nggak bisa masuk',
            'tidak bisa masuk', 'gabisa akses', 'ga bisa akses', 'gak bisa akses', 'tidak bisa akses',
            'gabisa kebuka', 'ga bisa kebuka', 'gak bisa kebuka', 'ngga bisa kebuka', 'nggak bisa kebuka',
            'tidak bisa kebuka', 'gabisa buka', 'ga bisa buka', 'gak bisa buka', 'ngga bisa buka',
            'nggak bisa buka', 'tidak bisa buka',
        ];
        $ambiguousOpenIssueMarkers = [
            'gabisa dibuka', 'ga bisa dibuka', 'gak bisa dibuka', 'ngga bisa dibuka', 'nggak bisa dibuka',
            'tidak bisa dibuka', 'susah dibuka', 'gagal dibuka',
        ];
        $statusFollowUpMarkers = [
            'coba cek lagi', 'cek lagi', 'cek dong', 'cek status', 'cek statusnya', 'cek status nya',
            'cek sekarang', 'cek skrg', 'status sekarang', 'statusnya sekarang', 'status nya sekarang',
            'kalo sekarang', 'kalau sekarang', 'gimana sekarang', 'gmn sekarang',
            'masih keblokir', 'masih keblok', 'masih terblokir', 'masih kebuka ga', 'masih kebuka gak',
            'masih kebuka nggak', 'masih gabisa', 'masih ga bisa', 'masih gak bisa', 'masih ngga bisa',
            'masih nggak bisa', 'udah bisa', 'sudah bisa', 'udah kebuka', 'sudah kebuka',
        ];
        $proofMarkers = [
            'bukti', 'buktinya', 'bukti nya', 'mana bukti', 'mana buktinya', 'mana bukti nya',
            'coba buktiin', 'lihat bukti', 'lihat status',
        ];
        $deviceMarkers = [
            'samsung', 'galaxy', 'handphone', 'hp', 'hape', 'ponsel', 'smartphone', 'android',
            'layar', 'baterai', 'charger', 'cas', 'charge', 'recovery', 'bootloop', 'service center',
            'tombol power', 'power button', 'volume up', 'volume down',
        ];

        $recentUserHistory = collect($conversationHistory)
            ->take(-6)
            ->filter(fn (array $message) => ($message['role'] ?? null) === 'user')
            ->map(fn (array $message) => $this->normalizeIntentText((string) ($message['content'] ?? '')))
            ->filter()
            ->values()
            ->all();

        $hasRecentS21SupportContext = $this->hasRecentS21SupportContext($conversationHistory);
        $hasS21Context = $this->containsAny($normalized, $s21Markers)
            || collect($recentUserHistory)->contains(fn (string $message) => $this->containsAny($message, $s21Markers))
            || $hasRecentS21SupportContext;
        $hasAccountContext = $this->containsAny($normalized, $accountContextMarkers)
            || collect($recentUserHistory)->contains(fn (string $message) => $this->containsAny($message, $accountContextMarkers))
            || $hasRecentS21SupportContext;
        $looksLikePhysicalDeviceIssue = $this->containsAny($normalized, $deviceMarkers) && ! $hasAccountContext;

        $hasSupportSignal = $this->containsAny($normalized, $blockedMarkers)
            || $this->containsAny($normalized, $loginIssueMarkers)
            || ($hasAccountContext && $this->containsAny($normalized, $ambiguousOpenIssueMarkers))
            || (
                $hasS21Context
                && ! $looksLikePhysicalDeviceIssue
                && $this->containsAny($normalized, $ambiguousOpenIssueMarkers)
            )
            || ($hasRecentS21SupportContext && (
                $this->containsAny($normalized, $statusFollowUpMarkers)
                || $this->containsAny($normalized, $proofMarkers)
            ));

        return $hasS21Context && ! $looksLikePhysicalDeviceIssue && $hasSupportSignal;
    }

    private function buildS21PlusInspectionPayload(array $result, \App\Models\User $user): array
    {
        $basePayload = [
            'scope' => 'conversation',
            'provider' => 'system',
            'sources' => [],
            'actions' => [],
        ];

        return match ($result['result_code']) {
            'mapping_missing' => [
                ...$basePayload,
                'answer' => 'Saya belum menemukan UserID S21Plus pada profil GESIT Anda. Saya bisa teruskan kendala ini ke Tim IT dan membuat ticket bantuan otomatis supaya Anda tidak perlu isi form manual.',
                'actions' => [
                    $this->makeS21PlusContactItAction($result),
                ],
            ],
            'account_not_found' => [
                ...$basePayload,
                'answer' => 'Saya tidak menemukan akun S21Plus yang terhubung dengan profil GESIT Anda. Kalau Anda mau, saya bisa buatkan ticket ke Tim IT langsung dari percakapan ini.',
                'actions' => [
                    $this->makeS21PlusContactItAction($result),
                ],
            ],
            'account_active' => [
                ...$basePayload,
                'answer' => sprintf(
                    'Saya cek langsung ke S21Plus sekarang. Akun Anda aktif dan tidak terblokir. %s Silakan coba login kembali. Jika aksesnya tetap bermasalah, saya bisa teruskan ke Tim IT lewat ticket otomatis.',
                    $this->buildS21PlusStateEvidence($result['after'] ?? $result['before'] ?? [])
                ),
                'actions' => [
                    $this->makeS21PlusContactItAction($result),
                ],
            ],
            'blocked_confirmed' => [
                ...$basePayload,
                'answer' => sprintf(
                    'Saya cek langsung ke S21Plus sekarang. Akun S21Plus Anda atas nama %s terdeteksi dalam status terblokir. %s Saya dapat membantu membuka blokir akun Anda sekarang. Apakah Anda ingin saya lanjutkan?',
                    $user->name
                    ,
                    $this->buildS21PlusStateEvidence($result['after'] ?? $result['before'] ?? [])
                ),
                'actions' => [
                    [
                        'key' => self::ACTION_S21PLUS_UNLOCK_CONFIRM,
                        'label' => 'Buka blokir sekarang',
                        'variant' => 'primary',
                    ],
                    $this->makeS21PlusContactItAction($result),
                ],
            ],
            'blocked_unexpected_state' => [
                ...$basePayload,
                'answer' => sprintf(
                    'Saya cek langsung ke S21Plus sekarang, tetapi status akun Anda tidak memenuhi kriteria self-service unblock. %s Saya bisa teruskan kasus ini ke Tim IT melalui ticket bantuan otomatis.',
                    $this->buildS21PlusStateEvidence($result['after'] ?? $result['before'] ?? [])
                ),
                'actions' => [
                    $this->makeS21PlusContactItAction($result),
                ],
            ],
            default => [
                ...$basePayload,
                'answer' => 'Maaf, saya belum bisa mengakses status akun S21Plus Anda saat ini karena ada kendala pada sistem. Kalau Anda setuju, saya bisa buatkan ticket ke Tim IT langsung dari percakapan ini.',
                'actions' => [
                    $this->makeS21PlusContactItAction($result),
                ],
            ],
        };
    }

    private function buildConversationActionPayload(
        array $action,
        \App\Models\User $user,
        KnowledgeConversation $conversation,
        KnowledgeConversationMessage $userMessage,
        S21PlusAccountService $s21plusAccountService,
        S21PlusHelpdeskEscalationService $s21plusHelpdeskEscalationService
    ): array {
        $actionKey = (string) ($action['key'] ?? '');

        return match ($actionKey) {
            self::ACTION_S21PLUS_UNLOCK_CONFIRM => $this->buildS21PlusUnlockPayload(
                $user,
                $conversation,
                $userMessage,
                $s21plusAccountService->unlockOwnAccount($user, [
                    'conversation_id' => $conversation->id,
                    'message_id' => $userMessage->id,
                ]),
                $s21plusHelpdeskEscalationService
            ),
            self::ACTION_S21PLUS_CONTACT_IT => $this->buildS21PlusEscalationPayload(
                $user,
                $conversation,
                $userMessage,
                (array) ($action['context'] ?? []),
                $s21plusHelpdeskEscalationService,
                'manual_request'
            ),
            default => throw ValidationException::withMessages([
                'action_key' => 'Aksi tidak dikenali.',
            ]),
        };
    }

    private function buildS21PlusUnlockPayload(
        \App\Models\User $user,
        KnowledgeConversation $conversation,
        KnowledgeConversationMessage $userMessage,
        array $result,
        S21PlusHelpdeskEscalationService $s21plusHelpdeskEscalationService
    ): array
    {
        $basePayload = [
            'scope' => 'conversation',
            'provider' => 'system',
            'sources' => [],
            'actions' => [],
        ];

        return match ($result['result_code']) {
            'unlock_success' => [
                ...$basePayload,
                'answer' => sprintf(
                    'Akun S21Plus Anda berhasil dibuka blokir. Verifikasi ulang di S21Plus menunjukkan %s Silakan coba login kembali. Jika masih ada kendala, saya siap bantu lanjutkan pengecekan berikutnya.',
                    $this->buildS21PlusStateEvidence($result['after'] ?? [])
                ),
            ],
            'account_active' => [
                ...$basePayload,
                'answer' => sprintf(
                    'Akun S21Plus Anda sudah terdeteksi aktif, jadi proses unblock tidak perlu dijalankan lagi. %s Silakan coba login kembali. Jika aksesnya tetap bermasalah, saya bisa buatkan ticket ke Tim IT.',
                    $this->buildS21PlusStateEvidence($result['after'] ?? $result['before'] ?? [])
                ),
                'actions' => [
                    $this->makeS21PlusContactItAction($result),
                ],
            ],
            'mapping_missing',
            'account_not_found',
            'blocked_unexpected_state',
            'service_unavailable',
            'unlock_failed',
            'verification_failed' => $this->buildS21PlusEscalationPayload(
                $user,
                $conversation,
                $userMessage,
                $result,
                $s21plusHelpdeskEscalationService,
                'unlock_failed'
            ),
            default => $this->buildS21PlusEscalationPayload(
                $user,
                $conversation,
                $userMessage,
                $result,
                $s21plusHelpdeskEscalationService,
                'unlock_failed'
            ),
        };
    }

    private function makeS21PlusContactItAction(array $result): array
    {
        return [
            'key' => self::ACTION_S21PLUS_CONTACT_IT,
            'label' => 'Buat ticket ke Tim IT',
            'variant' => 'secondary',
            'context' => [
                'audit_log_id' => $result['audit_log_id'] ?? null,
                'request_type' => $result['request_type'] ?? null,
                'status' => $result['status'] ?? null,
                'result_code' => $result['result_code'] ?? null,
                'message' => $result['message'] ?? null,
                's21plus_user_id' => $result['s21plus_user_id'] ?? null,
                'before' => $result['before'] ?? [],
                'after' => $result['after'] ?? [],
            ],
        ];
    }

    private function buildS21PlusEscalationPayload(
        \App\Models\User $user,
        KnowledgeConversation $conversation,
        KnowledgeConversationMessage $userMessage,
        array $result,
        S21PlusHelpdeskEscalationService $s21plusHelpdeskEscalationService,
        string $escalationReason
    ): array {
        $basePayload = [
            'scope' => 'conversation',
            'provider' => 'system',
            'sources' => [],
            'actions' => [],
        ];

        try {
            $escalation = $s21plusHelpdeskEscalationService->escalate($user, $conversation, $result, [
                'conversation_message_id' => $userMessage->id,
                'trigger_action_key' => $escalationReason === 'manual_request'
                    ? self::ACTION_S21PLUS_CONTACT_IT
                    : self::ACTION_S21PLUS_UNLOCK_CONFIRM,
                'escalation_reason' => $escalationReason,
                'exclude_user_message_ids' => [$userMessage->id],
            ]);

            $ticket = $escalation['ticket'];
            $issueSummary = trim((string) data_get($escalation, 'draft.issue_summary', 'kendala akses akun S21Plus'));
            $isReused = (bool) ($escalation['ticket_reused'] ?? false);

            $answer = $isReused
                ? "Saya menemukan ticket {$ticket->ticket_number} yang masih aktif untuk {$this->lowercaseFirst($issueSummary)}. Saya tambahkan konteks terbaru ke ticket yang sama agar tidak duplikat, dan Tim IT sudah menerima update baru."
                : "Saya sudah buat ticket {$ticket->ticket_number} untuk {$this->lowercaseFirst($issueSummary)}. Ringkasan percakapan dan hasil cek S21Plus terbaru sudah saya kirim ke antrian Tim IT, jadi Anda tidak perlu isi form manual lagi.";

            $answer .= ' Anda bisa pantau progresnya dari menu Butuh Bantuan IT.';

            return [
                ...$basePayload,
                'answer' => $answer,
            ];
        } catch (\Throwable $exception) {
            Log::warning('Automatic S21Plus helpdesk escalation failed', [
                'gesit_user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'result_code' => $result['result_code'] ?? null,
                'reason' => $escalationReason,
                'error' => $exception->getMessage(),
            ]);

            return [
                ...$basePayload,
                'answer' => 'Saya belum berhasil membuat ticket bantuan otomatis dari percakapan ini. Silakan coba lagi sekali lagi atau buka menu Butuh Bantuan IT untuk melanjutkan pelaporan.',
                'retain_source_actions' => true,
            ];
        }
    }

    private function normalizeIntentText(string $text): string
    {
        $normalized = Str::lower(Str::ascii($text));
        $normalized = preg_replace('/[^a-z0-9\+\-]+/u', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);
    }

    private function hasRecentS21SupportContext(array $conversationHistory): bool
    {
        return collect($conversationHistory)
            ->take(-8)
            ->contains(function (array $message) {
                $normalizedContent = $this->normalizeIntentText((string) ($message['content'] ?? ''));
                $provider = (string) ($message['provider'] ?? '');
                $actions = collect($message['actions'] ?? []);

                $hasS21Action = $actions->contains(
                    fn (mixed $action) => is_array($action) && str_starts_with((string) ($action['key'] ?? ''), 's21plus_')
                );

                return $hasS21Action
                    || ($provider === 'system' && $this->containsAny($normalizedContent, ['akun s21plus', 's21plus', 's21 plus']));
            });
    }

    private function buildS21PlusStateEvidence(array $state): string
    {
        $segments = [];

        if (array_key_exists('is_enabled', $state) && $state['is_enabled'] !== null) {
            $segments[] = sprintf('IsEnabled = %d', $state['is_enabled'] ? 1 : 0);
        }

        if (array_key_exists('login_retry', $state) && $state['login_retry'] !== null) {
            $segments[] = sprintf('LoginRetry = %d', (int) $state['login_retry']);
        }

        if ($segments === []) {
            return 'status detail belum tersedia.';
        }

        return 'status realtime: '.implode(', ', $segments).'.';
    }

    private function lowercaseFirst(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return $trimmed;
        }

        return Str::lcfirst($trimmed);
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function scopeLabel(?string $scope): ?string
    {
        return match ($scope) {
            'internal' => 'Internal',
            'inventory' => 'Inventaris IT',
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
