<?php

namespace App\Http\Controllers;

use App\Support\Notifications\UserNotificationCenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $payload = app(UserNotificationCenter::class)->pagePayload(
            $user,
            $request->only(['status', 'type', 'per_page'])
        );

        return $this->inertiaOrJson('Notifications/Index', [
            'notification_history' => $payload['notifications'],
            'history_filters' => $payload['filters'],
            'history_stats' => $payload['stats'],
            'history_type_options' => $payload['type_options'],
            'history_per_page_options' => $payload['per_page_options'],
        ]);
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user) {
            app(UserNotificationCenter::class)->markAllHeaderReadAndArchive($user);
        }

        return redirect()->back();
    }

    public function markRead(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $user = $request->user();
        if (! $user || ! app(UserNotificationCenter::class)->belongsTo($user, $notification)) {
            abort(404);
        }

        app(UserNotificationCenter::class)->markRead($notification);

        return redirect()->back();
    }

    public function archive(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $user = $request->user();
        if (! $user || ! app(UserNotificationCenter::class)->belongsTo($user, $notification)) {
            abort(404);
        }

        app(UserNotificationCenter::class)->archive($notification);

        return redirect()->back();
    }

    public function restore(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $user = $request->user();
        if (! $user || ! app(UserNotificationCenter::class)->belongsTo($user, $notification)) {
            abort(404);
        }

        app(UserNotificationCenter::class)->restore($notification);

        return redirect()->back();
    }

    public function open(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $user = $request->user();
        if (! $user || ! app(UserNotificationCenter::class)->belongsTo($user, $notification)) {
            abort(404);
        }

        $source = $request->query('source') === 'header' ? 'header' : 'history';
        if ($source === 'header') {
            app(UserNotificationCenter::class)->markReadAndArchive($notification);
        } else {
            app(UserNotificationCenter::class)->markRead($notification);
        }

        $actionUrl = data_get($notification->data ?? [], 'action_url');

        return redirect()->to($actionUrl ?: route('notifications.index'));
    }
}
