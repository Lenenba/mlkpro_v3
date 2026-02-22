<?php

namespace App\Services;

use App\Models\User;

class ReservationNotificationPreferenceService
{
    private const DEFAULTS = [
        'enabled' => true,
        'email' => true,
        'in_app' => true,
        'sms' => false,
        'notify_on_created' => true,
        'notify_on_rescheduled' => true,
        'notify_on_cancelled' => true,
        'notify_on_completed' => true,
        'notify_on_reminder' => true,
        'notify_on_review_submitted' => true,
        'review_request_on_completed' => true,
        'notify_on_queue_pre_call' => true,
        'notify_on_queue_called' => true,
        'notify_on_queue_grace_expired' => true,
        'notify_on_queue_ticket_created' => true,
        'notify_on_queue_eta_10m' => true,
        'notify_on_queue_status_changed' => false,
        'reminder_hours' => [24, 2],
    ];

    public function resolveFor(User $account): array
    {
        $companySettings = is_array($account->company_notification_settings)
            ? $account->company_notification_settings
            : [];
        $stored = is_array($companySettings['reservations'] ?? null)
            ? $companySettings['reservations']
            : [];

        return $this->normalize(array_replace_recursive(self::DEFAULTS, $stored));
    }

    public function mergeCompanySettings(User $account, array $payload): array
    {
        $companySettings = is_array($account->company_notification_settings)
            ? $account->company_notification_settings
            : [];

        $current = is_array($companySettings['reservations'] ?? null)
            ? $companySettings['reservations']
            : [];

        $companySettings['reservations'] = $this->normalize(array_replace_recursive($current, $payload));

        return $companySettings;
    }

    private function normalize(array $settings): array
    {
        $normalized = [];
        foreach (self::DEFAULTS as $key => $default) {
            if ($key === 'reminder_hours') {
                continue;
            }

            $normalized[$key] = (bool) ($settings[$key] ?? $default);
        }

        $hours = $settings['reminder_hours'] ?? self::DEFAULTS['reminder_hours'];
        if (!is_array($hours)) {
            $hours = self::DEFAULTS['reminder_hours'];
        }

        $hours = collect($hours)
            ->map(fn ($value) => (int) $value)
            ->filter(fn (int $value) => $value >= 1 && $value <= 168)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();

        if (!$hours) {
            $hours = self::DEFAULTS['reminder_hours'];
        }

        $normalized['reminder_hours'] = $hours;

        return $normalized;
    }
}
