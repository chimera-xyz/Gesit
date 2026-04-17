<?php

namespace App\Support;

use App\Models\HelpdeskTicket;
use App\Models\HelpdeskTicketUpdate;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HelpdeskTicketService
{
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

    private const ACTIVE_STATUSES = [
        'open',
        'in_progress',
        'waiting_user',
    ];

    private const PRIORITY_RANK = [
        'low' => 1,
        'normal' => 2,
        'high' => 3,
        'critical' => 4,
    ];

    public function createTicket(User $actor, User $requester, array $attributes): HelpdeskTicket
    {
        $assignedTo = $this->normalizeAssignedTo($attributes['assigned_to'] ?? null);
        $channel = $this->normalizeChannel((string) ($attributes['channel'] ?? 'portal'));
        $priority = $this->normalizePriority((string) ($attributes['priority'] ?? 'normal'));
        $status = $this->normalizeStatus($attributes['status'] ?? null, $assignedTo);
        $context = $this->normalizeContext($attributes['context'] ?? []);
        $attachmentPath = $this->nullableString($attributes['attachment_path'] ?? null);
        $attachmentName = $this->nullableString($attributes['attachment_name'] ?? null);

        $ticket = DB::transaction(function () use (
            $actor,
            $requester,
            $assignedTo,
            $attachmentName,
            $attachmentPath,
            $attributes,
            $channel,
            $context,
            $priority,
            $status
        ) {
            $ticket = HelpdeskTicket::query()->create([
                'requester_id' => $requester->id,
                'created_by' => $actor->id,
                'assigned_to' => $assignedTo,
                'subject' => $this->resolveSubject(
                    $this->nullableString($attributes['subject'] ?? null),
                    (string) ($attributes['description'] ?? '')
                ),
                'description' => trim((string) ($attributes['description'] ?? '')),
                'category' => (string) ($attributes['category'] ?? 'other'),
                'channel' => $channel,
                'priority' => $priority,
                'status' => $status,
                'attachment_path' => $attachmentPath,
                'attachment_name' => $attachmentName,
                'assigned_at' => $assignedTo ? now() : null,
                'resolved_at' => $status === 'resolved' ? now() : null,
                'closed_at' => $status === 'closed' ? now() : null,
                'last_activity_at' => now(),
                'context' => $context,
            ]);

            $ticket->ticket_number = $this->generateTicketNumber($ticket);
            $ticket->save();

            $this->createTimelineUpdate(
                $ticket,
                $actor,
                'created',
                $channel === 'phone'
                    ? 'Ticket bantuan dibuat dari log panggilan.'
                    : 'Ticket bantuan dibuat dari portal helpdesk.',
                false,
                [
                    'channel' => $channel,
                    'priority' => $priority,
                ],
            );

            if ($assignedTo) {
                $assignedUser = User::query()->find($assignedTo);

                $this->createTimelineUpdate(
                    $ticket,
                    $actor,
                    'assigned',
                    'Ticket di-assign ke '.($assignedUser?->name ?? 'petugas helpdesk').'.',
                    false,
                    [
                        'assigned_to' => $assignedTo,
                    ],
                );
            }

            if ($status !== 'open') {
                $this->createTimelineUpdate(
                    $ticket,
                    $actor,
                    'status_changed',
                    "Status ticket diubah menjadi {$this->statusLabel($status)}.",
                    false,
                    [
                        'status' => $status,
                    ],
                );
            }

            return $ticket->fresh([
                'requester.roles',
                'creator',
                'assignee',
                'updates.user.roles',
            ]);
        });

        $this->notifyTicketCreated($ticket, $actor, $requester, $assignedTo, $channel);

        return $ticket;
    }

    public function addPublicUpdate(HelpdeskTicket $ticket, User $actor, string $message, array $meta = []): HelpdeskTicket
    {
        DB::transaction(function () use ($actor, $message, $meta, $ticket) {
            $this->createTimelineUpdate(
                $ticket,
                $actor,
                'comment',
                trim($message),
                false,
                $meta,
            );
        });

        $ticket = $ticket->fresh(['requester.roles', 'creator', 'assignee', 'updates.user.roles']);
        $this->notifyTicketCommented($ticket, $actor, false);

        return $ticket;
    }

    public function mergeContext(HelpdeskTicket $ticket, array $context): HelpdeskTicket
    {
        $ticket->context = $this->mergeContextValues($ticket->context ?? [], $this->normalizeContext($context));
        $ticket->save();

        return $ticket;
    }

    public function ensurePriorityAtLeast(HelpdeskTicket $ticket, User $actor, string $priority): HelpdeskTicket
    {
        $normalizedPriority = $this->normalizePriority($priority);
        $currentRank = self::PRIORITY_RANK[$ticket->priority] ?? self::PRIORITY_RANK['normal'];
        $nextRank = self::PRIORITY_RANK[$normalizedPriority] ?? self::PRIORITY_RANK['normal'];

        if ($nextRank <= $currentRank) {
            return $ticket;
        }

        $ticket->priority = $normalizedPriority;
        $ticket->save();

        $this->createTimelineUpdate(
            $ticket,
            $actor,
            'priority_changed',
            "Prioritas ticket diubah menjadi {$this->priorityLabel($ticket->priority)}.",
            false,
            ['priority' => $ticket->priority],
        );

        return $ticket;
    }

    public function activeStatuses(): array
    {
        return self::ACTIVE_STATUSES;
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

    private function notifyTicketCommented(HelpdeskTicket $ticket, User $actor, bool $isInternal): void
    {
        if ($isInternal) {
            return;
        }

        $link = "/helpdesk/{$ticket->id}";

        if ($actor->can('manage helpdesk tickets')) {
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

    private function notifyHelpdeskManagers(string $title, string $message, string $link, ?int $exceptUserId = null): void
    {
        User::query()
            ->permission('manage helpdesk tickets')
            ->where('is_active', true)
            ->when($exceptUserId, fn (Builder $query) => $query->where('id', '!=', $exceptUserId))
            ->get()
            ->each(function (User $user) use ($link, $message, $title) {
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

    private function resolveSubject(?string $subject, string $description): string
    {
        $normalizedSubject = trim((string) $subject);

        if ($normalizedSubject !== '') {
            return Str::limit($normalizedSubject, 160, '');
        }

        return Str::limit(preg_replace('/\s+/', ' ', trim($description)) ?: 'Kendala tanpa judul', 160, '');
    }

    private function generateTicketNumber(HelpdeskTicket $ticket): string
    {
        return 'HD-'.now()->format('Ymd').'-'.str_pad((string) $ticket->id, 4, '0', STR_PAD_LEFT);
    }

    private function mergeContextValues(array $current, array $incoming): array
    {
        if ($incoming === []) {
            return $current;
        }

        return array_replace_recursive($current, $incoming);
    }

    private function normalizeAssignedTo(mixed $value): ?int
    {
        $assignedTo = (int) $value;

        return $assignedTo > 0 ? $assignedTo : null;
    }

    private function normalizeChannel(string $value): string
    {
        return array_key_exists($value, self::CHANNEL_OPTIONS) ? $value : 'portal';
    }

    private function normalizePriority(string $value): string
    {
        return array_key_exists($value, self::PRIORITY_OPTIONS) ? $value : 'normal';
    }

    private function normalizeStatus(mixed $value, ?int $assignedTo): string
    {
        $status = is_string($value) && array_key_exists($value, self::STATUS_OPTIONS)
            ? $value
            : 'open';

        if ($status === 'open' && $assignedTo) {
            return 'in_progress';
        }

        return $status;
    }

    private function normalizeContext(mixed $context): array
    {
        if (! is_array($context)) {
            return [];
        }

        return $context;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
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
}
