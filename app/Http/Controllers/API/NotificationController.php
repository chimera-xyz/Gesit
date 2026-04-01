<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    /**
     * Get all notifications for authenticated user
     */
    public function index(Request $request)
    {
        try {
            $notifications = Notification::where('user_id', auth()->id())
                ->orderBy('created_at', 'desc')
                ->paginate(20);

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
     * Mark notification as read
     */
    public function markAsRead($id)
    {
        try {
            $notification = Notification::where('user_id', auth()->id())
                ->findOrFail($id);

            $notification->is_read = true;
            $notification->save();

            return response()->json([
                'success' => true,
                'notification' => $notification,
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
                ->update(['is_read' => true]);

            return response()->json([
                'success' => true,
                'message' => 'Semua notifikasi ditandai sebagai dibaca',
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
            $count = Notification::where('user_id', auth()->id())
                ->where('is_read', false)
                ->count();

            return response()->json([
                'unread_count' => $count,
            ]);
        } catch (\Exception $e) {
            Log::error('Get Unread Count Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
