<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\User;

class LocalePreference
{
    /**
     * @var array<int, string>
     */
    private const DEFAULT_SUPPORTED = ['fr', 'en'];

    /**
     * @return array<int, string>
     */
    public static function supported(): array
    {
        $configured = config('app.supported_locales', self::DEFAULT_SUPPORTED);
        if (! is_array($configured)) {
            return self::DEFAULT_SUPPORTED;
        }

        $supported = array_values(array_unique(array_filter(array_map(
            fn ($locale) => strtolower(trim((string) $locale)),
            $configured
        ))));

        return $supported !== [] ? $supported : self::DEFAULT_SUPPORTED;
    }

    public static function default(): string
    {
        $value = strtolower(trim((string) config('app.locale', self::DEFAULT_SUPPORTED[0])));

        return in_array($value, self::supported(), true)
            ? $value
            : self::supported()[0];
    }

    public static function fallback(): string
    {
        $value = strtolower(trim((string) config('app.fallback_locale', 'en')));
        if (in_array($value, self::supported(), true)) {
            return $value;
        }

        return in_array('en', self::supported(), true)
            ? 'en'
            : self::default();
    }

    /**
     * @return array<int, string>
     */
    public static function resolutionOrder(?string $locale = null): array
    {
        $requested = strtolower(trim((string) $locale));
        $supported = self::supported();
        $candidates = [];

        if (in_array($requested, $supported, true)) {
            $candidates[] = $requested;
        }

        $candidates[] = self::fallback();
        $candidates[] = self::default();

        return array_values(array_unique(array_merge($candidates, $supported)));
    }

    public static function firstStoredLocale(array $storedLocales, ?string $preferredLocale = null): ?string
    {
        foreach (self::resolutionOrder($preferredLocale) as $candidate) {
            $value = $storedLocales[$candidate] ?? null;
            if (is_array($value) && $value !== []) {
                return $candidate;
            }
        }

        return null;
    }

    public static function normalize(?string $locale): string
    {
        $value = strtolower(trim((string) $locale));

        return in_array($value, self::supported(), true)
            ? $value
            : self::default();
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
