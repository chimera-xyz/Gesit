<?php

namespace App\Support;

use App\Models\HelpdeskTicket;
use App\Models\KnowledgeConversation;
use App\Models\KnowledgeConversationMessage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class S21PlusHelpdeskEscalationService
{
    private const CATEGORY = 'account_access';

    private const SOURCE = 'ai_s21plus';

    private const ISSUE_TYPE = 's21plus_access';

    private const ACTION_LABELS = [
        'Buka blokir sekarang',
        'Hubungi IT manual',
        'Buat ticket ke Tim IT',
    ];

    public function __construct(
        private readonly HelpdeskTicketService $helpdeskTicketService,
    ) {
    }

    public function escalate(
        User $user,
        KnowledgeConversation $conversation,
        array $result,
        array $options = [],
    ): array {
        $conversationMessages = $this->conversationMessages($conversation);
        $relevantUserMessages = $this->relevantUserMessages(
            $conversationMessages,
            (array) ($options['exclude_user_message_ids'] ?? [])
        );
        $draft = $this->composeTicketDraft($user, $relevantUserMessages, $result, $options);
        $priority = $this->resolvePriority($relevantUserMessages, $result);
        $context = $this->buildContext($conversation, $relevantUserMessages, $result, $draft, $priority, $options);
        $ticket = $this->findReusableTicket($user, (string) ($result['s21plus_user_id'] ?? ''));

        if ($ticket) {
            $ticket = $this->helpdeskTicketService->mergeContext($ticket, $context);
            $ticket = $this->helpdeskTicketService->ensurePriorityAtLeast($ticket, $user, $priority);
            $ticket = $this->helpdeskTicketService->addPublicUpdate(
                $ticket,
                $user,
                $draft['update_message'],
                [
                    'source' => self::SOURCE,
                    'issue_type' => self::ISSUE_TYPE,
                    'conversation_id' => $conversation->id,
                    'result_code' => $result['result_code'] ?? null,
                    'escalation_reason' => $options['escalation_reason'] ?? null,
                ],
            );

            return [
                'ticket' => $ticket,
                'ticket_created' => false,
                'ticket_reused' => true,
                'draft' => $draft,
            ];
        }

        $ticket = $this->helpdeskTicketService->createTicket($user, $user, [
            'category' => self::CATEGORY,
            'subject' => $draft['subject'],
            'description' => $draft['description'],
            'channel' => 'portal',
            'priority' => $priority,
            'context' => $context,
        ]);

        return [
            'ticket' => $ticket,
            'ticket_created' => true,
            'ticket_reused' => false,
            'draft' => $draft,
        ];
    }

    private function conversationMessages(KnowledgeConversation $conversation): Collection
    {
        return KnowledgeConversationMessage::query()
            ->where('knowledge_conversation_id', $conversation->id)
            ->orderBy('id')
            ->get();
    }

    private function relevantUserMessages(Collection $messages, array $excludeMessageIds = []): Collection
    {
        return $messages
            ->filter(fn (KnowledgeConversationMessage $message) => $message->role === 'user')
            ->reject(function (KnowledgeConversationMessage $message) use ($excludeMessageIds) {
                if (in_array((int) $message->id, $excludeMessageIds, true)) {
                    return true;
                }

                $content = trim((string) $message->content);

                return $content === '' || in_array($content, self::ACTION_LABELS, true);
            })
            ->take(-6)
            ->values();
    }

    private function composeTicketDraft(
        User $user,
        Collection $messages,
        array $result,
        array $options = [],
    ): array {
        $quotes = $messages
            ->pluck('content')
            ->map(fn ($content) => trim((string) $content))
            ->filter()
            ->take(-3)
            ->values()
            ->all();

        $issueSummary = $this->issueSummary($messages, $result);
        $subject = $this->composeSubject($user, $issueSummary, $result);
        $stateSummary = $this->stateSummary($result);
        $reasonSummary = $this->reasonSummary($result, (string) ($options['escalation_reason'] ?? 'manual_request'));

        $descriptionParts = [];
        $descriptionParts[] = sprintf(
            '%s melaporkan bahwa %s.',
            $user->name,
            $this->lowercaseFirst($issueSummary)
        );

        if ($quotes !== []) {
            $quoteText = collect($quotes)
                ->map(fn (string $quote) => "\"{$quote}\"")
                ->implode(', ');

            $descriptionParts[] = 'Percakapan terakhir yang perlu diperhatikan: '.$quoteText.'.';
        }

        $descriptionParts[] = 'Hasil pengecekan S21Plus terbaru: '.$stateSummary.'.';
        $descriptionParts[] = $reasonSummary.'. Mohon tim IT menindaklanjuti akses akun S21Plus user sampai bisa digunakan kembali.';

        $updateMessageParts = [
            'AI menerima eskalasi lanjutan untuk kendala S21Plus ini.',
            'Keluhan terbaru mengarah ke '.$this->lowercaseFirst($issueSummary).'.',
            'Pengecekan paling baru menunjukkan '.$this->lowercaseFirst($stateSummary).'.',
        ];

        return [
            'subject' => Str::limit($subject, 160, ''),
            'description' => implode("\n\n", $descriptionParts),
            'update_message' => implode(' ', $updateMessageParts),
            'issue_summary' => $issueSummary,
        ];
    }

    private function issueSummary(Collection $messages, array $result): string
    {
        $combined = $messages
            ->pluck('content')
            ->map(fn ($content) => $this->normalizeText((string) $content))
            ->filter()
            ->implode(' ');

        $hasLoginIssue = $this->containsAny($combined, [
            'gabisa login', 'ga bisa login', 'gak bisa login', 'tidak bisa login',
            'gagal login', 'login gagal', 'gabisa masuk', 'ga bisa masuk', 'tidak bisa masuk',
            'gabisa akses', 'ga bisa akses', 'tidak bisa akses', 'gabisa kebuka', 'ga bisa kebuka',
            'gak bisa kebuka', 'tidak bisa kebuka',
        ]);
        $hasBlockedSignal = $this->containsAny($combined, [
            'keblok', 'keblokir', 'terblokir', 'ter block', 'terblock', 'unblock', 'unlock',
        ]);
        $hasOpenSignal = $this->containsAny($combined, [
            'buka', 'kebuka', 'akses',
        ]);

        return match ($result['result_code'] ?? null) {
            'mapping_missing' => 'mapping UserID S21Plus di profil GESIT belum tersedia',
            'account_not_found' => 'akun S21Plus yang terhubung ke profil user tidak ditemukan saat pengecekan',
            'service_unavailable' => 'pengecekan akses S21Plus tidak bisa dituntaskan karena koneksi ke sistem sedang bermasalah',
            'blocked_unexpected_state' => $hasLoginIssue
                ? 'user tidak bisa login ke S21Plus tetapi status akun tidak memenuhi kriteria self-service unblock'
                : 'status akun S21Plus berada di luar kriteria self-service unblock',
            'blocked_confirmed' => $hasLoginIssue
                ? 'user tidak bisa login ke S21Plus karena akun terdeteksi terblokir'
                : 'akun S21Plus user terdeteksi terblokir dan memerlukan tindak lanjut',
            'account_active' => $hasLoginIssue || $hasOpenSignal
                ? 'user masih tidak bisa mengakses S21Plus meskipun status akun terdeteksi aktif'
                : 'user memerlukan pengecekan akses S21Plus meskipun status akun terdeteksi aktif',
            'unlock_failed', 'verification_failed' => $hasLoginIssue
                ? 'proses unblock S21Plus tidak berhasil dituntaskan otomatis sehingga user tetap tidak bisa login'
                : 'proses unblock S21Plus tidak berhasil dituntaskan otomatis',
            default => $hasBlockedSignal && $hasLoginIssue
                ? 'user tidak bisa login ke S21Plus dan akun diduga terblokir'
                : ($hasLoginIssue
                    ? 'user tidak bisa login ke S21Plus'
                    : 'user membutuhkan bantuan akses akun S21Plus'),
        };
    }

    private function composeSubject(User $user, string $issueSummary, array $result): string
    {
        $resultCode = (string) ($result['result_code'] ?? '');
        $normalizedSummary = Str::lower($issueSummary);

        if ($resultCode === 'mapping_missing') {
            return 'Mapping UserID S21Plus belum tersedia di profil GESIT';
        }

        if ($resultCode === 'account_not_found') {
            return 'Akun S21Plus terhubung tidak ditemukan saat pengecekan akses';
        }

        if ($resultCode === 'service_unavailable') {
            return 'Pengecekan akses S21Plus terhambat karena koneksi sistem';
        }

        if ($resultCode === 'account_active') {
            return 'S21Plus masih tidak bisa diakses meski status akun aktif';
        }

        if ($resultCode === 'unlock_failed' || $resultCode === 'verification_failed') {
            return 'Self-service unblock S21Plus gagal dituntaskan';
        }

        if (Str::contains($normalizedSummary, 'terblokir')) {
            return 'S21Plus tidak bisa login karena akun terblokir';
        }

        if (Str::contains($normalizedSummary, 'tidak bisa login')) {
            return 'S21Plus tidak bisa login dan perlu pengecekan IT';
        }

        return 'Bantuan akses akun S21Plus untuk '.$user->name;
    }

    private function stateSummary(array $result): string
    {
        $segments = [];
        $before = is_array($result['before'] ?? null) ? $result['before'] : [];
        $after = is_array($result['after'] ?? null) ? $result['after'] : [];

        if (array_key_exists('is_enabled', $after) && $after['is_enabled'] !== null) {
            $segments[] = 'IsEnabled = '.((bool) $after['is_enabled'] ? '1' : '0');
        }

        if (array_key_exists('login_retry', $after) && $after['login_retry'] !== null) {
            $segments[] = 'LoginRetry = '.(int) $after['login_retry'];
        }

        if ($segments === [] && array_key_exists('is_enabled', $before) && $before['is_enabled'] !== null) {
            $segments[] = 'IsEnabled = '.((bool) $before['is_enabled'] ? '1' : '0');
        }

        if ($segments === [] && array_key_exists('login_retry', $before) && $before['login_retry'] !== null) {
            $segments[] = 'LoginRetry = '.(int) $before['login_retry'];
        }

        $resultMessage = trim((string) ($result['message'] ?? ''));

        if ($segments === []) {
            return $resultMessage !== '' ? $resultMessage : 'detail status realtime belum tersedia';
        }

        $stateText = implode(', ', $segments);

        if ($resultMessage === '') {
            return $stateText;
        }

        return $stateText.'; '.$resultMessage;
    }

    private function reasonSummary(array $result, string $reason): string
    {
        return match ($reason) {
            'manual_request' => 'User memilih diteruskan ke Tim IT dari AI chat',
            'unlock_failed' => 'Self-service unblock tidak berhasil sehingga kasus otomatis dieskalasikan ke helpdesk',
            default => 'Kasus ini tidak bisa dituntaskan penuh melalui self-service sehingga perlu penanganan manual Tim IT',
        };
    }

    private function resolvePriority(Collection $messages, array $result): string
    {
        $combined = $messages
            ->pluck('content')
            ->map(fn ($content) => $this->normalizeText((string) $content))
            ->filter()
            ->implode(' ');

        $hasCriticalSignal = $this->containsAny($combined, [
            'tidak bisa kerja', 'ga bisa kerja', 'gak bisa kerja', 'nggak bisa kerja',
            'urgent', 'mendesak', 'segera', 'harus sekarang',
        ]);

        if ($hasCriticalSignal) {
            return 'critical';
        }

        return match ($result['result_code'] ?? null) {
            'blocked_confirmed',
            'blocked_unexpected_state',
            'service_unavailable',
            'unlock_failed',
            'verification_failed',
            'account_not_found',
            'mapping_missing',
            'account_active' => 'high',
            default => 'normal',
        };
    }

    private function buildContext(
        KnowledgeConversation $conversation,
        Collection $messages,
        array $result,
        array $draft,
        string $priority,
        array $options = [],
    ): array {
        $snippets = $messages
            ->pluck('content')
            ->map(fn ($content) => trim((string) $content))
            ->filter()
            ->take(-4)
            ->values()
            ->all();

        return [
            'page' => '/knowledge-hub',
            'source' => self::SOURCE,
            'source_label' => 'AI Chat S21Plus',
            'issue_type' => self::ISSUE_TYPE,
            'is_blocking' => in_array($priority, ['high', 'critical'], true),
            'conversation_id' => $conversation->id,
            'conversation_title' => $conversation->title,
            'conversation_message_id' => $options['conversation_message_id'] ?? null,
            'trigger_action_key' => $options['trigger_action_key'] ?? null,
            'escalation_reason' => $options['escalation_reason'] ?? null,
            'requester' => [
                'name' => $conversation->user?->name,
                'email' => $conversation->user?->email,
                'department' => $conversation->user?->department,
                'employee_id' => $conversation->user?->employee_id,
                'phone_number' => $conversation->user?->phone_number,
            ],
            's21plus_user_id' => $result['s21plus_user_id'] ?? null,
            'audit_log_id' => $result['audit_log_id'] ?? null,
            's21plus_result' => [
                'request_type' => $result['request_type'] ?? null,
                'status' => $result['status'] ?? null,
                'result_code' => $result['result_code'] ?? null,
                'message' => $result['message'] ?? null,
                'before' => $result['before'] ?? [],
                'after' => $result['after'] ?? [],
            ],
            'conversation_snippets' => $snippets,
            'ai_summary' => [
                'issue_summary' => $draft['issue_summary'] ?? null,
                'generated_subject' => $draft['subject'] ?? null,
            ],
        ];
    }

    private function findReusableTicket(User $user, string $s21plusUserId): ?HelpdeskTicket
    {
        return HelpdeskTicket::query()
            ->with(['requester.roles', 'creator', 'assignee', 'updates.user.roles'])
            ->where('requester_id', $user->id)
            ->where('category', self::CATEGORY)
            ->whereIn('status', $this->helpdeskTicketService->activeStatuses())
            ->orderByDesc('last_activity_at')
            ->get()
            ->first(function (HelpdeskTicket $ticket) use ($s21plusUserId) {
                $context = is_array($ticket->context) ? $ticket->context : [];

                if (($context['source'] ?? null) !== self::SOURCE || ($context['issue_type'] ?? null) !== self::ISSUE_TYPE) {
                    return false;
                }

                $ticketUserId = trim((string) ($context['s21plus_user_id'] ?? ''));

                if ($ticketUserId === '' || $s21plusUserId === '') {
                    return true;
                }

                return Str::lower($ticketUserId) === Str::lower(trim($s21plusUserId));
            });
    }

    private function normalizeText(string $value): string
    {
        $normalized = Str::lower(Str::ascii($value));
        $normalized = preg_replace('/[^a-z0-9\+\-]+/u', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);
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

    private function lowercaseFirst(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return $trimmed;
        }

        return Str::lcfirst($trimmed);
    }
}
