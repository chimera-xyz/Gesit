<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\NotificationDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = $this->userNotifications($request->user())
                ->orderByDesc('created_at');

            if ($request->boolean('unread_only')) {
                $query->where('is_read', false);
            }

            $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);
            $notifications = $query->paginate($perPage);

            return response()->json([
                'notifications' => collect($notifications->items())
                    ->map(fn (Notification $notification) => $this->serializeNotification($notification))
                    ->values()
                    ->all(),
                'unread_count' => $this->countUnreadNotifications($request->user()),
                'pagination' => [
                    'current_page' => $notifications->currentPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
                    'last_page' => $notifications->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Get Notifications Error: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Gagal memuat notifikasi.'], 500);
        }
    }

    public function unreadFeed(Request $request)
    {
        try {
            $notifications = $this->userNotifications($request->user())
                ->where('is_read', false)
                ->orderBy('created_at')
                ->get();

            return response()->json([
                'notifications' => $notifications
                    ->map(fn (Notification $notification) => $this->serializeNotification($notification))
                    ->values()
                    ->all(),
                'unread_count' => $notifications->count(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Get Unread Notification Feed Error: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Gagal memuat notifikasi baru.'], 500);
        }
    }

    public function stream(Request $request)
    {
        $validated = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
            'timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:55'],
        ]);

        $user = $request->user();
        $afterId = (int) ($validated['after_id'] ?? 0);
        $timeoutSeconds = (int) ($validated['timeout_seconds'] ?? 55);

        return response()->stream(function () use ($user, $afterId, $timeoutSeconds) {
            $currentAfterId = $afterId;
            $startedAt = microtime(true);
            $nextHeartbeatAt = microtime(true);

            echo ": connected\n\n";
            $this->flushStreamBuffers();

            while (! connection_aborted() && microtime(true) - $startedAt < $timeoutSeconds) {
                if ($this->hasNotificationsAfter($user, $currentAfterId)) {
                    $notifications = $this->notificationsAfter($user, $currentAfterId);
                    $lastId = (int) ($notifications->last()?->id ?? $currentAfterId);

                    $this->writeServerSentEvent('notifications', [
                        'notifications' => $notifications
                            ->map(fn (Notification $notification) => $this->serializeNotification($notification))
                            ->values()
                            ->all(),
                        'last_notification_id' => $lastId,
                    ], $lastId);

                    $currentAfterId = max($currentAfterId, $lastId);
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
    }

    public function registerDeviceToken(Request $request)
    {
        try {
            $validated = $request->validate([
                'token' => ['required', 'string', 'max:4096'],
                'platform' => ['required', 'string', 'in:android,ios'],
            ]);

            $device = NotificationDevice::query()->updateOrCreate(
                ['token' => $validated['token']],
                [
                    'user_id' => $request->user()->id,
                    'platform' => $validated['platform'],
                    'is_active' => true,
                    'last_registered_at' => now(),
                ]
            );

            return response()->json([
                'success' => true,
                'device' => [
                    'id' => $device->id,
                    'platform' => $device->platform,
                    'last_registered_at' => optional($device->last_registered_at)?->toISOString(),
                ],
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Register Notification Device Error: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Gagal mendaftarkan perangkat push.'], 500);
        }
    }

    public function unregisterDeviceToken(Request $request)
    {
        try {
            $validated = $request->validate([
                'token' => ['required', 'string', 'max:4096'],
            ]);

            NotificationDevice::query()
                ->where('user_id', $request->user()->id)
                ->where('token', $validated['token'])
                ->delete();

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            Log::error('Unregister Notification Device Error: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Gagal melepas perangkat push.'], 500);
        }
    }

    public function markAsRead(Request $request, $id)
    {
        try {
            $notification = $this->userNotifications($request->user())
                ->findOrFail($id);

            if (! $notification->is_read) {
                $notification->markAsRead();
            }

            return response()->json([
                'success' => true,
                'notification' => $this->serializeNotification($notification->fresh()),
                'unread_count' => $this->countUnreadNotifications($request->user()),
            ]);
        } catch (\Throwable $e) {
            Log::error('Mark Notification as Read Error: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Gagal menandai notifikasi.'], 500);
        }
    }

    public function markAllAsRead(Request $request)
    {
        try {
            $this->userNotifications($request->user())
                ->where('is_read', false)
                ->update([
                    'is_read' => true,
                    'read_at' => Carbon::now(),
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Semua notifikasi ditandai sebagai dibaca',
                'unread_count' => 0,
            ]);
        } catch (\Throwable $e) {
            Log::error('Mark All Notifications as Read Error: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Gagal menandai semua notifikasi.'], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            $notification = $this->userNotifications($request->user())
                ->findOrFail($id);

            $notification->delete();

            return response()->json([
                'success' => true,
                'message' => 'Notifikasi berhasil dihapus',
                'unread_count' => $this->countUnreadNotifications($request->user()),
            ]);
        } catch (\Throwable $e) {
            Log::error('Delete Notification Error: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Gagal menghapus notifikasi.'], 500);
        }
    }

    public function destroyAll(Request $request)
    {
        try {
            $this->userNotifications($request->user())->delete();

            return response()->json([
                'success' => true,
                'message' => 'Semua notifikasi berhasil dihapus',
                'unread_count' => 0,
            ]);
        } catch (\Throwable $e) {
            Log::error('Delete All Notifications Error: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Gagal menghapus semua notifikasi.'], 500);
        }
    }

    public function unreadCount(Request $request)
    {
        try {
            return response()->json([
                'unread_count' => $this->countUnreadNotifications($request->user()),
            ]);
        } catch (\Throwable $e) {
            Log::error('Get Unread Count Error: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Gagal menghitung notifikasi belum dibaca.'], 500);
        }
    }

    private function userNotifications(User $user)
    {
        return Notification::query()->where('user_id', $user->id);
    }

    private function countUnreadNotifications(User $user): int
    {
        return $this->userNotifications($user)
            ->where('is_read', false)
            ->count();
    }

    private function hasNotificationsAfter(User $user, int $afterId): bool
    {
        return $this->userNotifications($user)
            ->where('id', '>', $afterId)
            ->exists();
    }

    private function notificationsAfter(User $user, int $afterId)
    {
        return $this->userNotifications($user)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit(50)
            ->get();
    }

    private function serializeNotification(Notification $notification): array
    {
        return [
            'id' => (int) $notification->id,
            'title' => $notification->title,
            'message' => $notification->message,
            'type' => $notification->type,
            'link' => $notification->link,
            'is_read' => (bool) $notification->is_read,
            'read_at' => optional($notification->read_at)?->toISOString(),
            'created_at' => optional($notification->created_at)?->toISOString(),
            'updated_at' => optional($notification->updated_at)?->toISOString(),
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
}
