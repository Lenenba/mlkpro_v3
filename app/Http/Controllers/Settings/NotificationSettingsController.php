<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\CompanyNotificationPreferenceService;
use App\Services\NotificationPreferenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class NotificationSettingsController extends Controller
{
    public function edit(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $service = app(NotificationPreferenceService::class);
        $settings = $service->resolveFor($user);
        $companyNotificationSettings = $user->isAccountOwner()
            ? app(CompanyNotificationPreferenceService::class)->resolveFor($user)
            : (object) [];

        return $this->inertiaOrJson('Settings/Notifications', [
            'settings' => $settings,
            'can_manage_company_notifications' => $user->isAccountOwner(),
            'company_notification_settings' => $companyNotificationSettings,
            'whatsapp_configured' => $this->whatsappConfigured(),
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $validated = $request->validate([
            'channels' => 'array',
            'channels.in_app' => 'boolean',
            'channels.push' => 'boolean',
            'categories' => 'array',
            'categories.orders' => 'boolean',
            'categories.sales' => 'boolean',
            'categories.stock' => 'boolean',
            'categories.planning' => 'boolean',
            'categories.billing' => 'boolean',
            'categories.crm' => 'boolean',
            'categories.support' => 'boolean',
            'categories.security' => 'boolean',
            'categories.emails_mirror' => 'boolean',
            'categories.system' => 'boolean',
            'company_notification_settings' => 'nullable|array',
            'company_notification_settings.preferred_channel' => ['nullable', 'string', Rule::in([
                CompanyNotificationPreferenceService::CHANNEL_EMAIL,
                CompanyNotificationPreferenceService::CHANNEL_SMS,
                CompanyNotificationPreferenceService::CHANNEL_WHATSAPP,
            ])],
            'company_notification_settings.alerts' => 'nullable|array',
            'company_notification_settings.alerts.*' => 'nullable|array',
            'company_notification_settings.alerts.*.email' => 'nullable|boolean',
            'company_notification_settings.alerts.*.sms' => 'nullable|boolean',
            'company_notification_settings.alerts.*.whatsapp' => 'nullable|boolean',
            'company_notification_settings.task_day' => 'nullable|array',
            'company_notification_settings.task_day.email' => 'nullable|boolean',
            'company_notification_settings.task_day.sms' => 'nullable|boolean',
            'company_notification_settings.task_day.whatsapp' => 'nullable|boolean',
            'company_notification_settings.task_updates' => 'nullable|array',
            'company_notification_settings.task_updates.email' => 'nullable|boolean',
            'company_notification_settings.task_updates.sms' => 'nullable|boolean',
            'company_notification_settings.task_updates.whatsapp' => 'nullable|boolean',
            'company_notification_settings.security' => 'nullable|array',
            'company_notification_settings.security.two_factor_sms' => 'nullable|boolean',
        ]);

        if (array_key_exists('company_notification_settings', $validated) && ! $user->isAccountOwner()) {
            abort(403);
        }

        $payload = [
            'channels' => Arr::only($validated['channels'] ?? [], ['in_app', 'push']),
            'categories' => Arr::only($validated['categories'] ?? [], [
                'orders',
                'sales',
                'stock',
                'planning',
                'billing',
                'crm',
                'support',
                'security',
                'emails_mirror',
                'system',
            ]),
        ];

        $settings = app(NotificationPreferenceService::class)->applyUpdate($user, $payload);
        $companyNotificationSettings = null;
        if ($user->isAccountOwner() && array_key_exists('company_notification_settings', $validated)) {
            $companyNotificationSettings = app(CompanyNotificationPreferenceService::class)
                ->mergeSettings($user, $validated['company_notification_settings'] ?? []);

            $user->company_notification_settings = $companyNotificationSettings;
            $user->save();
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Preferences mises a jour.',
                'settings' => $settings,
                'company_notification_settings' => $companyNotificationSettings,
            ]);
        }

        return redirect()
            ->back()
            ->with('success', 'Preferences mises a jour.');
    }

    private function whatsappConfigured(): bool
    {
        return (bool) config('services.twilio.sid')
            && (bool) config('services.twilio.token')
            && (bool) config('services.twilio.whatsapp_from');
    }
}
