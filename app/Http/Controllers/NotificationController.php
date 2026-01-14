<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function markAllRead(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user) {
            $user->unreadNotifications()->update(['read_at' => now()]);
        }

        return redirect()->back();
    }

    public function markRead(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $user = $request->user();
        if (!$user || $notification->notifiable_id !== $user->id) {
            abort(403);
        }

        if (!$notification->read_at) {
            $notification->markAsRead();
        }

        return redirect()->back();
    }
}
