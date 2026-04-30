<?php

namespace App\Services;

use App\Models\User;

class CompanyNotificationPreferenceService
{
    public const KEY_PREFERRED_CHANNEL = 'preferred_channel';

    public const KEY_ALERTS = 'alerts';

    public const CATEGORY_TASK_DAY = 'task_day';

    public const CATEGORY_TASK_UPDATES = 'task_updates';

    public const CATEGORY_RESERVATIONS = 'reservations';

    public const CATEGORY_ORDERS = 'orders';

    public const CATEGORY_SALES = 'sales';

    public const CATEGORY_STOCK = 'stock';

    public const CATEGORY_PLANNING = 'planning';

    public const CATEGORY_BILLING = 'billing';

    public const CATEGORY_EXPENSES = 'expenses';

    public const CATEGORY_CRM = 'crm';

    public const CATEGORY_SUPPORT = 'support';

    public const CATEGORY_SECURITY = 'security';

    public const CATEGORY_EMAILS_MIRROR = 'emails_mirror';

    public const CATEGORY_SYSTEM = 'system';

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
        $next = $this->syncLegacyTaskChannels($next, $payload);
        $next = $this->syncReservationChannels($next, $payload);

        return $this->normalize($next, $this->defaults());
    }

    public function preferredChannel(User $user): string
    {
        $settings = $this->resolveFor($user);

        return (string) ($settings[self::KEY_PREFERRED_CHANNEL] ?? self::CHANNEL_EMAIL);
    }

    public function alertChannels(User $user, string $category): array
    {
        $settings = $this->resolveFor($user);
        $channels = $settings[self::KEY_ALERTS][$category] ?? [];

        return $this->normalizeDeliveryChannels(
            is_array($channels) ? $channels : [],
            $this->defaultDeliveryChannels()
        );
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

    public function taskUpdateChannels(User $user): array
    {
        $settings = $this->resolveFor($user);
        $channels = $settings[self::CATEGORY_TASK_UPDATES] ?? [];

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
        $alerts = $this->defaultAlerts();

        return [
            self::KEY_PREFERRED_CHANNEL => self::CHANNEL_EMAIL,
            self::KEY_ALERTS => $alerts,
            self::CATEGORY_TASK_DAY => [
                self::CHANNEL_EMAIL => true,
                self::CHANNEL_SMS => false,
                self::CHANNEL_WHATSAPP => false,
            ],
            self::CATEGORY_TASK_UPDATES => [
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

        $preferredChannel = (string) ($settings[self::KEY_PREFERRED_CHANNEL] ?? $defaults[self::KEY_PREFERRED_CHANNEL]);
        $normalized[self::KEY_PREFERRED_CHANNEL] = in_array($preferredChannel, $this->deliveryChannels(), true)
            ? $preferredChannel
            : self::CHANNEL_EMAIL;

        $alertSettings = is_array($settings[self::KEY_ALERTS] ?? null) ? $settings[self::KEY_ALERTS] : [];
        $normalized[self::KEY_ALERTS] = $alertSettings;

        foreach ($defaults[self::KEY_ALERTS] as $category => $channels) {
            $categorySettings = is_array($alertSettings[$category] ?? null) ? $alertSettings[$category] : [];
            if ($categorySettings === [] && in_array($category, [self::CATEGORY_TASK_DAY, self::CATEGORY_TASK_UPDATES], true)) {
                $categorySettings = is_array($settings[$category] ?? null) ? $settings[$category] : [];
            }

            $normalized[self::KEY_ALERTS][$category] = $this->normalizeDeliveryChannels($categorySettings, $channels);
        }

        foreach ($defaults as $category => $channels) {
            if (in_array($category, [self::KEY_PREFERRED_CHANNEL, self::KEY_ALERTS], true)) {
                continue;
            }

            $categorySettings = is_array($settings[$category] ?? null) ? $settings[$category] : [];
            $normalized[$category] = $categorySettings;
            foreach ($channels as $channel => $default) {
                $normalized[$category][$channel] = (bool) ($categorySettings[$channel] ?? $default);
            }
        }

        $normalized[self::CATEGORY_TASK_DAY] = $normalized[self::KEY_ALERTS][self::CATEGORY_TASK_DAY];
        $normalized[self::CATEGORY_TASK_UPDATES] = $normalized[self::KEY_ALERTS][self::CATEGORY_TASK_UPDATES];

        return $normalized;
    }

    private function defaultAlerts(): array
    {
        $alerts = [];

        foreach ($this->alertCategories() as $category) {
            $alerts[$category] = $this->defaultDeliveryChannels();
        }

        return $alerts;
    }

    private function defaultDeliveryChannels(): array
    {
        return [
            self::CHANNEL_EMAIL => true,
            self::CHANNEL_SMS => false,
            self::CHANNEL_WHATSAPP => false,
        ];
    }

    private function normalizeDeliveryChannels(array $channels, array $defaults): array
    {
        return [
            self::CHANNEL_EMAIL => (bool) ($channels[self::CHANNEL_EMAIL] ?? $defaults[self::CHANNEL_EMAIL]),
            self::CHANNEL_SMS => (bool) ($channels[self::CHANNEL_SMS] ?? $defaults[self::CHANNEL_SMS]),
            self::CHANNEL_WHATSAPP => (bool) ($channels[self::CHANNEL_WHATSAPP] ?? $defaults[self::CHANNEL_WHATSAPP]),
        ];
    }

    private function deliveryChannels(): array
    {
        return [
            self::CHANNEL_EMAIL,
            self::CHANNEL_SMS,
            self::CHANNEL_WHATSAPP,
        ];
    }

    private function alertCategories(): array
    {
        return [
            self::CATEGORY_TASK_DAY,
            self::CATEGORY_TASK_UPDATES,
            self::CATEGORY_RESERVATIONS,
            self::CATEGORY_ORDERS,
            self::CATEGORY_SALES,
            self::CATEGORY_STOCK,
            self::CATEGORY_PLANNING,
            self::CATEGORY_BILLING,
            self::CATEGORY_EXPENSES,
            self::CATEGORY_CRM,
            self::CATEGORY_SUPPORT,
            self::CATEGORY_SECURITY,
            self::CATEGORY_EMAILS_MIRROR,
            self::CATEGORY_SYSTEM,
        ];
    }

    private function syncLegacyTaskChannels(array $settings, array $payload): array
    {
        foreach ([self::CATEGORY_TASK_DAY, self::CATEGORY_TASK_UPDATES] as $category) {
            if (array_key_exists($category, $payload) && is_array($payload[$category] ?? null)) {
                $settings[self::KEY_ALERTS][$category] = $payload[$category];

                continue;
            }

            if (
                is_array($payload[self::KEY_ALERTS] ?? null)
                && array_key_exists($category, $payload[self::KEY_ALERTS])
                && is_array($payload[self::KEY_ALERTS][$category] ?? null)
            ) {
                $settings[$category] = $payload[self::KEY_ALERTS][$category];
            }
        }

        return $settings;
    }

    private function syncReservationChannels(array $settings, array $payload): array
    {
        $channels = $payload[self::KEY_ALERTS][self::CATEGORY_RESERVATIONS] ?? null;
        if (! is_array($channels)) {
            return $settings;
        }

        $settings[self::CATEGORY_RESERVATIONS] = is_array($settings[self::CATEGORY_RESERVATIONS] ?? null)
            ? $settings[self::CATEGORY_RESERVATIONS]
            : [];
        $settings[self::CATEGORY_RESERVATIONS][self::CHANNEL_EMAIL] = (bool) ($channels[self::CHANNEL_EMAIL] ?? true);
        $settings[self::CATEGORY_RESERVATIONS][self::CHANNEL_SMS] = (bool) ($channels[self::CHANNEL_SMS] ?? false);
        $settings[self::CATEGORY_RESERVATIONS][self::CHANNEL_WHATSAPP] = (bool) ($channels[self::CHANNEL_WHATSAPP] ?? false);

        return $settings;
    }
}
