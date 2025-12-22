<?php

namespace App\Support;

class PlatformPermissions
{
    public const ANALYTICS_VIEW = 'analytics.view';
    public const TENANTS_VIEW = 'tenants.view';
    public const TENANTS_MANAGE = 'tenants.manage';
    public const BILLING_VIEW = 'billing.view';
    public const BILLING_MANAGE = 'billing.manage';
    public const NOTIFICATIONS_MANAGE = 'notifications.manage';
    public const SETTINGS_MANAGE = 'settings.manage';
    public const ADMINS_MANAGE = 'admins.manage';
    public const SUPPORT_IMPERSONATE = 'support.impersonate';
    public const SUPPORT_MANAGE = 'support.manage';
    public const AUDIT_VIEW = 'audit.view';

    public static function all(): array
    {
        return [
            self::ANALYTICS_VIEW,
            self::TENANTS_VIEW,
            self::TENANTS_MANAGE,
            self::BILLING_VIEW,
            self::BILLING_MANAGE,
            self::NOTIFICATIONS_MANAGE,
            self::SETTINGS_MANAGE,
            self::ADMINS_MANAGE,
            self::SUPPORT_IMPERSONATE,
            self::SUPPORT_MANAGE,
            self::AUDIT_VIEW,
        ];
    }

    public static function labels(): array
    {
        return [
            self::ANALYTICS_VIEW => 'Analytics view',
            self::TENANTS_VIEW => 'Tenants view',
            self::TENANTS_MANAGE => 'Tenants manage',
            self::BILLING_VIEW => 'Billing view',
            self::BILLING_MANAGE => 'Billing manage',
            self::NOTIFICATIONS_MANAGE => 'Notifications manage',
            self::SETTINGS_MANAGE => 'Settings manage',
            self::ADMINS_MANAGE => 'Admins manage',
            self::SUPPORT_IMPERSONATE => 'Support impersonate',
            self::SUPPORT_MANAGE => 'Support manage',
            self::AUDIT_VIEW => 'Audit log view',
        ];
    }

    public static function defaultForRole(string $role): array
    {
        return match ($role) {
            'support' => [
                self::TENANTS_VIEW,
                self::SUPPORT_IMPERSONATE,
                self::SUPPORT_MANAGE,
            ],
            'billing' => [
                self::TENANTS_VIEW,
                self::BILLING_VIEW,
                self::BILLING_MANAGE,
            ],
            'ops' => [
                self::TENANTS_VIEW,
                self::TENANTS_MANAGE,
                self::SETTINGS_MANAGE,
                self::AUDIT_VIEW,
            ],
            'analytics' => [
                self::ANALYTICS_VIEW,
                self::TENANTS_VIEW,
            ],
            'content' => [
                self::SETTINGS_MANAGE,
            ],
            default => [
                self::TENANTS_VIEW,
            ],
        };
    }

    public static function roles(): array
    {
        return [
            'support',
            'billing',
            'ops',
            'analytics',
            'content',
        ];
    }
}
