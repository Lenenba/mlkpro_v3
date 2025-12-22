<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Models\PlatformNotificationSetting;
use App\Support\PlatformPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends BaseSuperAdminController
{
    private const CHANNELS = [
        'email',
        'slack',
        'webhook',
        'sms',
    ];

    private const CATEGORIES = [
        'payment_failed',
        'churn_risk',
        'error_spike',
        'new_account',
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
                    'categories' => ['payment_failed', 'error_spike'],
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
            'channels.*' => 'string|in:' . implode(',', self::CHANNELS),
            'categories' => 'nullable|array',
            'categories.*' => 'string|in:' . implode(',', self::CATEGORIES),
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
}
