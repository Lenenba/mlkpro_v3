<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Models\PlatformNotification;
use App\Models\PlatformNotificationSetting;
use App\Notifications\PlatformAdminDigestNotification;
use App\Support\PlatformPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends BaseSuperAdminController
{
    private const RECAP_DAYS = 7;

    private const CHANNELS = [
        'email',
        'slack',
        'webhook',
        'sms',
    ];

    private const CATEGORIES = [
        'new_account',
        'onboarding_completed',
        'subscription_started',
        'plan_changed',
        'subscription_paused',
        'subscription_resumed',
        'subscription_canceled',
        'payment_succeeded',
        'payment_failed',
        'churn_risk',
        'operational_health',
        'error_spike',
        'abuse_security',
    ];

    public function edit(Request $request): Response
    {
        $this->authorizePermission($request, PlatformPermissions::NOTIFICATIONS_MANAGE);

        $user = $request->user();
        $settings = PlatformNotificationSetting::query()
            ->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'channels' => ['email'],
                    'categories' => [
                        'new_account',
                        'onboarding_completed',
                        'subscription_started',
                        'plan_changed',
                        'subscription_paused',
                        'subscription_resumed',
                        'subscription_canceled',
                        'payment_succeeded',
                        'payment_failed',
                        'churn_risk',
                    ],
                    'rules' => [
                        'error_spike' => 10,
                        'payment_failed' => 3,
                        'churn_risk' => 5,
                    ],
                    'digest_frequency' => 'daily',
                ]
            );

        return Inertia::render('SuperAdmin/Notifications/Edit', [
            'settings' => [
                'channels' => $settings->channels ?? [],
                'categories' => $settings->categories ?? [],
                'rules' => $settings->rules ?? [],
                'digest_frequency' => $settings->digest_frequency,
                'quiet_hours_start' => $settings->quiet_hours_start,
                'quiet_hours_end' => $settings->quiet_hours_end,
            ],
            'available_channels' => self::CHANNELS,
            'available_categories' => self::CATEGORIES,
            'digest_options' => ['immediate', 'daily', 'weekly'],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::NOTIFICATIONS_MANAGE);

        $validated = $request->validate([
            'channels' => 'nullable|array',
            'channels.*' => 'string|in:'.implode(',', self::CHANNELS),
            'categories' => 'nullable|array',
            'categories.*' => 'string|in:'.implode(',', self::CATEGORIES),
            'digest_frequency' => 'required|string|in:immediate,daily,weekly',
            'quiet_hours_start' => 'nullable|string|max:10',
            'quiet_hours_end' => 'nullable|string|max:10',
            'rules' => 'nullable|array',
            'rules.error_spike' => 'nullable|integer|min:0|max:10000',
            'rules.payment_failed' => 'nullable|integer|min:0|max:10000',
            'rules.churn_risk' => 'nullable|integer|min:0|max:10000',
        ]);

        PlatformNotificationSetting::query()->updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'channels' => array_values($validated['channels'] ?? []),
                'categories' => array_values($validated['categories'] ?? []),
                'rules' => $validated['rules'] ?? [],
                'digest_frequency' => $validated['digest_frequency'],
                'quiet_hours_start' => $validated['quiet_hours_start'] ?? null,
                'quiet_hours_end' => $validated['quiet_hours_end'] ?? null,
            ]
        );

        $this->logAudit($request, 'notifications.updated');

        return redirect()->back()->with('success', 'Notification preferences saved.');
    }

    /**
     * Send an on-demand recap email of the recent platform notifications to the
     * current super admin. Useful when the scheduled daily digest was not received.
     * Sent inline (synchronously) so a delivery failure surfaces immediately, and
     * notifications are left untouched (sent_at is not modified).
     */
    public function sendRecap(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::NOTIFICATIONS_MANAGE);

        $user = $request->user();

        $items = PlatformNotification::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDays(self::RECAP_DAYS))
            ->orderBy('created_at')
            ->get()
            ->map(fn (PlatformNotification $item) => [
                'title' => $item->title,
                'category' => $item->category,
                'intro' => $item->intro,
                'created_at' => $item->created_at,
            ])
            ->all();

        if ($items === []) {
            return redirect()->back()->with('warning', 'No notifications to recap from the last '.self::RECAP_DAYS.' days.');
        }

        try {
            // sendNow bypasses the queue even though the notification is ShouldQueue.
            Notification::sendNow($user, new PlatformAdminDigestNotification('daily', $items));
        } catch (\Throwable $e) {
            Log::warning('Notification recap email failed.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()->with('error', 'Could not send the recap email. Please try again later.');
        }

        $this->logAudit($request, 'notifications.recap_sent');

        return redirect()->back()->with('success', 'Recap email sent to '.$user->email.'.');
    }
}
