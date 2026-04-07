<?php

namespace App\Support\Billing;

use App\Enums\BillingPeriod;
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
                'prices' => self::normalizeMonthlyPrices($defaultPlan['prices'] ?? []),
            ];
        }

        return $result;
    }

    public static function periodicDefinitions(): array
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
                'prices' => self::normalizePeriodicPrices($defaultPlan['prices'] ?? []),
            ];
        }

        return $result;
    }

    private static function normalizeMonthlyPrices(array $prices): array
    {
        $normalized = [];

        foreach (CurrencyCode::cases() as $currency) {
            $row = $prices[$currency->value] ?? null;
            $monthlyRow = self::extractPriceRow($row, BillingPeriod::MONTHLY);
            if ($monthlyRow === null) {
                continue;
            }

            $normalized[$currency->value] = [
                'amount' => self::normalizeAmount($monthlyRow['amount'] ?? 0),
                'stripe_price_id' => self::normalizeNullableString($monthlyRow['stripe_price_id'] ?? null),
            ];
        }

        return $normalized;
    }

    private static function normalizePeriodicPrices(array $prices): array
    {
        $normalized = [];

        foreach (CurrencyCode::cases() as $currency) {
            $row = $prices[$currency->value] ?? null;
            if (! is_array($row)) {
                continue;
            }

            foreach (BillingPeriod::cases() as $period) {
                $periodRow = self::extractPriceRow($row, $period);
                if ($periodRow === null) {
                    continue;
                }

                $normalized[$currency->value][$period->value] = [
                    'amount' => self::normalizeAmount($periodRow['amount'] ?? 0),
                    'stripe_price_id' => self::normalizeNullableString($periodRow['stripe_price_id'] ?? null),
                ];
            }
        }

        return $normalized;
    }

    private static function extractPriceRow(mixed $row, BillingPeriod $period): ?array
    {
        if (! is_array($row)) {
            return null;
        }

        if (array_key_exists('amount', $row) || array_key_exists('stripe_price_id', $row)) {
            return $period === BillingPeriod::MONTHLY ? $row : null;
        }

        $periodRow = $row[$period->value] ?? null;

        return is_array($periodRow) ? $periodRow : null;
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
