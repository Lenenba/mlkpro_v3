<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;

class NotificationPreferenceService
{
    public const CHANNEL_IN_APP = 'in_app';
    public const CHANNEL_PUSH = 'push';

    public const CATEGORY_ORDERS = 'orders';
    public const CATEGORY_SALES = 'sales';
    public const CATEGORY_STOCK = 'stock';
    public const CATEGORY_SYSTEM = 'system';

    public function defaultsFor(User $user): array
    {
        $roleName = $user->relationLoaded('role')
            ? $user->role?->name
            : Role::query()->whereKey($user->role_id)->value('name');

        $teamRole = $user->relationLoaded('teamMembership')
            ? $user->teamMembership?->role
            : $user->teamMembership()->value('role');

        $isClient = $user->isClient();
        $isOwner = $user->isAccountOwner();
        $isSeller = $teamRole === 'seller';
        $isPlatform = $user->isSuperadmin() || $user->isPlatformAdmin();

        $channels = [
            self::CHANNEL_IN_APP => true,
            self::CHANNEL_PUSH => !$isPlatform,
        ];

        if ($roleName === 'admin') {
            $channels[self::CHANNEL_PUSH] = false;
        }

        $categories = [
            self::CATEGORY_ORDERS => $isClient || $isOwner || $isSeller,
            self::CATEGORY_SALES => $isOwner || $isSeller,
            self::CATEGORY_STOCK => $isOwner || $isSeller,
            self::CATEGORY_SYSTEM => true,
        ];

        return [
            'channels' => $channels,
            'categories' => $categories,
        ];
    }

    public function resolveFor(User $user): array
    {
        $defaults = $this->defaultsFor($user);
        $stored = is_array($user->notification_settings) ? $user->notification_settings : [];

        return $this->normalizeSettings(array_replace_recursive($defaults, $stored));
    }

    public function applyUpdate(User $user, array $payload): array
    {
        $current = $this->resolveFor($user);
        $next = array_replace_recursive($current, $payload);
        $next = $this->normalizeSettings($next);

        $user->notification_settings = $next;
        $user->save();

        return $next;
    }

    public function shouldNotify(User $user, string $category, string $channel = self::CHANNEL_IN_APP): bool
    {
        $settings = $this->resolveFor($user);
        $channels = $settings['channels'] ?? [];
        $categories = $settings['categories'] ?? [];

        $channelEnabled = array_key_exists($channel, $channels)
            ? (bool) $channels[$channel]
            : true;
        $categoryEnabled = array_key_exists($category, $categories)
            ? (bool) $categories[$category]
            : true;

        return $channelEnabled && $categoryEnabled;
    }

    private function normalizeSettings(array $settings): array
    {
        $channels = [
            self::CHANNEL_IN_APP => (bool) ($settings['channels'][self::CHANNEL_IN_APP] ?? true),
            self::CHANNEL_PUSH => (bool) ($settings['channels'][self::CHANNEL_PUSH] ?? true),
        ];

        $categories = [
            self::CATEGORY_ORDERS => (bool) ($settings['categories'][self::CATEGORY_ORDERS] ?? true),
            self::CATEGORY_SALES => (bool) ($settings['categories'][self::CATEGORY_SALES] ?? true),
            self::CATEGORY_STOCK => (bool) ($settings['categories'][self::CATEGORY_STOCK] ?? true),
            self::CATEGORY_SYSTEM => (bool) ($settings['categories'][self::CATEGORY_SYSTEM] ?? true),
        ];

        return [
            'channels' => $channels,
            'categories' => $categories,
        ];
    }
}
