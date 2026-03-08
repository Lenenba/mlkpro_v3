<?php

namespace App\Support\Billing;

use App\Enums\CurrencyCode;

class DefaultPlanCatalog
{
    public static function definitions(): array
    {
        $configuredPlans = config('billing.plans', []);

        $defaults = [
            'free' => [
                'description' => 'Free starter access for very small teams.',
                'contact_only' => false,
                'prices' => [
                    'CAD' => ['amount' => '0.00', 'stripe_price_id' => env('STRIPE_PRICE_FREE_CAD', env('STRIPE_PRICE_FREE'))],
                    'EUR' => ['amount' => '0.00', 'stripe_price_id' => env('STRIPE_PRICE_FREE_EUR')],
                    'USD' => ['amount' => '0.00', 'stripe_price_id' => env('STRIPE_PRICE_FREE_USD')],
                ],
            ],
            'starter' => [
                'description' => 'Starter plan for growing teams.',
                'contact_only' => false,
                'prices' => [
                    'CAD' => ['amount' => self::normalizeAmount(env('STRIPE_PRICE_STARTER_CAD_AMOUNT', env('STRIPE_PRICE_STARTER_AMOUNT', 29))), 'stripe_price_id' => env('STRIPE_PRICE_STARTER_CAD', env('STRIPE_PRICE_STARTER'))],
                    'EUR' => ['amount' => self::normalizeAmount(env('STRIPE_PRICE_STARTER_EUR_AMOUNT', 21)), 'stripe_price_id' => env('STRIPE_PRICE_STARTER_EUR')],
                    'USD' => ['amount' => self::normalizeAmount(env('STRIPE_PRICE_STARTER_USD_AMOUNT', 24)), 'stripe_price_id' => env('STRIPE_PRICE_STARTER_USD')],
                ],
            ],
            'growth' => [
                'description' => 'Growth plan for larger teams and automation.',
                'contact_only' => false,
                'prices' => [
                    'CAD' => ['amount' => self::normalizeAmount(env('STRIPE_PRICE_GROWTH_CAD_AMOUNT', env('STRIPE_PRICE_GROWTH_AMOUNT', 79))), 'stripe_price_id' => env('STRIPE_PRICE_GROWTH_CAD', env('STRIPE_PRICE_GROWTH'))],
                    'EUR' => ['amount' => self::normalizeAmount(env('STRIPE_PRICE_GROWTH_EUR_AMOUNT', 57)), 'stripe_price_id' => env('STRIPE_PRICE_GROWTH_EUR')],
                    'USD' => ['amount' => self::normalizeAmount(env('STRIPE_PRICE_GROWTH_USD_AMOUNT', 64)), 'stripe_price_id' => env('STRIPE_PRICE_GROWTH_USD')],
                ],
            ],
            'scale' => [
                'description' => 'Scale plan with advanced support and included AI.',
                'contact_only' => false,
                'prices' => [
                    'CAD' => ['amount' => self::normalizeAmount(env('STRIPE_PRICE_SCALE_CAD_AMOUNT', env('STRIPE_PRICE_SCALE_AMOUNT', 149))), 'stripe_price_id' => env('STRIPE_PRICE_SCALE_CAD', env('STRIPE_PRICE_SCALE'))],
                    'EUR' => ['amount' => self::normalizeAmount(env('STRIPE_PRICE_SCALE_EUR_AMOUNT', 109)), 'stripe_price_id' => env('STRIPE_PRICE_SCALE_EUR')],
                    'USD' => ['amount' => self::normalizeAmount(env('STRIPE_PRICE_SCALE_USD_AMOUNT', 119)), 'stripe_price_id' => env('STRIPE_PRICE_SCALE_USD')],
                ],
            ],
            'enterprise' => [
                'description' => 'Enterprise plan with custom pricing.',
                'contact_only' => true,
                'prices' => [],
            ],
        ];

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
