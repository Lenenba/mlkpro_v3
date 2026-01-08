<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class PortalNotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $limit = (int) $request->input('limit', 30);
        $limit = max(5, min(100, $limit));

        $notifications = $user->notifications()
            ->latest()
            ->limit($limit)
            ->get();

        $unreadCount = $user->unreadNotifications()->count();

        return response()->json([
            'notifications' => $notifications->map(fn(DatabaseNotification $notification) => [
                'id' => $notification->id,
                'type' => $notification->type,
                'data' => $notification->data,
                'read_at' => $notification->read_at?->toIso8601String(),
                'created_at' => $notification->created_at?->toIso8601String(),
            ])->values(),
            'unread_count' => $unreadCount,
        ]);
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $user->unreadNotifications()->update(['read_at' => now()]);

        return response()->json([
            'message' => 'Notifications marquees comme lues.',
        ]);
    }

    public function markRead(Request $request, DatabaseNotification $notification)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        if ($notification->notifiable_id !== $user->id) {
            abort(404);
        }

        if (!$notification->read_at) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return response()->json([
            'message' => 'Notification marquee comme lue.',
        ]);
    }
}
