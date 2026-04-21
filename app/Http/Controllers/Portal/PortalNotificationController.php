<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Support\Notifications\UserNotificationCenter;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class PortalNotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        return response()->json(
            app(UserNotificationCenter::class)->pagePayload(
                $user,
                [
                    'status' => $request->input('status'),
                    'type' => $request->input('type'),
                    'per_page' => $request->input('per_page', $request->input('limit', 30)),
                ]
            )
        );
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        app(UserNotificationCenter::class)->markAllHeaderReadAndArchive($user);

        return response()->json([
            'message' => 'Notifications marquees comme lues.',
        ]);
    }

    public function markRead(Request $request, DatabaseNotification $notification)
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        if (! app(UserNotificationCenter::class)->belongsTo($user, $notification)) {
            abort(404);
        }

        app(UserNotificationCenter::class)->markRead($notification);

        return response()->json([
            'message' => 'Notification marquee comme lue.',
        ]);
    }
}
