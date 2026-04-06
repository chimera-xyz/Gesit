<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class NotificationController extends Controller
{
    /**
     * Get all notifications for authenticated user
     */
    public function index(Request $request)
    {
        try {
            $query = Notification::query()
                ->where('user_id', auth()->id())
                ->orderByDesc('created_at');

            if ($request->boolean('unread_only')) {
                $query->where('is_read', false);
            }

            $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);
            $notifications = $query->paginate($perPage);

            $unreadCount = Notification::where('user_id', auth()->id())
                ->where('is_read', false)
                ->count();

            return response()->json([
                'notifications' => $notifications->items(),
                'unread_count' => $unreadCount,
                'pagination' => [
                    'current_page' => $notifications->currentPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
                    'last_page' => $notifications->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get Notifications Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get unread notifications for popup delivery.
     */
    public function unreadFeed()
    {
        try {
            $notifications = Notification::query()
                ->where('user_id', auth()->id())
                ->where('is_read', false)
                ->orderBy('created_at')
                ->get();

            return response()->json([
                'notifications' => $notifications,
                'unread_count' => $notifications->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Get Unread Notification Feed Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Mark notification as read
     */
    public function markAsRead($id)
    {
        try {
            $notification = Notification::where('user_id', auth()->id())
                ->findOrFail($id);

            if (!$notification->is_read) {
                $notification->markAsRead();
            }

            return response()->json([
                'success' => true,
                'notification' => $notification,
                'unread_count' => $this->countUnreadNotifications(),
            ]);
        } catch (\Exception $e) {
            Log::error('Mark Notification as Read Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead()
    {
        try {
            Notification::where('user_id', auth()->id())
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
        } catch (\Exception $e) {
            Log::error('Mark All Notifications as Read Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete notification
     */
    public function destroy($id)
    {
        try {
            $notification = Notification::where('user_id', auth()->id())
                ->findOrFail($id);

            $notification->delete();

            return response()->json([
                'success' => true,
                'message' => 'Notifikasi berhasil dihapus',
                'unread_count' => $this->countUnreadNotifications(),
            ]);
        } catch (\Exception $e) {
            Log::error('Delete Notification Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get unread notifications count
     */
    public function unreadCount()
    {
        try {
            return response()->json([
                'unread_count' => $this->countUnreadNotifications(),
            ]);
        } catch (\Exception $e) {
            Log::error('Get Unread Count Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function countUnreadNotifications(): int
    {
        return Notification::query()
            ->where('user_id', auth()->id())
            ->where('is_read', false)
            ->count();
    }
}
