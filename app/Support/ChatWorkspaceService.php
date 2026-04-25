<?php

namespace App\Support;

use App\Models\ChatCallParticipant;
use App\Models\ChatCallSession;
use App\Models\ChatConversation;
use App\Models\ChatConversationParticipant;
use App\Models\ChatConversationUserState;
use App\Models\ChatMessage;
use App\Models\ChatMessageAttachment;
use App\Models\ChatUserEvent;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ChatWorkspaceService
{
    private const DEFAULT_GROUPS = [
        [
            'slug' => 'approval-board',
            'title' => 'Approval Board',
            'subtitle' => 'Operasional lintas divisi',
            'accent_color' => 0xFF9B6B17,
        ],
        [
            'slug' => 'finance-ops',
            'title' => 'Finance Ops',
            'subtitle' => 'Koordinasi finance dan operasional',
            'accent_color' => 0xFF315EA8,
        ],
        [
            'slug' => 'it-command',
            'title' => 'IT Command',
            'subtitle' => 'Helpdesk dan incident response',
            'accent_color' => 0xFF0F9F72,
        ],
    ];

    private const USER_ACCENT_PALETTE = [
        0xFF9B6B17,
        0xFF315EA8,
        0xFF0F9F72,
        0xFFB91C1C,
        0xFFB7791F,
    ];

    private ?bool $sessionsTableAvailable = null;
    private ?array $onlineUserIdsCache = null;

    public function workspace(User $user, array $options = []): array
    {
        $includeMessages = (bool) ($options['include_messages'] ?? true);
        $includeDirectoryMembers = (bool) ($options['include_directory_members'] ?? true);
        $messageConversationIds = $this->normalizeConversationIds($options['message_conversation_ids'] ?? null);

        $this->ensureWorkspaceProvisioned($user);
        $this->expireStaleCalls();

        $conversationIds = ChatConversationParticipant::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->pluck('conversation_id');

        $conversations = ChatConversation::query()
            ->whereIn('id', $conversationIds)
            ->with([
                'participants.user.roles',
                'states',
                'latestMessage' => fn ($query) => $query->with(['sender.roles', 'attachment']),
                'messages' => function ($query) use ($includeMessages, $messageConversationIds) {
                    if (! $includeMessages) {
                        $query->whereRaw('1 = 0');

                        return;
                    }

                    if ($messageConversationIds !== null) {
                        $query->whereIn('conversation_id', $messageConversationIds);
                    }

                    $query
                        ->with(['sender.roles', 'attachment'])
                        ->orderBy('created_at');
                },
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->get();

        $directoryMembers = [];
        if ($includeDirectoryMembers) {
            $directoryMembers = User::query()
                ->where('is_active', true)
                ->whereKeyNot($user->id)
                ->with('roles')
                ->orderBy('name')
                ->get()
                ->map(fn (User $member) => $this->serializeDirectoryMember($user, $member))
                ->values()
                ->all();
        }

        $activeCall = $this->activeCallForUser($user);
        $messagesByConversation = [];
        $membersByConversation = [];
        $assetsByConversation = [];

        foreach ($conversations as $conversation) {
            if (! $includeMessages) {
                continue;
            }

            if ($messageConversationIds !== null && ! in_array((int) $conversation->id, $messageConversationIds, true)) {
                continue;
            }

            $messagesByConversation[(string) $conversation->id] = $conversation->messages
                ->map(fn (ChatMessage $message) => $this->serializeMessage($user, $conversation, $message))
                ->values()
                ->all();

            $membersByConversation[(string) $conversation->id] = $conversation->participants
                ->filter(fn (ChatConversationParticipant $participant) => $participant->user !== null)
                ->map(fn (ChatConversationParticipant $participant) => $this->serializeConversationMember($user, $participant->user))
                ->values()
                ->all();

            $assetsByConversation[(string) $conversation->id] = $conversation->messages
                ->filter(fn (ChatMessage $message) => $message->attachment !== null)
                ->sortByDesc('created_at')
                ->map(fn (ChatMessage $message) => $this->serializeAttachmentAsset($conversation, $message, $message->attachment))
                ->values()
                ->all();
        }

        return [
            'conversations' => $conversations
                ->map(fn (ChatConversation $conversation) => $this->serializeConversation($user, $conversation))
                ->values()
                ->all(),
            'messages_by_conversation' => $messagesByConversation,
            'members_by_conversation' => $membersByConversation,
            'assets_by_conversation' => $assetsByConversation,
            'directory_members' => $directoryMembers,
            'active_call' => $activeCall ? $this->serializeCall($user, $activeCall) : null,
            'last_event_id' => $this->maxEventIdForUser($user),
        ];
    }

    public function ensureDirectConversation(User $actor, User $participant): ChatConversation
    {
        if ((int) $actor->id === (int) $participant->id) {
            throw new ChatWorkspaceException('Tidak bisa memulai chat dengan akun sendiri.');
        }

        if (! $participant->is_active) {
            throw new ChatWorkspaceException('User tujuan sudah tidak aktif.');
        }

        $directHash = $this->directHashFor([$actor->id, $participant->id]);

        return DB::transaction(function () use ($actor, $participant, $directHash) {
            $conversation = ChatConversation::query()->firstOrCreate(
                ['direct_hash' => $directHash],
                [
                    'type' => 'direct',
                    'accent_color' => $this->accentColorForConversation($directHash),
                    'created_by' => $actor->id,
                ],
            );

            $this->ensureParticipant($conversation, $actor);
            $this->ensureParticipant($conversation, $participant);
            $this->ensureState($conversation, $actor);
            $this->ensureState($conversation, $participant);
            $conversation->touch();
            $this->emitConversationEvent($conversation, 'conversation.synced');

            return $conversation->fresh();
        });
    }

    public function sendMessage(
        User $actor,
        ChatConversation $conversation,
        string $kind,
        ?string $text,
        ?string $clientToken = null,
        array $metadata = [],
    ): ChatMessage {
        $this->assertConversationParticipant($conversation, $actor);

        if ($kind === 'text' && trim((string) $text) === '') {
            throw new ChatWorkspaceException('Pesan tidak boleh kosong.');
        }

        return DB::transaction(function () use ($actor, $conversation, $kind, $text, $clientToken, $metadata) {
            [$message, $messageWasCreated] = $this->createOrReuseMessage(
                $actor,
                $conversation,
                $kind,
                $text,
                $clientToken,
                $metadata,
            );

            $this->touchConversation($conversation, $message->created_at);
            $this->markConversationRead($actor, $conversation, $message);
            $this->emitConversationEvent($conversation, 'message.created', $message->id);

            if ($messageWasCreated) {
                $this->dispatchMessageNotifications($actor, $conversation, $message);
            }

            return $message->fresh(['sender.roles', 'attachment']);
        });
    }

    public function sendAttachment(
        User $actor,
        ChatConversation $conversation,
        UploadedFile $attachment,
        ?string $caption = null,
        ?string $clientToken = null,
        string $kind = 'attachment',
        array $metadata = [],
    ): ChatMessage {
        $this->assertConversationParticipant($conversation, $actor);

        return DB::transaction(function () use ($actor, $conversation, $attachment, $caption, $clientToken, $kind, $metadata) {
            [$message] = $this->createOrReuseMessage(
                $actor,
                $conversation,
                $kind,
                $caption,
                $clientToken,
                $metadata,
            );

            $attachmentWasCreated = false;
            if ($message->attachment === null) {
                $path = $attachment->store('chat-attachments', 'public');

                ChatMessageAttachment::query()->create([
                    'message_id' => $message->id,
                    'disk' => 'public',
                    'path' => $path,
                    'original_name' => $attachment->getClientOriginalName(),
                    'mime_type' => $attachment->getClientMimeType(),
                    'size_bytes' => $attachment->getSize(),
                ]);

                $attachmentWasCreated = true;
            }

            $this->touchConversation($conversation, $message->created_at);
            $this->markConversationRead($actor, $conversation, $message);
            $this->emitConversationEvent($conversation, 'message.created', $message->id);
            $this->emitConversationEvent($conversation, 'message.attachment_created', $message->id);

            if ($attachmentWasCreated) {
                $this->dispatchMessageNotifications($actor, $conversation, $message->fresh(['sender.roles', 'attachment']));
            }

            return $message->fresh(['sender.roles', 'attachment']);
        });
    }

    public function markConversationRead(User $user, ChatConversation $conversation, ?ChatMessage $targetMessage = null): void
    {
        $this->assertConversationParticipant($conversation, $user);

        DB::transaction(function () use ($user, $conversation, $targetMessage) {
            $latestMessage = $targetMessage ?? ChatMessage::query()
                ->where('conversation_id', $conversation->id)
                ->latest('created_at')
                ->latest('id')
                ->first();

            $state = $this->ensureState($conversation, $user);
            $readAt = $latestMessage?->created_at ?? now();
            $nextMessageId = $latestMessage?->id;

            $changed = $state->last_read_message_id !== $nextMessageId
                || $state->last_read_at?->ne($readAt);

            $state->forceFill([
                'last_read_message_id' => $nextMessageId,
                'last_read_at' => $readAt,
            ])->save();

            if ($changed) {
                $this->emitConversationEvent($conversation, 'conversation.read', $nextMessageId);
            }
        });
    }

    public function updateConversationPreferences(
        User $user,
        ChatConversation $conversation,
        array $attributes,
    ): ChatConversationUserState {
        $this->assertConversationParticipant($conversation, $user);

        return DB::transaction(function () use ($user, $conversation, $attributes) {
            $state = $this->ensureState($conversation, $user);
            $state->fill([
                'is_pinned' => array_key_exists('is_pinned', $attributes)
                    ? (bool) $attributes['is_pinned']
                    : $state->is_pinned,
                'is_muted' => array_key_exists('is_muted', $attributes)
                    ? (bool) $attributes['is_muted']
                    : $state->is_muted,
            ]);
            $state->save();

            ChatUserEvent::query()->create([
                'user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'event_type' => 'conversation.preferences_updated',
            ]);

            return $state;
        });
    }

    public function startCall(User $actor, ChatConversation $conversation, string $type): ChatCallSession
    {
        $this->assertConversationParticipant($conversation, $actor);
        $this->expireStaleCalls();

        if (! in_array($type, ['voice', 'video'], true)) {
            throw new ChatWorkspaceException('Tipe panggilan tidak valid.');
        }

        $hasOpenCall = ChatCallSession::query()
            ->whereIn('status', ['ringing', 'active'])
            ->whereHas('participants', fn ($query) => $query->where('user_id', $actor->id))
            ->exists();

        if ($hasOpenCall) {
            throw new ChatWorkspaceException('Masih ada panggilan aktif yang belum selesai.', 409);
        }

        return DB::transaction(function () use ($actor, $conversation, $type) {
            $call = ChatCallSession::query()->create([
                'conversation_id' => $conversation->id,
                'initiated_by' => $actor->id,
                'type' => $type,
                'status' => 'ringing',
                'last_activity_at' => now(),
            ]);

            $participantIds = $conversation->participants()
                ->where('is_active', true)
                ->pluck('user_id');

            foreach ($participantIds as $participantId) {
                ChatCallParticipant::query()->create([
                    'call_session_id' => $call->id,
                    'user_id' => $participantId,
                    'state' => (int) $participantId === (int) $actor->id ? 'accepted' : 'invited',
                    'acted_at' => (int) $participantId === (int) $actor->id ? now() : null,
                ]);
            }

            $this->emitConversationEvent($conversation, 'call.started', null, $call->id);
            $this->dispatchIncomingCallNotifications($actor, $conversation, $call);

            return $call->fresh([
                'conversation.participants.user.roles',
                'participants.user.roles',
                'initiator.roles',
                'answerer.roles',
            ]);
        });
    }

    public function acceptCall(User $actor, ChatCallSession $call): ChatCallSession
    {
        $this->assertCallParticipant($call, $actor);
        $this->expireStaleCalls();

        if ($call->status !== 'ringing') {
            throw new ChatWorkspaceException('Panggilan ini sudah tidak menunggu jawaban.');
        }

        return DB::transaction(function () use ($actor, $call) {
            $participant = ChatCallParticipant::query()
                ->where('call_session_id', $call->id)
                ->where('user_id', $actor->id)
                ->firstOrFail();

            $participant->forceFill([
                'state' => 'accepted',
                'acted_at' => now(),
            ])->save();

            $call->forceFill([
                'status' => 'active',
                'answered_by' => $actor->id,
                'started_at' => $call->started_at ?? now(),
                'last_activity_at' => now(),
            ])->save();

            $this->emitConversationEvent($call->conversation, 'call.accepted', null, $call->id);

            return $call->fresh([
                'conversation.participants.user.roles',
                'participants.user.roles',
                'initiator.roles',
                'answerer.roles',
            ]);
        });
    }

    public function declineCall(User $actor, ChatCallSession $call): void
    {
        $this->assertCallParticipant($call, $actor);
        $this->expireStaleCalls();

        if (! in_array($call->status, ['ringing', 'active'], true)) {
            return;
        }

        DB::transaction(function () use ($actor, $call) {
            if ($call->status === 'active') {
                $this->finishCall($call, 'ended');

                return;
            }

            ChatCallParticipant::query()
                ->where('call_session_id', $call->id)
                ->where('user_id', $actor->id)
                ->update([
                    'state' => 'declined',
                    'acted_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->finishCall($call, 'declined');
        });
    }

    public function endCall(User $actor, ChatCallSession $call): void
    {
        $this->assertCallParticipant($call, $actor);
        $this->expireStaleCalls();

        if (! in_array($call->status, ['ringing', 'active'], true)) {
            return;
        }

        DB::transaction(function () use ($call) {
            $terminalStatus = $call->status === 'active' ? 'ended' : 'declined';
            $this->finishCall($call, $terminalStatus);
        });
    }

    public function maxEventIdForUser(User $user): int
    {
        return (int) (ChatUserEvent::query()
            ->where('user_id', $user->id)
            ->max('id') ?? 0);
    }

    public function syncHasChanges(User $user, int $afterEventId): bool
    {
        return ChatUserEvent::query()
            ->where('user_id', $user->id)
            ->where('id', '>', $afterEventId)
            ->exists();
    }

    public function eventsForUserSince(User $user, int $afterEventId): array
    {
        return ChatUserEvent::query()
            ->where('user_id', $user->id)
            ->where('id', '>', $afterEventId)
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(fn (ChatUserEvent $event) => $this->serializeEvent($event))
            ->values()
            ->all();
    }

    public function signalCall(User $actor, ChatCallSession $call, string $type, array $payload = []): void
    {
        $this->assertCallParticipant($call, $actor);
        $this->expireStaleCalls();

        if (! in_array($type, ['offer', 'answer', 'ice_candidate', 'media_state', 'ready', 'hangup'], true)) {
            throw new ChatWorkspaceException('Signal panggilan tidak valid.');
        }

        if (! in_array($call->status, ['ringing', 'active'], true)) {
            return;
        }

        DB::transaction(function () use ($actor, $call, $type, $payload) {
            $call->refresh();
            $call->loadMissing('conversation');

            if (! in_array($call->status, ['ringing', 'active'], true)) {
                return;
            }

            $metadata = $call->metadata ?? [];
            if ($type === 'media_state' || $type === 'ready') {
                $mediaStateByUser = is_array($metadata['media_state_by_user'] ?? null)
                    ? $metadata['media_state_by_user']
                    : [];
                $mediaStateByUser[(string) $actor->id] = [
                    'mic_enabled' => (bool) ($payload['mic_enabled'] ?? true),
                    'camera_enabled' => (bool) ($payload['camera_enabled'] ?? false),
                    'speaker_enabled' => (bool) ($payload['speaker_enabled'] ?? true),
                    'ready' => $type === 'ready' || (bool) ($payload['ready'] ?? false),
                    'updated_at' => now()->toISOString(),
                ];
                $metadata['media_state_by_user'] = $mediaStateByUser;
            }

            if (in_array($type, ['offer', 'answer'], true)) {
                $descriptions = is_array($metadata['descriptions'] ?? null)
                    ? $metadata['descriptions']
                    : [];
                $descriptions[$type] = [
                    'from_user_id' => (string) $actor->id,
                    'payload' => $payload,
                    'created_at' => now()->toISOString(),
                ];
                $metadata['descriptions'] = $descriptions;
            }

            if ($type === 'ice_candidate') {
                $candidates = is_array($metadata['candidates'] ?? null)
                    ? $metadata['candidates']
                    : [];
                $candidates[] = [
                    'from_user_id' => (string) $actor->id,
                    'payload' => $payload,
                    'created_at' => now()->toISOString(),
                ];
                $metadata['candidates'] = array_slice($candidates, -50);
            }

            if ($type === 'hangup') {
                $metadata['hangup'] = [
                    'from_user_id' => (string) $actor->id,
                    'payload' => $payload,
                    'created_at' => now()->toISOString(),
                ];
            }

            $call->forceFill([
                'metadata' => $metadata,
                'last_activity_at' => now(),
            ])->save();

            $this->emitConversationEvent(
                $call->conversation,
                'call.signal',
                null,
                $call->id,
                [
                    'call_id' => (string) $call->id,
                    'signal_type' => $type,
                    'from_user_id' => (string) $actor->id,
                    'payload' => $payload,
                ],
                $actor->id,
            );
        });
    }

    public function expireStaleCalls(): void
    {
        ChatCallSession::query()
            ->where('status', 'ringing')
            ->where('created_at', '<=', now()->subSeconds(25))
            ->with('conversation')
            ->orderBy('id')
            ->get()
            ->each(function (ChatCallSession $call) {
                DB::transaction(function () use ($call) {
                    ChatCallParticipant::query()
                        ->where('call_session_id', $call->id)
                        ->where('state', 'invited')
                        ->update([
                            'state' => 'missed',
                            'acted_at' => now(),
                            'updated_at' => now(),
                        ]);

                    $this->finishCall($call, 'missed');
                });
            });
    }

    /**
     * @return array{0: ChatMessage, 1: bool}
     */
    private function createOrReuseMessage(
        User $actor,
        ChatConversation $conversation,
        string $kind,
        ?string $text,
        ?string $clientToken = null,
        array $metadata = [],
    ): array {
        $existingMessage = null;
        if ($clientToken !== null && trim($clientToken) !== '') {
            $existingMessage = ChatMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('sender_id', $actor->id)
                ->where('client_token', trim($clientToken))
                ->first();
        }

        if ($existingMessage !== null) {
            return [$existingMessage->loadMissing(['sender.roles', 'attachment']), false];
        }

        $message = ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $actor->id,
            'client_token' => $clientToken ? trim($clientToken) : null,
            'kind' => $kind,
            'body' => $text !== null ? trim($text) : null,
            'metadata' => $metadata,
        ]);

        return [$message->loadMissing(['sender.roles', 'attachment']), true];
    }

    private function dispatchMessageNotifications(
        User $actor,
        ChatConversation $conversation,
        ChatMessage $message,
    ): void {
        $conversation->loadMissing([
            'participants.user.roles',
            'states',
        ]);

        foreach ($conversation->participants as $participant) {
            $recipient = $participant->user;
            if (
                ! $participant->is_active
                || $recipient === null
                || ! $recipient->is_active
                || (int) $recipient->id === (int) $actor->id
            ) {
                continue;
            }

            $state = $conversation->states
                ->firstWhere('user_id', $recipient->id);
            if ((bool) ($state?->is_muted ?? false)) {
                continue;
            }

            $title = $conversation->type === 'group'
                ? $this->conversationTitle($recipient, $conversation)
                : trim((string) $actor->name);
            $messagePreview = $this->messageNotificationPreview($message);
            $body = $conversation->type === 'group'
                ? trim((string) $actor->name).': '.$messagePreview
                : $messagePreview;

            Notification::query()->create([
                'user_id' => $recipient->id,
                'title' => $title !== '' ? $title : 'Chat baru',
                'message' => $body,
                'type' => 'general',
                'link' => '/chat/conversations/'.$conversation->id,
                'is_read' => false,
            ]);
        }
    }

    private function dispatchIncomingCallNotifications(
        User $actor,
        ChatConversation $conversation,
        ChatCallSession $call,
    ): void {
        $conversation->loadMissing([
            'participants.user.roles',
        ]);

        foreach ($conversation->participants as $participant) {
            $recipient = $participant->user;
            if (
                ! $participant->is_active
                || $recipient === null
                || ! $recipient->is_active
                || (int) $recipient->id === (int) $actor->id
            ) {
                continue;
            }

            $callLabel = $call->type === 'video' ? 'Video call' : 'Panggilan suara';
            $groupSuffix = $conversation->type === 'group' ? ' grup' : '';

            Notification::query()->create([
                'user_id' => $recipient->id,
                'title' => $conversation->type === 'group'
                    ? $this->conversationTitle($recipient, $conversation)
                    : trim((string) $actor->name).' menelepon',
                'message' => $callLabel.$groupSuffix.' masuk dari '.trim((string) $actor->name).'.',
                'type' => 'general',
                'link' => '/chat/conversations/'.$conversation->id.'?call='.$call->id,
                'is_read' => false,
            ]);
        }
    }

    private function messageNotificationPreview(ChatMessage $message): string
    {
        if ($message->kind === 'voice_note') {
            return 'Mengirim voice note.';
        }

        if ($message->attachment !== null) {
            return $this->attachmentNotificationPreview($message->attachment);
        }

        $body = trim((string) ($message->body ?? ''));

        return $body !== '' ? $body : 'Pesan baru';
    }

    private function attachmentNotificationPreview(ChatMessageAttachment $attachment): string
    {
        if ($this->attachmentLooksLikePhoto($attachment)) {
            return 'Mengirim photo.';
        }

        return 'Mengirim file.';
    }

    private function attachmentLooksLikePhoto(ChatMessageAttachment $attachment): bool
    {
        $mimeType = strtolower(trim((string) ($attachment->mime_type ?? '')));
        if ($mimeType !== '' && str_starts_with($mimeType, 'image/')) {
            return true;
        }

        $extension = strtolower((string) pathinfo((string) $attachment->original_name, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif'], true);
    }

    private function ensureWorkspaceProvisioned(User $user): void
    {
        $user->loadMissing('roles');

        foreach (self::DEFAULT_GROUPS as $definition) {
            $conversation = ChatConversation::query()->firstOrCreate(
                ['slug' => $definition['slug']],
                [
                    'type' => 'group',
                    'title' => $definition['title'],
                    'subtitle' => $definition['subtitle'],
                    'description' => $definition['subtitle'],
                    'accent_color' => $definition['accent_color'],
                    'created_by' => $user->id,
                ],
            );

            $this->ensureParticipant($conversation, $user);
            $this->ensureState($conversation, $user);
        }
    }

    private function serializeConversation(User $viewer, ChatConversation $conversation): array
    {
        $state = $this->stateForUser($conversation, $viewer);
        $latestMessage = $conversation->relationLoaded('messages')
            ? ($conversation->messages->last() ?? $conversation->latestMessage)
            : $conversation->latestMessage;
        $updatedAt = $conversation->last_message_at ?? $conversation->updated_at;
        $title = $this->conversationTitle($viewer, $conversation);
        $subtitle = $this->conversationSubtitle($viewer, $conversation);
        $isGroup = $conversation->type === 'group';

        return [
            'id' => (string) $conversation->id,
            'title' => $title,
            'preview' => $this->previewForMessage($latestMessage),
            'timestamp' => $this->formatConversationTimestamp($updatedAt),
            'is_group' => $isGroup,
            'accent_color' => $conversation->accent_color ?? $this->accentColorForConversation((string) $conversation->id),
            'subtitle' => $subtitle,
            'unread_count' => $this->unreadCountForConversation($viewer, $conversation, $state),
            'is_pinned' => (bool) ($state?->is_pinned ?? false),
            'is_typing' => false,
            'is_online' => ! $isGroup && $this->directPeerIsOnline($viewer, $conversation),
            'is_muted' => (bool) ($state?->is_muted ?? false),
            'updated_at' => optional($updatedAt)?->toISOString(),
        ];
    }

    private function serializeDirectoryMember(User $viewer, User $member): array
    {
        $member->loadMissing('roles');

        return [
            'id' => (string) $member->id,
            'name' => $member->name,
            'role' => $this->userRoleLabel($member),
            'accent_color' => $this->accentColorForUser($member->id),
            'active' => $this->userIsOnline($member),
            'is_current_user' => false,
        ];
    }

    private function serializeConversationMember(User $viewer, User $member): array
    {
        $member->loadMissing('roles');

        return [
            'id' => (string) $member->id,
            'name' => $member->name,
            'role' => $this->userRoleLabel($member),
            'accent_color' => $this->accentColorForUser($member->id),
            'active' => $this->userIsOnline($member),
            'is_current_user' => (int) $member->id === (int) $viewer->id,
        ];
    }

    private function serializeMessage(User $viewer, ChatConversation $conversation, ChatMessage $message): array
    {
        $attachment = $message->attachment;
        $metadata = $message->metadata ?? [];

        return [
            'id' => (string) $message->id,
            'text' => (string) ($message->body ?? ''),
            'time_label' => $message->created_at?->format('H:i') ?? '',
            'delivery' => $this->deliveryForMessage($viewer, $conversation, $message),
            'sender_name' => $message->sender_id && (int) $message->sender_id !== (int) $viewer->id
                ? $message->sender?->name
                : null,
            'is_mine' => (int) $message->sender_id === (int) $viewer->id,
            'is_system' => $message->kind === 'system',
            'has_attachment' => $attachment !== null,
            'attachment_label' => $attachment?->original_name,
            'attachment_type_label' => $attachment ? $this->attachmentTypeLabel($attachment->original_name) : null,
            'attachment_size_label' => $attachment ? $this->formatFileSize($attachment->size_bytes) : null,
            'attachment_url' => $attachment ? $this->attachmentUrl($attachment) : null,
            'attachment_mime_type' => $attachment?->mime_type,
            'is_voice_note' => $message->kind === 'voice_note',
            'voice_note_duration' => $message->kind === 'voice_note'
                ? ($metadata['duration_label'] ?? '0:00')
                : null,
            'sent_at' => optional($message->created_at)?->toISOString(),
        ];
    }

    private function serializeAttachmentAsset(
        ChatConversation $conversation,
        ChatMessage $message,
        ChatMessageAttachment $attachment,
    ): array {
        return [
            'id' => (string) $attachment->id,
            'label' => $attachment->original_name,
            'type_label' => $this->attachmentTypeLabel($attachment->original_name),
            'uploaded_by' => $message->sender?->name ?? 'System',
            'uploaded_at' => optional($message->created_at)?->toISOString(),
            'size_label' => $this->formatFileSize($attachment->size_bytes),
            'accent_color' => $conversation->accent_color ?? $this->accentColorForConversation((string) $conversation->id),
        ];
    }

    private function serializeCall(User $viewer, ChatCallSession $call): array
    {
        $call->loadMissing([
            'conversation.participants.user.roles',
            'participants.user.roles',
            'initiator.roles',
            'answerer.roles',
        ]);

        $conversation = $call->conversation;
        $isGroup = $conversation->type === 'group';
        $metadata = is_array($call->metadata ?? null) ? $call->metadata : [];
        $mediaStateByUser = is_array($metadata['media_state_by_user'] ?? null)
            ? $metadata['media_state_by_user']
            : [];
        $conversationMembers = $conversation->participants
            ->filter(fn (ChatConversationParticipant $participant) => $participant->user !== null)
            ->values();
        $viewerMediaState = is_array($mediaStateByUser[(string) $viewer->id] ?? null)
            ? $mediaStateByUser[(string) $viewer->id]
            : [];

        return [
            'id' => (string) $call->id,
            'conversation_id' => (string) $conversation->id,
            'title' => $this->conversationTitle($viewer, $conversation),
            'subtitle' => $this->conversationSubtitle($viewer, $conversation),
            'is_group' => $isGroup,
            'type' => $call->type,
            'status' => $call->status,
            'is_incoming' => (int) $call->initiated_by !== (int) $viewer->id,
            'created_at' => optional($call->created_at)?->toISOString(),
            'started_at' => optional($call->started_at)?->toISOString(),
            'ended_at' => optional($call->ended_at)?->toISOString(),
            'participants' => $conversationMembers
                ->map(function (ChatConversationParticipant $participant) use ($call, $viewer) {
                    $callState = $call->participants
                        ->firstWhere('user_id', $participant->user_id);
                    $metadata = is_array($call->metadata ?? null) ? $call->metadata : [];
                    $mediaStateByUser = is_array($metadata['media_state_by_user'] ?? null)
                        ? $metadata['media_state_by_user']
                        : [];
                    $participantMediaState = is_array($mediaStateByUser[(string) $participant->user_id] ?? null)
                        ? $mediaStateByUser[(string) $participant->user_id]
                        : [];
                    $micEnabled = array_key_exists('mic_enabled', $participantMediaState)
                        ? (bool) $participantMediaState['mic_enabled']
                        : true;
                    $cameraEnabled = array_key_exists('camera_enabled', $participantMediaState)
                        ? (bool) $participantMediaState['camera_enabled']
                        : ($call->type === 'video' && $call->status === 'active');

                    return [
                        'id' => (string) $participant->user_id,
                        'name' => $participant->user->name,
                        'role' => $this->userRoleLabel($participant->user),
                        'accent_color' => $this->accentColorForUser($participant->user_id),
                        'is_current_user' => (int) $participant->user_id === (int) $viewer->id,
                        'is_muted' => ! $micEnabled,
                        'is_video_enabled' => $cameraEnabled,
                        'is_connected' => $callState?->state === 'accepted'
                            || ((int) $participant->user_id === (int) $call->initiated_by && in_array($call->status, ['ringing', 'active'], true)),
                        'is_speaking' => false,
                    ];
                })
                ->values()
                ->all(),
            'speaker_enabled' => array_key_exists('speaker_enabled', $viewerMediaState)
                ? (bool) $viewerMediaState['speaker_enabled']
                : true,
            'mic_enabled' => array_key_exists('mic_enabled', $viewerMediaState)
                ? (bool) $viewerMediaState['mic_enabled']
                : true,
            'camera_enabled' => array_key_exists('camera_enabled', $viewerMediaState)
                ? (bool) $viewerMediaState['camera_enabled']
                : $call->type === 'video',
            'metadata' => $metadata,
        ];
    }

    private function activeCallForUser(User $user): ?ChatCallSession
    {
        return ChatCallSession::query()
            ->whereIn('status', ['ringing', 'active'])
            ->whereHas('participants', fn ($query) => $query->where('user_id', $user->id))
            ->with([
                'conversation.participants.user.roles',
                'participants.user.roles',
                'initiator.roles',
                'answerer.roles',
            ])
            ->latest('created_at')
            ->first();
    }

    private function unreadCountForConversation(
        User $viewer,
        ChatConversation $conversation,
        ?ChatConversationUserState $state,
    ): int {
        if (! $conversation->relationLoaded('messages') || $conversation->messages->isEmpty()) {
            return ChatMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where(function ($query) use ($viewer) {
                    $query
                        ->whereNull('sender_id')
                        ->orWhere('sender_id', '!=', $viewer->id);
                })
                ->when(
                    $state?->last_read_at !== null,
                    fn ($query) => $query->where('created_at', '>', $state->last_read_at),
                )
                ->count();
        }

        return $conversation->messages
            ->filter(function (ChatMessage $message) use ($viewer, $state) {
                if ((int) $message->sender_id === (int) $viewer->id) {
                    return false;
                }

                if ($state?->last_read_at === null) {
                    return true;
                }

                return $message->created_at?->gt($state->last_read_at) ?? false;
            })
            ->count();
    }

    private function previewForMessage(?ChatMessage $message): string
    {
        if ($message === null) {
            return 'Percakapan dimulai';
        }

        if ($message->kind === 'voice_note') {
            return 'Voice note';
        }

        if ($message->attachment !== null) {
            return $message->attachment->original_name;
        }

        $body = trim((string) ($message->body ?? ''));

        return $body !== '' ? $body : 'Percakapan dimulai';
    }

    private function deliveryForMessage(User $viewer, ChatConversation $conversation, ChatMessage $message): string
    {
        if ((int) $message->sender_id !== (int) $viewer->id) {
            return 'delivered';
        }

        $otherStates = $conversation->states
            ->where('user_id', '!=', $viewer->id);

        if ($otherStates->isEmpty()) {
            return 'read';
        }

        $allRead = $otherStates->every(function (ChatConversationUserState $state) use ($message) {
            if ($state->last_read_at === null || $message->created_at === null) {
                return false;
            }

            return $state->last_read_at->greaterThanOrEqualTo($message->created_at);
        });

        return $allRead ? 'read' : 'delivered';
    }

    private function conversationTitle(User $viewer, ChatConversation $conversation): string
    {
        if ($conversation->type === 'group') {
            return $conversation->title ?? 'Group Chat';
        }

        $peer = $conversation->participants
            ->first(fn (ChatConversationParticipant $participant) => (int) $participant->user_id !== (int) $viewer->id)
            ?->user;

        return $peer?->name ?? ($conversation->title ?? 'Direct Chat');
    }

    private function conversationSubtitle(User $viewer, ChatConversation $conversation): string
    {
        if ($conversation->type === 'group') {
            return $conversation->subtitle ?? sprintf('%d anggota', $conversation->participants->count());
        }

        $peer = $conversation->participants
            ->first(fn (ChatConversationParticipant $participant) => (int) $participant->user_id !== (int) $viewer->id)
            ?->user;

        return $peer ? $this->userRoleLabel($peer) : ($conversation->subtitle ?? 'Internal');
    }

    private function directPeerIsOnline(User $viewer, ChatConversation $conversation): bool
    {
        $peer = $conversation->participants
            ->first(fn (ChatConversationParticipant $participant) => (int) $participant->user_id !== (int) $viewer->id)
            ?->user;

        return $peer ? $this->userIsOnline($peer) : false;
    }

    private function userRoleLabel(User $user): string
    {
        $user->loadMissing('roles');
        $role = $user->roles->pluck('name')->first();

        return $role ?: ($user->department ?: 'Internal');
    }

    private function userIsOnline(User $user): bool
    {
        if (! $this->hasSessionsTable()) {
            return true;
        }

        return in_array((int) $user->id, $this->onlineUserIds(), true);
    }

    private function hasSessionsTable(): bool
    {
        if ($this->sessionsTableAvailable !== null) {
            return $this->sessionsTableAvailable;
        }

        $this->sessionsTableAvailable = Schema::hasTable('sessions');

        return $this->sessionsTableAvailable;
    }

    private function onlineUserIds(): array
    {
        if ($this->onlineUserIdsCache !== null) {
            return $this->onlineUserIdsCache;
        }

        if (! $this->hasSessionsTable()) {
            $this->onlineUserIdsCache = [];

            return $this->onlineUserIdsCache;
        }

        $this->onlineUserIdsCache = DB::table('sessions')
            ->whereNotNull('user_id')
            ->where('last_activity', '>=', now()->subMinutes(5)->timestamp)
            ->pluck('user_id')
            ->map(fn ($userId) => (int) $userId)
            ->unique()
            ->values()
            ->all();

        return $this->onlineUserIdsCache;
    }

    private function normalizeConversationIds(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $ids = collect(is_array($value) ? $value : [$value])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        return $ids === [] ? [] : $ids;
    }

    private function ensureParticipant(ChatConversation $conversation, User $user): ChatConversationParticipant
    {
        return ChatConversationParticipant::query()->firstOrCreate(
            [
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
            ],
            [
                'is_active' => true,
                'joined_at' => now(),
            ],
        );
    }

    private function ensureState(ChatConversation $conversation, User $user): ChatConversationUserState
    {
        return ChatConversationUserState::query()->firstOrCreate(
            [
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
            ],
        );
    }

    private function stateForUser(ChatConversation $conversation, User $user): ?ChatConversationUserState
    {
        return $conversation->states->firstWhere('user_id', $user->id);
    }

    private function assertConversationParticipant(ChatConversation $conversation, User $user): void
    {
        $isParticipant = ChatConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();

        if (! $isParticipant) {
            throw new ChatWorkspaceException('Anda tidak punya akses ke percakapan ini.', 403);
        }
    }

    private function assertCallParticipant(ChatCallSession $call, User $user): void
    {
        $isParticipant = ChatCallParticipant::query()
            ->where('call_session_id', $call->id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isParticipant) {
            throw new ChatWorkspaceException('Anda tidak punya akses ke panggilan ini.', 403);
        }
    }

    private function touchConversation(ChatConversation $conversation, ?Carbon $at = null): void
    {
        $conversation->forceFill([
            'last_message_at' => $at ?? now(),
        ])->save();
    }

    private function emitConversationEvent(
        ChatConversation $conversation,
        string $type,
        ?int $messageId = null,
        ?int $callSessionId = null,
        ?array $payload = null,
        ?int $excludedUserId = null,
    ): void {
        $participantIds = ChatConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('is_active', true)
            ->pluck('user_id')
            ->filter(fn ($userId) => $excludedUserId === null || (int) $userId !== (int) $excludedUserId);

        $now = now();
        $encodedPayload = $this->encodeEventPayload($payload);
        $rows = $participantIds->map(fn ($userId) => [
            'user_id' => $userId,
            'conversation_id' => $conversation->id,
            'message_id' => $messageId,
            'call_session_id' => $callSessionId,
            'event_type' => $type,
            'payload' => $encodedPayload,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($rows !== []) {
            ChatUserEvent::query()->insert($rows);
        }
    }

    private function encodeEventPayload(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new \RuntimeException('Gagal meng-encode payload event chat.');
        }

        return $encoded;
    }

    private function serializeEvent(ChatUserEvent $event): array
    {
        return [
            'id' => (int) $event->id,
            'conversation_id' => $event->conversation_id ? (string) $event->conversation_id : null,
            'message_id' => $event->message_id ? (string) $event->message_id : null,
            'call_session_id' => $event->call_session_id ? (string) $event->call_session_id : null,
            'event_type' => $event->event_type,
            'payload' => $event->payload ?? [],
            'created_at' => optional($event->created_at)?->toISOString(),
        ];
    }

    private function finishCall(ChatCallSession $call, string $terminalStatus): void
    {
        if (! in_array($terminalStatus, ['ended', 'missed', 'declined'], true)) {
            throw new ChatWorkspaceException('Status akhir panggilan tidak valid.');
        }

        $call->refresh();
        $call->forceFill([
            'status' => $terminalStatus,
            'ended_at' => $call->ended_at ?? now(),
            'last_activity_at' => now(),
        ])->save();

        $conversation = $call->conversation()->firstOrFail();
        $summaryMessage = ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => null,
            'kind' => 'system',
            'body' => $this->callSummaryFor($conversation, $call->fresh()),
            'metadata' => [
                'call_session_id' => $call->id,
                'terminal_status' => $terminalStatus,
            ],
        ]);

        $this->touchConversation($conversation, $summaryMessage->created_at);
        $this->emitConversationEvent($conversation, 'call.finished', $summaryMessage->id, $call->id);
    }

    private function callSummaryFor(ChatConversation $conversation, ChatCallSession $call): string
    {
        $scopeLabel = $call->type === 'video'
            ? ($conversation->type === 'group' ? 'Video call grup' : 'Video call')
            : ($conversation->type === 'group' ? 'Panggilan suara grup' : 'Panggilan suara');

        if ($call->status === 'ended') {
            $duration = $call->started_at && $call->ended_at
                ? $this->formatCallDuration($call->ended_at->diff($call->started_at))
                : '00:00';

            return sprintf('%s selesai • %s', $scopeLabel, $duration);
        }

        if ($call->status === 'missed') {
            return sprintf('%s tidak terjawab', $scopeLabel);
        }

        return sprintf('%s ditolak', $scopeLabel);
    }

    private function formatConversationTimestamp(?Carbon $timestamp): string
    {
        if ($timestamp === null) {
            return '';
        }

        $now = now();
        if ($timestamp->isSameDay($now)) {
            return $timestamp->format('H:i');
        }

        if ($timestamp->isSameDay($now->copy()->subDay())) {
            return 'Kemarin';
        }

        return $timestamp->locale('id')->translatedFormat('d M');
    }

    private function formatCallDuration(\DateInterval $interval): string
    {
        $hours = max(0, ((int) $interval->days * 24) + (int) $interval->h);
        $minutes = str_pad((string) $interval->i, 2, '0', STR_PAD_LEFT);
        $seconds = str_pad((string) $interval->s, 2, '0', STR_PAD_LEFT);

        if ($hours > 0) {
            return sprintf('%02d:%s:%s', $hours, $minutes, $seconds);
        }

        return sprintf('%d:%s', (int) $interval->i, $seconds);
    }

    private function formatFileSize(?int $sizeBytes): string
    {
        $bytes = max(0, (int) $sizeBytes);
        if ($bytes === 0) {
            return '0 KB';
        }

        if ($bytes < 1024 * 1024) {
            $kilobytes = $bytes / 1024;

            return sprintf(
                '%s KB',
                number_format($kilobytes, $kilobytes >= 100 ? 0 : 1, '.', ''),
            );
        }

        $megabytes = $bytes / (1024 * 1024);

        return sprintf(
            '%s MB',
            number_format($megabytes, $megabytes >= 100 ? 0 : 1, '.', ''),
        );
    }

    private function attachmentTypeLabel(string $originalName): string
    {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);

        return $extension !== '' ? strtoupper($extension) : 'FILE';
    }

    private function attachmentUrl(ChatMessageAttachment $attachment): string
    {
        return url('/storage/'.$attachment->path);
    }

    private function directHashFor(array $userIds): string
    {
        sort($userIds, SORT_NUMERIC);

        return implode(':', $userIds);
    }

    private function accentColorForUser(int $userId): int
    {
        return self::USER_ACCENT_PALETTE[$userId % count(self::USER_ACCENT_PALETTE)];
    }

    private function accentColorForConversation(string $seed): int
    {
        $hash = abs(crc32($seed));

        return self::USER_ACCENT_PALETTE[$hash % count(self::USER_ACCENT_PALETTE)];
    }
}
