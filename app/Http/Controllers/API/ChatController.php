<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ChatCallSession;
use App\Models\ChatConversation;
use App\Models\User;
use App\Support\ChatWorkspaceException;
use App\Support\ChatWorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChatController extends Controller
{
    public function __construct(private readonly ChatWorkspaceService $chatWorkspaceService) {}

    public function workspace(Request $request)
    {
        return $this->handleChatRequest(fn () => $this->workspaceResponse($request->user()));
    }

    public function sync(Request $request)
    {
        return $this->handleChatRequest(function () use ($request) {
            $validated = $request->validate([
                'after_event_id' => ['nullable', 'integer', 'min:0'],
                'wait_seconds' => ['nullable', 'integer', 'min:0', 'max:15'],
            ]);

            $user = $request->user();
            $afterEventId = (int) ($validated['after_event_id'] ?? 0);
            $waitSeconds = (int) ($validated['wait_seconds'] ?? 10);
            $deadline = microtime(true) + $waitSeconds;

            do {
                $this->chatWorkspaceService->expireStaleCalls();

                if ($this->chatWorkspaceService->syncHasChanges($user, $afterEventId)) {
                    return $this->workspaceResponse($user, 200, $afterEventId);
                }

                if ($waitSeconds === 0) {
                    break;
                }

                usleep(350000);
            } while (microtime(true) < $deadline);

            $lastEventId = $this->chatWorkspaceService->maxEventIdForUser($user);
            if ($lastEventId > $afterEventId) {
                return $this->workspaceResponse($user, 200, $afterEventId);
            }

            return response()->json([
                'has_changes' => false,
                'last_event_id' => $lastEventId,
            ]);
        });
    }

    public function stream(Request $request)
    {
        return $this->handleChatRequest(function () use ($request) {
            $validated = $request->validate([
                'after_event_id' => ['nullable', 'integer', 'min:0'],
            ]);

            $user = $request->user();
            $afterEventId = (int) ($validated['after_event_id'] ?? 0);

            return response()->stream(function () use ($user, $afterEventId) {
                $currentAfterEventId = $afterEventId;
                $startedAt = microtime(true);
                $nextHeartbeatAt = microtime(true);

                echo ": connected\n\n";
                $this->flushStreamBuffers();

                while (! connection_aborted() && microtime(true) - $startedAt < 55) {
                    $this->chatWorkspaceService->expireStaleCalls();

                    if ($this->chatWorkspaceService->syncHasChanges($user, $currentAfterEventId)) {
                        $payload = $this->workspacePayload($user, $currentAfterEventId);
                        $lastEventId = (int) ($payload['last_event_id'] ?? $currentAfterEventId);

                        $this->writeServerSentEvent('workspace', $payload, $lastEventId);
                        $currentAfterEventId = max($currentAfterEventId, $lastEventId);
                        $nextHeartbeatAt = microtime(true) + 10;
                        $this->flushStreamBuffers();

                        continue;
                    }

                    if (microtime(true) >= $nextHeartbeatAt) {
                        echo ": ping\n\n";
                        $nextHeartbeatAt = microtime(true) + 10;
                        $this->flushStreamBuffers();
                    }

                    usleep(350000);
                }
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-transform',
                'X-Accel-Buffering' => 'no',
                'Connection' => 'keep-alive',
            ]);
        });
    }

    public function ensureDirectConversation(Request $request)
    {
        return $this->handleChatRequest(function () use ($request) {
            $validated = $request->validate([
                'participant_user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            ]);

            $participant = User::query()->findOrFail($validated['participant_user_id']);
            $this->chatWorkspaceService->ensureDirectConversation($request->user(), $participant);

            return $this->workspaceResponse($request->user(), 201);
        });
    }

    public function sendMessage(Request $request, ChatConversation $conversation)
    {
        return $this->handleChatRequest(function () use ($request, $conversation) {
            $validated = $request->validate([
                'kind' => ['nullable', Rule::in(['text', 'voice_note'])],
                'text' => ['nullable', 'string', 'max:5000'],
                'client_token' => ['nullable', 'string', 'max:255'],
                'voice_note_duration' => ['nullable', 'string', 'max:40'],
            ]);

            $kind = $validated['kind'] ?? 'text';
            $metadata = [];
            if ($kind === 'voice_note' && isset($validated['voice_note_duration'])) {
                $metadata['duration_label'] = $validated['voice_note_duration'];
            }

            $this->chatWorkspaceService->sendMessage(
                $request->user(),
                $conversation,
                $kind,
                $validated['text'] ?? null,
                $validated['client_token'] ?? null,
                $metadata,
            );

            return $this->workspaceResponse($request->user(), 201);
        });
    }

    public function sendAttachment(Request $request, ChatConversation $conversation)
    {
        return $this->handleChatRequest(function () use ($request, $conversation) {
            $validated = $request->validate([
                'attachment' => ['required', 'file', 'max:20480'],
                'kind' => ['nullable', Rule::in(['attachment', 'voice_note'])],
                'caption' => ['nullable', 'string', 'max:5000'],
                'client_token' => ['nullable', 'string', 'max:255'],
                'voice_note_duration' => ['nullable', 'string', 'max:40'],
            ]);

            $kind = $validated['kind'] ?? 'attachment';
            $metadata = [];
            if ($kind === 'voice_note' && isset($validated['voice_note_duration'])) {
                $metadata['duration_label'] = $validated['voice_note_duration'];
            }

            $this->chatWorkspaceService->sendAttachment(
                $request->user(),
                $conversation,
                $validated['attachment'],
                $validated['caption'] ?? null,
                $validated['client_token'] ?? null,
                $kind,
                $metadata,
            );

            return $this->workspaceResponse($request->user(), 201);
        });
    }

    public function markRead(Request $request, ChatConversation $conversation)
    {
        return $this->handleChatRequest(function () use ($request, $conversation) {
            $this->chatWorkspaceService->markConversationRead($request->user(), $conversation);

            return $this->workspaceResponse($request->user());
        });
    }

    public function updatePreferences(Request $request, ChatConversation $conversation)
    {
        return $this->handleChatRequest(function () use ($request, $conversation) {
            $validated = $request->validate([
                'is_pinned' => ['sometimes', 'boolean'],
                'is_muted' => ['sometimes', 'boolean'],
            ]);

            $this->chatWorkspaceService->updateConversationPreferences(
                $request->user(),
                $conversation,
                $validated,
            );

            return $this->workspaceResponse($request->user());
        });
    }

    public function startCall(Request $request, ChatConversation $conversation)
    {
        return $this->handleChatRequest(function () use ($request, $conversation) {
            $validated = $request->validate([
                'type' => ['required', Rule::in(['voice', 'video'])],
            ]);

            $this->chatWorkspaceService->startCall(
                $request->user(),
                $conversation,
                $validated['type'],
            );

            return $this->workspaceResponse($request->user(), 201);
        });
    }

    public function acceptCall(Request $request, ChatCallSession $call)
    {
        return $this->handleChatRequest(function () use ($request, $call) {
            $this->chatWorkspaceService->acceptCall($request->user(), $call);

            return $this->workspaceResponse($request->user());
        });
    }

    public function declineCall(Request $request, ChatCallSession $call)
    {
        return $this->handleChatRequest(function () use ($request, $call) {
            $this->chatWorkspaceService->declineCall($request->user(), $call);

            return $this->workspaceResponse($request->user());
        });
    }

    public function endCall(Request $request, ChatCallSession $call)
    {
        return $this->handleChatRequest(function () use ($request, $call) {
            $this->chatWorkspaceService->endCall($request->user(), $call);

            return $this->workspaceResponse($request->user());
        });
    }

    public function signalCall(Request $request, ChatCallSession $call)
    {
        return $this->handleChatRequest(function () use ($request, $call) {
            $validated = $request->validate([
                'type' => ['required', Rule::in(['offer', 'answer', 'ice_candidate', 'media_state', 'ready', 'hangup'])],
                'payload' => ['nullable', 'array'],
            ]);

            $this->chatWorkspaceService->signalCall(
                $request->user(),
                $call,
                $validated['type'],
                $validated['payload'] ?? [],
            );

            return $this->workspaceResponse($request->user());
        });
    }

    private function workspaceResponse(User $user, int $status = 200, ?int $afterEventId = null)
    {
        return response()->json($this->workspacePayload($user, $afterEventId), $status);
    }

    private function workspacePayload(User $user, ?int $afterEventId = null): array
    {
        $workspace = $this->chatWorkspaceService->workspace($user);
        $events = $afterEventId === null
            ? []
            : $this->chatWorkspaceService->eventsForUserSince($user, $afterEventId);

        return [
            'has_changes' => true,
            'last_event_id' => $workspace['last_event_id'] ?? 0,
            'workspace' => $workspace,
            'events' => $events,
        ];
    }

    private function writeServerSentEvent(string $event, array $payload, int $id): void
    {
        echo "event: {$event}\n";
        if ($id > 0) {
            echo "id: {$id}\n";
        }

        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encodedPayload === false) {
            $encodedPayload = '{}';
        }

        foreach (explode("\n", $encodedPayload) as $line) {
            echo "data: {$line}\n";
        }
        echo "\n";
    }

    private function flushStreamBuffers(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }

    private function handleChatRequest(callable $callback)
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (ChatWorkspaceException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], $exception->status());
        } catch (\Throwable $throwable) {
            Log::error('Chat workspace error: '.$throwable->getMessage(), [
                'exception' => $throwable,
            ]);

            return response()->json([
                'error' => 'Chat server gagal memproses permintaan.',
            ], 500);
        }
    }
}
