<?php

namespace App\Services;

use App\Models\User;

class CompanyNotificationPreferenceService
{
    public const CATEGORY_TASK_DAY = 'task_day';

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SMS = 'sms';
    public const CHANNEL_WHATSAPP = 'whatsapp';

    public function resolveFor(User $user): array
    {
        $defaults = [
            self::CATEGORY_TASK_DAY => [
                self::CHANNEL_EMAIL => true,
                self::CHANNEL_SMS => false,
                self::CHANNEL_WHATSAPP => false,
            ],
        ];

        $stored = is_array($user->company_notification_settings) ? $user->company_notification_settings : [];
        $merged = array_replace_recursive($defaults, $stored);

        return $this->normalize($merged, $defaults);
    }

    public function mergeSettings(User $user, array $payload): array
    {
        $current = $this->resolveFor($user);
        $next = array_replace_recursive($current, $payload);

        return $this->normalize($next, $current);
    }

    public function taskDayChannels(User $user): array
    {
        $settings = $this->resolveFor($user);
        $channels = $settings[self::CATEGORY_TASK_DAY] ?? [];

        return [
            self::CHANNEL_EMAIL => (bool) ($channels[self::CHANNEL_EMAIL] ?? true),
            self::CHANNEL_SMS => (bool) ($channels[self::CHANNEL_SMS] ?? false),
            self::CHANNEL_WHATSAPP => (bool) ($channels[self::CHANNEL_WHATSAPP] ?? false),
        ];
    }

    private function normalize(array $settings, array $defaults): array
    {
        $normalized = [];
        foreach ($defaults as $category => $channels) {
            $normalized[$category] = [];
            foreach ($channels as $channel => $default) {
                $normalized[$category][$channel] = (bool) ($settings[$category][$channel] ?? $default);
            }
        }

        return $normalized;
    }
}
