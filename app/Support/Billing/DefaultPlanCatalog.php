<?php

namespace App\Support\Billing;

use App\Enums\CurrencyCode;

class DefaultPlanCatalog
{
    public static function definitions(): array
    {
        $configuredPlans = config('billing.plans', []);
        $defaults = config('billing.catalog_defaults', []);

        $result = [];
        foreach ($configuredPlans as $planCode => $configuredPlan) {
            $defaultPlan = $defaults[$planCode] ?? [
                'description' => null,
                'contact_only' => (bool) ($configuredPlan['contact_only'] ?? false),
                'prices' => [],
            ];

            $result[$planCode] = [
                'code' => $planCode,
                'name' => $configuredPlan['name'] ?? ucfirst($planCode),
                'description' => $defaultPlan['description'],
                'contact_only' => (bool) ($configuredPlan['contact_only'] ?? $defaultPlan['contact_only'] ?? false),
                'is_active' => true,
                'prices' => self::normalizePrices($defaultPlan['prices'] ?? []),
            ];
        }

        return $result;
    }

    private static function normalizePrices(array $prices): array
    {
        $normalized = [];

        foreach (CurrencyCode::cases() as $currency) {
            $row = $prices[$currency->value] ?? null;
            if (! is_array($row)) {
                continue;
            }

            $normalized[$currency->value] = [
                'amount' => self::normalizeAmount($row['amount'] ?? 0),
                'stripe_price_id' => self::normalizeNullableString($row['stripe_price_id'] ?? null),
            ];
        }

        return $normalized;
    }

    private static function normalizeAmount(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private static function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
