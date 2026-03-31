<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\User;

class LocalePreference
{
    /**
     * @var array<int, string>
     */
    private const SUPPORTED = ['fr', 'en'];

    public static function normalize(?string $locale): string
    {
        $value = strtolower(trim((string) $locale));

        return in_array($value, self::SUPPORTED, true)
            ? $value
            : config('app.locale', 'fr');
    }

    public static function forUser(?User $user): string
    {
        return self::normalize($user?->locale);
    }

    public static function forCustomer(?Customer $customer, ?User $fallbackOwner = null): string
    {
        if (! $customer) {
            return self::forUser($fallbackOwner);
        }

        $portalUser = $customer->relationLoaded('portalUser')
            ? $customer->portalUser
            : $customer->portalUser()->select(['id', 'locale'])->first();

        if ($portalUser instanceof User && filled($portalUser->locale)) {
            return self::forUser($portalUser);
        }

        $owner = $customer->relationLoaded('user')
            ? $customer->user
            : $customer->user()->select(['id', 'locale'])->first();

        if ($owner instanceof User) {
            return self::forUser($owner);
        }

        return self::forUser($fallbackOwner);
    }

    public static function forNotifiable(mixed $notifiable, ?User $fallbackOwner = null): string
    {
        if ($notifiable instanceof Customer) {
            return self::forCustomer($notifiable, $fallbackOwner);
        }

        if ($notifiable instanceof User) {
            return self::forUser($notifiable);
        }

        return self::forUser($fallbackOwner);
    }

    public static function trans(string $key, array $replace = [], ?string $locale = null): string
    {
        return __($key, $replace, self::normalize($locale));
    }
}
