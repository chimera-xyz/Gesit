<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\HelpdeskTicket;
use App\Models\HelpdeskTicketUpdate;
use App\Models\Notification;
use App\Models\User;
use App\Support\HelpdeskTicketService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class HelpdeskTicketController extends Controller
{
    private const CATEGORY_OPTIONS = [
        'hardware' => 'Mouse / Keyboard / Perangkat',
        'printer' => 'Printer / Scanner',
        'internet' => 'Internet / VPN',
        'email' => 'Email / Outlook',
        'account_access' => 'Akses Akun / Permission',
        'software' => 'Aplikasi / Software',
        'installation' => 'Instalasi Aplikasi',
        'other' => 'Lainnya',
    ];

    private const PRIORITY_OPTIONS = [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'critical' => 'Critical',
    ];

    private const STATUS_OPTIONS = [
        'open' => 'Open',
        'in_progress' => 'In Progress',
        'waiting_user' => 'Waiting User',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ];

    private const CHANNEL_OPTIONS = [
        'portal' => 'Portal',
        'phone' => 'Panggilan',
    ];

    /**
     * List helpdesk tickets for requester or IT queue.
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $canManage = $this->canManageTickets($user);
            $baseQuery = $this->visibleTicketsQuery($user, $canManage);
            $query = clone $baseQuery;

            if ($request->filled('search')) {
                $search = trim((string) $request->string('search')->value());

                $query->where(function (Builder $ticketQuery) use ($search, $canManage) {
                    $ticketQuery->where('ticket_number', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");

                    if ($canManage) {
                        $ticketQuery->orWhereHas('requester', function (Builder $requesterQuery) use ($search) {
                            $requesterQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('department', 'like', "%{$search}%");
                        });
                    }
                });
            }

            foreach (['status', 'category', 'priority', 'channel'] as $filterKey) {
                if ($request->filled($filterKey)) {
                    $query->where($filterKey, $request->string($filterKey)->value());
                }
            }

            if ($canManage && $request->filled('assignment')) {
                $assignment = $request->string('assignment')->value();

                if ($assignment === 'assigned_to_me') {
                    $query->where('assigned_to', $user->id);
                } elseif ($assignment === 'unassigned') {
                    $query->whereNull('assigned_to');
                }
            }

            $tickets = $query
                ->orderByRaw($this->statusOrderSql())
                ->orderByDesc('last_activity_at')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (HelpdeskTicket $ticket) => $this->transformTicketSummary($ticket, $user, $canManage))
                ->values();

            return response()->json([
                'tickets' => $tickets,
                'stats' => $this->buildStats(clone $baseQuery),
                'filters' => [
                    'categories' => $this->optionList(self::CATEGORY_OPTIONS),
                    'priorities' => $this->optionList(self::PRIORITY_OPTIONS),
                    'statuses' => $this->optionList(self::STATUS_OPTIONS),
                    'channels' => $this->optionList(self::CHANNEL_OPTIONS),
                ],
                'requesters' => $canManage ? $this->activeRequesters() : [],
                'assignees' => $canManage ? $this->activeHelpdeskAssignees() : [],
                'can_manage' => $canManage,
            ]);
        } catch (\Exception $e) {
            Log::error('List Helpdesk Tickets Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Show a single helpdesk ticket with timeline.
     */
    public function show(Request $request, int $id)
    {
        try {
            $user = $request->user();
            $canManage = $this->canManageTickets($user);
            $ticket = HelpdeskTicket::query()
                ->with([
                    'requester.roles',
                    'creator',
                    'assignee',
                    'updates.user.roles',
                ])
                ->findOrFail($id);

            $this->authorizeTicketAccess($ticket, $user, $canManage);

            return response()->json([
                'ticket' => $this->transformTicketDetail($ticket, $user, $canManage),
                'filters' => [
                    'categories' => $this->optionList(self::CATEGORY_OPTIONS),
                    'priorities' => $this->optionList(self::PRIORITY_OPTIONS),
                    'statuses' => $this->optionList(self::STATUS_OPTIONS),
                    'channels' => $this->optionList(self::CHANNEL_OPTIONS),
                ],
                'requesters' => $canManage ? $this->activeRequesters() : [],
                'assignees' => $canManage ? $this->activeHelpdeskAssignees() : [],
                'can_manage' => $canManage,
            ]);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Show Helpdesk Ticket Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Create a new helpdesk ticket from portal or call logging.
     */
    public function store(Request $request, HelpdeskTicketService $helpdeskTicketService)
    {
        try {
            $user = $request->user();
            $canManage = $this->canManageTickets($user);
            $validated = $request->validate($this->storeRules($canManage));

            $requester = ($canManage && !empty($validated['requester_id']))
                ? User::query()->findOrFail((int) $validated['requester_id'])
                : $user;

            $channel = $canManage ? ($validated['channel'] ?? 'portal') : 'portal';
            $priority = $canManage
                ? ($validated['priority'] ?? (($validated['is_blocking'] ?? false) ? 'high' : 'normal'))
                : (($validated['is_blocking'] ?? false) ? 'high' : 'normal');

            $assignedTo = null;

            if ($canManage) {
                if (!empty($validated['assign_to_me'])) {
                    $assignedTo = (int) $user->id;
                } elseif (!empty($validated['assigned_to'])) {
                    $assignedTo = (int) $validated['assigned_to'];
                }
            }

            $status = 'open';

            if ($canManage && !empty($validated['status']) && array_key_exists($validated['status'], self::STATUS_OPTIONS)) {
                $status = $validated['status'];
            } elseif ($assignedTo) {
                $status = 'in_progress';
            }

            $attachmentPath = null;
            $attachmentName = null;

            if ($request->hasFile('attachment')) {
                $attachmentPath = $request->file('attachment')->store('helpdesk-attachments', 'public');
                $attachmentName = $request->file('attachment')->getClientOriginalName();
            }

            $ticket = $helpdeskTicketService->createTicket($user, $requester, [
                'category' => $validated['category'],
                'subject' => $validated['subject'] ?? null,
                'description' => trim((string) $validated['description']),
                'channel' => $channel,
                'priority' => $priority,
                'status' => $status,
                'assigned_to' => $assignedTo,
                'attachment_path' => $attachmentPath,
                'attachment_name' => $attachmentName,
                'context' => $this->buildTicketContext($request, $requester, $validated),
            ]);

            return response()->json([
                'success' => true,
                'ticket' => $this->transformTicketDetail($ticket, $user, $canManage),
            ], 201);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Create Helpdesk Ticket Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update assignment, priority, or status of an existing helpdesk ticket.
     */
    public function update(Request $request, int $id)
    {
        try {
            $user = $request->user();
            $canManage = $this->canManageTickets($user);
            $ticket = HelpdeskTicket::query()
                ->with(['requester.roles', 'creator', 'assignee', 'updates.user.roles'])
                ->findOrFail($id);

            $this->authorizeTicketAccess($ticket, $user, $canManage);

            if (!$canManage) {
                return $this->handleRequesterTicketAction($request, $ticket, $user);
            }

            $validated = $request->validate([
                'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
                'assign_to_me' => ['sometimes', 'boolean'],
                'status' => ['sometimes', 'string', 'in:' . implode(',', array_keys(self::STATUS_OPTIONS))],
                'priority' => ['sometimes', 'string', 'in:' . implode(',', array_keys(self::PRIORITY_OPTIONS))],
            ]);

            DB::transaction(function () use ($ticket, $user, $validated) {
                $newAssignedTo = $ticket->assigned_to;

                if (!empty($validated['assign_to_me'])) {
                    $newAssignedTo = $user->id;
                } elseif (array_key_exists('assigned_to', $validated)) {
                    $newAssignedTo = $validated['assigned_to'] ? (int) $validated['assigned_to'] : null;
                }

                if ((int) ($ticket->assigned_to ?? 0) !== (int) ($newAssignedTo ?? 0)) {
                    $ticket->assigned_to = $newAssignedTo;
                    $ticket->assigned_at = $newAssignedTo ? now() : null;
                    $ticket->save();

                    $assignedUser = $newAssignedTo ? User::query()->find($newAssignedTo) : null;

                    $assignedMessage = $newAssignedTo
                        ? 'Ticket di-assign ke ' . ($assignedUser?->name ?? 'petugas helpdesk') . '.'
                        : 'Ticket dikembalikan ke antrian umum.';

                    $this->createTimelineUpdate(
                        $ticket,
                        $user,
                        'assigned',
                        $assignedMessage,
                        false,
                        ['assigned_to' => $newAssignedTo],
                    );
                }

                if (!empty($validated['priority']) && $validated['priority'] !== $ticket->priority) {
                    $ticket->priority = $validated['priority'];
                    $ticket->save();

                    $this->createTimelineUpdate(
                        $ticket,
                        $user,
                        'priority_changed',
                        "Prioritas ticket diubah menjadi {$this->priorityLabel($ticket->priority)}.",
                        false,
                        ['priority' => $ticket->priority],
                    );
                }

                if (!empty($validated['status']) && $validated['status'] !== $ticket->status) {
                    $this->applyStatusChange($ticket, $user, $validated['status'], "Status ticket diubah menjadi {$this->statusLabel($validated['status'])}.");
                }
            });

            $ticket = $ticket->fresh(['requester.roles', 'creator', 'assignee', 'updates.user.roles']);
            $this->notifyTicketManaged($ticket, $user);

            return response()->json([
                'success' => true,
                'ticket' => $this->transformTicketDetail($ticket, $user, $canManage),
            ]);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Update Helpdesk Ticket Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Add a public update or internal note to the ticket timeline.
     */
    public function addUpdate(Request $request, int $id)
    {
        try {
            $user = $request->user();
            $canManage = $this->canManageTickets($user);
            $ticket = HelpdeskTicket::query()
                ->with(['requester.roles', 'creator', 'assignee', 'updates.user.roles'])
                ->findOrFail($id);

            $this->authorizeTicketAccess($ticket, $user, $canManage);

            $validated = $request->validate([
                'message' => ['required', 'string', 'max:5000'],
                'is_internal' => ['sometimes', 'boolean'],
            ]);

            $isInternal = $canManage ? (bool) ($validated['is_internal'] ?? false) : false;

            DB::transaction(function () use ($ticket, $user, $validated, $isInternal, $canManage) {
                $this->createTimelineUpdate(
                    $ticket,
                    $user,
                    $isInternal ? 'internal_note' : 'comment',
                    trim((string) $validated['message']),
                    $isInternal,
                );

                if (!$canManage && $ticket->status === 'waiting_user') {
                    $this->applyStatusChange($ticket, $user, 'open', 'User memberikan respons baru, ticket dibuka kembali untuk diproses.');
                }
            });

            $ticket = $ticket->fresh(['requester.roles', 'creator', 'assignee', 'updates.user.roles']);
            $this->notifyTicketCommented($ticket, $user, $isInternal);

            return response()->json([
                'success' => true,
                'ticket' => $this->transformTicketDetail($ticket, $user, $canManage),
            ]);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Add Helpdesk Update Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function storeRules(bool $canManage): array
    {
        $rules = [
            'category' => ['required', 'string', 'in:' . implode(',', array_keys(self::CATEGORY_OPTIONS))],
            'subject' => ['sometimes', 'nullable', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:5000'],
            'attachment' => ['sometimes', 'nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp,pdf,txt'],
            'is_blocking' => ['sometimes', 'boolean'],
            'context' => ['sometimes', 'array'],
            'context.page' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];

        if ($canManage) {
            $rules['requester_id'] = ['sometimes', 'nullable', 'integer', 'exists:users,id'];
            $rules['channel'] = ['sometimes', 'string', 'in:' . implode(',', array_keys(self::CHANNEL_OPTIONS))];
            $rules['priority'] = ['sometimes', 'string', 'in:' . implode(',', array_keys(self::PRIORITY_OPTIONS))];
            $rules['status'] = ['sometimes', 'string', 'in:' . implode(',', array_keys(self::STATUS_OPTIONS))];
            $rules['assigned_to'] = ['sometimes', 'nullable', 'integer', 'exists:users,id'];
            $rules['assign_to_me'] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    private function visibleTicketsQuery(User $user, bool $canManage): Builder
    {
        $query = HelpdeskTicket::query()->with(['requester.roles', 'creator', 'assignee']);

        if (!$canManage) {
            $query->where('requester_id', $user->id);
        }

        return $query;
    }

    private function buildStats(Builder $query): array
    {
        $tickets = $query->get(['status']);

        return [
            'all' => $tickets->count(),
            'open' => $tickets->where('status', 'open')->count(),
            'in_progress' => $tickets->where('status', 'in_progress')->count(),
            'waiting_user' => $tickets->where('status', 'waiting_user')->count(),
            'resolved' => $tickets->where('status', 'resolved')->count(),
            'closed' => $tickets->where('status', 'closed')->count(),
        ];
    }

    private function activeRequesters(): array
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'department', 'employee_id', 'phone_number'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'department' => $user->department,
                'employee_id' => $user->employee_id,
                'phone_number' => $user->phone_number,
            ])
            ->values()
            ->all();
    }

    private function activeHelpdeskAssignees(): array
    {
        return User::query()
            ->with('roles')
            ->permission('manage helpdesk tickets')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'department'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'department' => $user->department,
                'roles' => $user->roles->pluck('name')->values()->all(),
            ])
            ->values()
            ->all();
    }

    private function authorizeTicketAccess(HelpdeskTicket $ticket, User $user, bool $canManage): void
    {
        if ($canManage || (int) $ticket->requester_id === (int) $user->id) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'error' => 'Anda tidak punya akses ke ticket bantuan ini.',
        ], 403));
    }

    private function handleRequesterTicketAction(Request $request, HelpdeskTicket $ticket, User $user)
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:close,reopen'],
        ]);

        DB::transaction(function () use ($ticket, $user, $validated) {
            if ($validated['action'] === 'close') {
                if ($ticket->status !== 'resolved') {
                    throw new HttpResponseException(response()->json([
                        'error' => 'Ticket hanya bisa ditutup oleh user setelah statusnya resolved.',
                    ], 422));
                }

                $this->applyStatusChange($ticket, $user, 'closed', 'User mengonfirmasi kendala sudah selesai dan menutup ticket.');
                return;
            }

            if (!in_array($ticket->status, ['resolved', 'closed'], true)) {
                throw new HttpResponseException(response()->json([
                    'error' => 'Ticket hanya bisa dibuka ulang jika sudah resolved atau closed.',
                ], 422));
            }

            $this->applyStatusChange($ticket, $user, 'open', 'User membuka kembali ticket karena kendala belum selesai.');
        });

        $ticket = $ticket->fresh(['requester.roles', 'creator', 'assignee', 'updates.user.roles']);
        $this->notifyTicketRequesterAction($ticket, $user, $validated['action']);

        return response()->json([
            'success' => true,
            'ticket' => $this->transformTicketDetail($ticket, $user, false),
        ]);
    }

    private function createTimelineUpdate(
        HelpdeskTicket $ticket,
        ?User $user,
        string $type,
        ?string $message,
        bool $isInternal = false,
        array $meta = [],
    ): HelpdeskTicketUpdate {
        $update = $ticket->updates()->create([
            'user_id' => $user?->id,
            'type' => $type,
            'message' => $message,
            'is_internal' => $isInternal,
            'meta' => $meta === [] ? null : $meta,
        ]);

        $ticket->forceFill([
            'last_activity_at' => now(),
        ])->save();

        return $update;
    }

    private function applyStatusChange(HelpdeskTicket $ticket, User $user, string $status, string $message): void
    {
        $ticket->status = $status;

        if (in_array($status, ['open', 'in_progress', 'waiting_user'], true)) {
            $ticket->resolved_at = null;
            $ticket->closed_at = null;
        }

        if ($status === 'resolved') {
            $ticket->resolved_at = now();
            $ticket->closed_at = null;
        }

        if ($status === 'closed') {
            $ticket->closed_at = now();
        }

        $ticket->save();

        $this->createTimelineUpdate(
            $ticket,
            $user,
            'status_changed',
            $message,
            false,
            ['status' => $status],
        );
    }

    private function resolveSubject(?string $subject, string $description): string
    {
        $normalizedSubject = trim((string) $subject);

        if ($normalizedSubject !== '') {
            return Str::limit($normalizedSubject, 160, '');
        }

        return Str::limit(preg_replace('/\s+/', ' ', trim($description)) ?: 'Kendala tanpa judul', 160, '');
    }

    private function buildTicketContext(Request $request, User $requester, array $validated): array
    {
        $context = array_filter([
            'page' => data_get($validated, 'context.page'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'requester' => [
                'name' => $requester->name,
                'email' => $requester->email,
                'department' => $requester->department,
                'employee_id' => $requester->employee_id,
                'phone_number' => $requester->phone_number,
            ],
            'is_blocking' => (bool) ($validated['is_blocking'] ?? false),
        ], fn ($value) => $value !== null && $value !== '');

        return $context;
    }

    private function generateTicketNumber(HelpdeskTicket $ticket): string
    {
        return 'HD-' . now()->format('Ymd') . '-' . str_pad((string) $ticket->id, 4, '0', STR_PAD_LEFT);
    }

    private function transformTicketSummary(HelpdeskTicket $ticket, User $user, bool $canManage): array
    {
        return [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'category' => $ticket->category,
            'category_label' => $this->categoryLabel($ticket->category),
            'channel' => $ticket->channel,
            'channel_label' => $this->channelLabel($ticket->channel),
            'priority' => $ticket->priority,
            'priority_label' => $this->priorityLabel($ticket->priority),
            'status' => $ticket->status,
            'status_label' => $this->statusLabel($ticket->status),
            'requester' => $ticket->requester ? [
                'id' => $ticket->requester->id,
                'name' => $ticket->requester->name,
                'email' => $ticket->requester->email,
                'department' => $ticket->requester->department,
            ] : null,
            'assignee' => $ticket->assignee ? [
                'id' => $ticket->assignee->id,
                'name' => $ticket->assignee->name,
            ] : null,
            'is_assigned_to_me' => (int) ($ticket->assigned_to ?? 0) === (int) $user->id,
            'can_assign_to_me' => $canManage && (int) ($ticket->assigned_to ?? 0) !== (int) $user->id,
            'can_manage' => $canManage,
            'can_close' => !$canManage && $ticket->status === 'resolved',
            'can_reopen' => !$canManage && in_array($ticket->status, ['resolved', 'closed'], true),
            'attachment_name' => $ticket->attachment_name,
            'created_at' => optional($ticket->created_at)?->toISOString(),
            'updated_at' => optional($ticket->updated_at)?->toISOString(),
            'last_activity_at' => optional($ticket->last_activity_at)?->toISOString(),
        ];
    }

    private function transformTicketDetail(HelpdeskTicket $ticket, User $user, bool $canManage): array
    {
        $ticket->loadMissing(['requester.roles', 'creator', 'assignee', 'updates.user.roles']);

        return [
            ...$this->transformTicketSummary($ticket, $user, $canManage),
            'context' => $ticket->context ?? [],
            'attachment_url' => $ticket->attachment_path ? Storage::disk('public')->url($ticket->attachment_path) : null,
            'assigned_at' => optional($ticket->assigned_at)?->toISOString(),
            'resolved_at' => optional($ticket->resolved_at)?->toISOString(),
            'closed_at' => optional($ticket->closed_at)?->toISOString(),
            'updates' => $ticket->updates
                ->filter(fn (HelpdeskTicketUpdate $update) => $canManage || !$update->is_internal)
                ->values()
                ->map(fn (HelpdeskTicketUpdate $update) => $this->transformUpdate($update))
                ->all(),
        ];
    }

    private function transformUpdate(HelpdeskTicketUpdate $update): array
    {
        return [
            'id' => $update->id,
            'type' => $update->type,
            'message' => $update->message,
            'is_internal' => (bool) $update->is_internal,
            'meta' => $update->meta ?? [],
            'user' => $update->user ? [
                'id' => $update->user->id,
                'name' => $update->user->name,
                'roles' => $update->user->roles->pluck('name')->values()->all(),
            ] : null,
            'created_at' => optional($update->created_at)?->toISOString(),
        ];
    }

    private function optionList(array $source): array
    {
        return collect($source)
            ->map(fn (string $label, string $value) => [
                'value' => $value,
                'label' => $label,
            ])
            ->values()
            ->all();
    }

    private function canManageTickets(User $user): bool
    {
        return $user->can('manage helpdesk tickets');
    }

    private function statusOrderSql(): string
    {
        return "CASE status
            WHEN 'open' THEN 0
            WHEN 'in_progress' THEN 1
            WHEN 'waiting_user' THEN 2
            WHEN 'resolved' THEN 3
            WHEN 'closed' THEN 4
            ELSE 5
        END";
    }

    private function categoryLabel(string $value): string
    {
        return self::CATEGORY_OPTIONS[$value] ?? Str::headline($value);
    }

    private function priorityLabel(string $value): string
    {
        return self::PRIORITY_OPTIONS[$value] ?? Str::headline($value);
    }

    private function statusLabel(string $value): string
    {
        return self::STATUS_OPTIONS[$value] ?? Str::headline($value);
    }

    private function channelLabel(string $value): string
    {
        return self::CHANNEL_OPTIONS[$value] ?? Str::headline($value);
    }

    private function notifyTicketCreated(HelpdeskTicket $ticket, User $actor, User $requester, ?int $assignedTo, string $channel): void
    {
        $link = "/helpdesk/{$ticket->id}";

        if ((int) $actor->id !== (int) $requester->id) {
            $this->createNotification(
                $requester->id,
                'Ticket bantuan berhasil dicatat',
                "Ticket {$ticket->ticket_number} sudah masuk ke helpdesk melalui {$this->channelLabel($channel)}.",
                $link,
            );
        }

        if ($assignedTo) {
            if ((int) $assignedTo !== (int) $actor->id) {
                $this->createNotification(
                    $assignedTo,
                    'Ticket bantuan baru di-assign ke Anda',
                    "Ticket {$ticket->ticket_number} perlu Anda tangani.",
                    $link,
                );
            }

            return;
        }

        if ($channel === 'portal') {
            $this->notifyHelpdeskManagers(
                'Ticket bantuan baru masuk',
                "{$requester->name} melaporkan kendala baru: {$ticket->subject}.",
                $link,
                $actor->id,
            );
        }
    }

    private function notifyTicketManaged(HelpdeskTicket $ticket, User $actor): void
    {
        $link = "/helpdesk/{$ticket->id}";

        if ((int) $ticket->requester_id !== (int) $actor->id) {
            $this->createNotification(
                $ticket->requester_id,
                'Ticket bantuan Anda diperbarui',
                "Ticket {$ticket->ticket_number} sekarang berstatus {$this->statusLabel($ticket->status)}.",
                $link,
            );
        }

        if ($ticket->assigned_to && (int) $ticket->assigned_to !== (int) $actor->id) {
            $this->createNotification(
                $ticket->assigned_to,
                'Ada perubahan pada ticket bantuan',
                "Ticket {$ticket->ticket_number} mendapatkan update terbaru.",
                $link,
            );
        }
    }

    private function notifyTicketCommented(HelpdeskTicket $ticket, User $actor, bool $isInternal): void
    {
        if ($isInternal) {
            return;
        }

        $link = "/helpdesk/{$ticket->id}";

        if ($this->canManageTickets($actor)) {
            if ((int) $ticket->requester_id !== (int) $actor->id) {
                $this->createNotification(
                    $ticket->requester_id,
                    'Ada update baru pada ticket bantuan Anda',
                    "IT memberikan pembaruan untuk ticket {$ticket->ticket_number}.",
                    $link,
                );
            }

            return;
        }

        if ($ticket->assigned_to) {
            $this->createNotification(
                $ticket->assigned_to,
                'Requester membalas ticket bantuan',
                "{$actor->name} memberikan respons baru di ticket {$ticket->ticket_number}.",
                $link,
            );

            return;
        }

        $this->notifyHelpdeskManagers(
            'Ada balasan baru di ticket bantuan',
            "{$actor->name} memberikan pembaruan di ticket {$ticket->ticket_number}.",
            $link,
            $actor->id,
        );
    }

    private function notifyTicketRequesterAction(HelpdeskTicket $ticket, User $actor, string $action): void
    {
        $message = $action === 'close'
            ? "{$actor->name} menutup ticket {$ticket->ticket_number} karena kendala sudah selesai."
            : "{$actor->name} membuka kembali ticket {$ticket->ticket_number}.";

        if ($ticket->assigned_to) {
            $this->createNotification(
                $ticket->assigned_to,
                $action === 'close' ? 'Ticket bantuan ditutup requester' : 'Ticket bantuan dibuka kembali',
                $message,
                "/helpdesk/{$ticket->id}",
            );

            return;
        }

        $this->notifyHelpdeskManagers(
            $action === 'close' ? 'Ticket bantuan selesai' : 'Ticket bantuan dibuka kembali',
            $message,
            "/helpdesk/{$ticket->id}",
            $actor->id,
        );
    }

    private function notifyHelpdeskManagers(string $title, string $message, string $link, ?int $exceptUserId = null): void
    {
        User::query()
            ->permission('manage helpdesk tickets')
            ->where('is_active', true)
            ->when($exceptUserId, fn (Builder $query) => $query->where('id', '!=', $exceptUserId))
            ->get()
            ->each(function (User $user) use ($title, $message, $link) {
                $this->createNotification($user->id, $title, $message, $link);
            });
    }

    private function createNotification(int $userId, string $title, string $message, string $link): void
    {
        Notification::query()->create([
            'user_id' => $userId,
            'title' => $title,
            'message' => $message,
            'type' => 'general',
            'link' => $link,
            'is_read' => false,
        ]);
    }
}
