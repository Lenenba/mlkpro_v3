<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Notifications\UserNotificationCenter;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return response()->json(
            app(UserNotificationCenter::class)->pagePayload(
                $user,
                $request->only(['status', 'type', 'per_page'])
            )
        );
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        app(UserNotificationCenter::class)->markAllHeaderReadAndArchive($user);

        return response()->json(['message' => 'Notifications marquees comme lues.']);
    }

    public function markRead(Request $request, DatabaseNotification $notification)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        if (! app(UserNotificationCenter::class)->belongsTo($user, $notification)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        app(UserNotificationCenter::class)->markRead($notification);

        return response()->json(['message' => 'Notification marquee comme lue.']);
    }
}
