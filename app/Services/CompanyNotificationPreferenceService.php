<?php

namespace App\Services;

use App\Models\User;

class CompanyNotificationPreferenceService
{
    public const CATEGORY_TASK_DAY = 'task_day';
    public const CATEGORY_SECURITY = 'security';

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SMS = 'sms';
    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const CHANNEL_TWO_FACTOR_SMS = 'two_factor_sms';

    public function resolveFor(User $user): array
    {
        $stored = is_array($user->company_notification_settings) ? $user->company_notification_settings : [];

        return $this->normalize($stored, $this->defaults());
    }

    public function mergeSettings(User $user, array $payload): array
    {
        $current = is_array($user->company_notification_settings) ? $user->company_notification_settings : [];
        $next = array_replace_recursive($current, $payload);

        return $this->normalize($next, $this->defaults());
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

    public function twoFactorSmsEnabled(User $user): bool
    {
        $settings = $this->resolveFor($user);
        $security = $settings[self::CATEGORY_SECURITY] ?? [];

        return (bool) ($security[self::CHANNEL_TWO_FACTOR_SMS] ?? false);
    }

    private function defaults(): array
    {
        return [
            self::CATEGORY_TASK_DAY => [
                self::CHANNEL_EMAIL => true,
                self::CHANNEL_SMS => false,
                self::CHANNEL_WHATSAPP => false,
            ],
            self::CATEGORY_SECURITY => [
                self::CHANNEL_TWO_FACTOR_SMS => false,
            ],
        ];
    }

    private function normalize(array $settings, array $defaults): array
    {
        $normalized = $settings;

        foreach ($defaults as $category => $channels) {
            $categorySettings = is_array($settings[$category] ?? null) ? $settings[$category] : [];
            $normalized[$category] = $categorySettings;
            foreach ($channels as $channel => $default) {
                $normalized[$category][$channel] = (bool) ($categorySettings[$channel] ?? $default);
            }
        }

        return $normalized;
    }
}
