<?php

namespace App\Support;

use App\Models\User;

class TenantPaymentMethodsResolver
{
    private const DEFAULT_INTERNAL_METHODS = ['cash', 'card'];

    private const ALLOWED_INTERNAL_METHODS = ['cash', 'card', 'bank_transfer', 'check'];

    private const ALLOWED_CASH_CONTEXTS = ['reservation', 'invoice', 'store_order', 'tip', 'walk_in'];

    private const INTERNAL_TO_BUSINESS = [
        'cash' => 'cash',
        'card' => 'stripe',
        'bank_transfer' => 'other',
        'check' => 'other',
    ];

    private const BUSINESS_TO_INTERNAL = [
        'stripe' => ['card'],
        'cash' => ['cash'],
        'other' => ['bank_transfer', 'check'],
    ];

    public static function defaults(): array
    {
        $internal = self::DEFAULT_INTERNAL_METHODS;

        return self::buildPayload(
            $internal,
            $internal[0] ?? null,
            self::ALLOWED_CASH_CONTEXTS
        );
    }

    public static function forAccountId(?int $accountId): array
    {
        if (!$accountId || $accountId <= 0) {
            return self::defaults();
        }

        $owner = User::query()
            ->select([
                'id',
                'payment_methods',
                'default_payment_method',
                'cash_allowed_contexts',
            ])
            ->find($accountId);

        if (!$owner) {
            return self::defaults();
        }

        return self::forUser($owner);
    }

    public static function forUser(?User $user): array
    {
        if (!$user) {
            return self::defaults();
        }

        $internal = self::sanitizeInternalMethods($user->payment_methods ?? null);
        if (empty($internal)) {
            $internal = self::DEFAULT_INTERNAL_METHODS;
        }

        $defaultInternal = self::normalizeInternalMethod($user->default_payment_method ?? null);
        if (!$defaultInternal || !in_array($defaultInternal, $internal, true)) {
            $defaultInternal = $internal[0] ?? null;
        }

        $cashContexts = is_array($user->cash_allowed_contexts)
            ? self::sanitizeCashContexts($user->cash_allowed_contexts)
            : self::ALLOWED_CASH_CONTEXTS;

        return self::buildPayload($internal, $defaultInternal, $cashContexts);
    }

    public static function allowedInternalMethods(): array
    {
        return self::ALLOWED_INTERNAL_METHODS;
    }

    public static function allowedCashContexts(): array
    {
        return self::ALLOWED_CASH_CONTEXTS;
    }

    public static function normalizeInternalMethod(mixed $method): ?string
    {
        if (!is_string($method)) {
            return null;
        }

        $normalized = strtolower(trim($method));
        if ($normalized === '') {
            return null;
        }

        // Business alias accepted for compatibility with future payloads.
        if ($normalized === 'stripe') {
            $normalized = 'card';
        }

        if (!in_array($normalized, self::ALLOWED_INTERNAL_METHODS, true)) {
            return null;
        }

        return $normalized;
    }

    public static function sanitizeInternalMethods(mixed $methods): array
    {
        if (!is_array($methods)) {
            return [];
        }

        $normalized = [];
        foreach ($methods as $method) {
            $internal = self::normalizeInternalMethod($method);
            if (!$internal) {
                continue;
            }

            if (in_array($internal, $normalized, true)) {
                continue;
            }

            $normalized[] = $internal;
        }

        return $normalized;
    }

    public static function sanitizeCashContexts(mixed $contexts): array
    {
        if (!is_array($contexts)) {
            return self::ALLOWED_CASH_CONTEXTS;
        }

        $normalized = [];
        foreach ($contexts as $context) {
            if (!is_string($context)) {
                continue;
            }

            $value = strtolower(trim($context));
            if ($value === '' || !in_array($value, self::ALLOWED_CASH_CONTEXTS, true)) {
                continue;
            }

            if (in_array($value, $normalized, true)) {
                continue;
            }

            $normalized[] = $value;
        }

        return $normalized;
    }

    public static function businessMethodForInternal(?string $internalMethod): ?string
    {
        if (!$internalMethod) {
            return null;
        }

        $normalized = self::normalizeInternalMethod($internalMethod);
        if (!$normalized) {
            return null;
        }

        return self::INTERNAL_TO_BUSINESS[$normalized] ?? null;
    }

    public static function internalMethodsForBusiness(mixed $businessMethod): array
    {
        if (!is_string($businessMethod)) {
            return [];
        }

        $normalized = strtolower(trim($businessMethod));
        if ($normalized === '') {
            return [];
        }

        return self::BUSINESS_TO_INTERNAL[$normalized] ?? [];
    }

    public static function toBusinessMethods(array $internalMethods): array
    {
        $business = [];
        foreach ($internalMethods as $internal) {
            $mapped = self::businessMethodForInternal($internal);
            if (!$mapped) {
                continue;
            }

            if (in_array($mapped, $business, true)) {
                continue;
            }

            $business[] = $mapped;
        }

        return $business;
    }

    private static function buildPayload(array $internal, ?string $defaultInternal, array $cashContexts): array
    {
        $enabledInternal = self::sanitizeInternalMethods($internal);
        if (empty($enabledInternal)) {
            $enabledInternal = self::DEFAULT_INTERNAL_METHODS;
        }

        $normalizedDefault = self::normalizeInternalMethod($defaultInternal);
        if (!$normalizedDefault || !in_array($normalizedDefault, $enabledInternal, true)) {
            $normalizedDefault = $enabledInternal[0] ?? null;
        }

        $enabledBusiness = self::toBusinessMethods($enabledInternal);

        return [
            'enabled_methods' => $enabledBusiness,
            'enabled_methods_internal' => $enabledInternal,
            'default_method' => self::businessMethodForInternal($normalizedDefault),
            'default_method_internal' => $normalizedDefault,
            'cash_allowed_contexts' => self::sanitizeCashContexts($cashContexts),
        ];
    }
}

