<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use App\Enums\BillingPeriod;
use App\Models\Plan;
use App\Models\PlanPrice;
use RuntimeException;

trait InteractsWithStripePlanCatalog
{
    public const SOLO_PLAN_CODES = [
        'solo_essential',
        'solo_pro',
        'solo_growth',
    ];

    public function resolveSelectedPlanCodes(array $selectedPlans, bool $soloOnly = false): array
    {
        $expanded = [];

        if ($soloOnly) {
            $expanded = [...self::SOLO_PLAN_CODES];
        }

        foreach ($selectedPlans as $selectedPlan) {
            if (! is_string($selectedPlan)) {
                continue;
            }

            $normalized = trim(strtolower($selectedPlan));
            if ($normalized === '') {
                continue;
            }

            if ($normalized === 'solo') {
                $expanded = [...$expanded, ...self::SOLO_PLAN_CODES];

                continue;
            }

            $expanded[] = $normalized;
        }

        return array_values(array_unique($expanded));
    }

    protected function resolvePlanDefinitions(array $selectedPlans, bool $soloOnly, array $selectedCurrencies): array
    {
        $configuredPlans = (array) config('billing.plans', []);
        $defaults = (array) config('billing.catalog_defaults', []);
        $resolvedPlanCodes = $this->resolveSelectedPlanCodes($selectedPlans, $soloOnly);
        $excludeSoloByDefault = $resolvedPlanCodes === [] && ! $soloOnly;

        $definitions = [];

        foreach ($defaults as $planCode => $definition) {
            $configuredPlan = is_array($configuredPlans[$planCode] ?? null) ? $configuredPlans[$planCode] : [];

            if (($definition['contact_only'] ?? false) === true) {
                continue;
            }

            if ($excludeSoloByDefault && (($configuredPlan['audience'] ?? null) === 'solo')) {
                continue;
            }

            if ($resolvedPlanCodes !== [] && ! in_array($planCode, $resolvedPlanCodes, true)) {
                continue;
            }

            $prices = [];

            foreach ((array) ($definition['prices'] ?? []) as $currencyCode => $row) {
                $currencyCode = strtoupper((string) $currencyCode);

                if ($selectedCurrencies !== [] && ! in_array($currencyCode, $selectedCurrencies, true)) {
                    continue;
                }

                if (! is_array($row)) {
                    continue;
                }

                $configuredPriceId = $this->normalizeNullableString($row['stripe_price_id'] ?? null);
                $amount = (float) ($row['amount'] ?? 0);

                if ($amount <= 0 && $configuredPriceId === null) {
                    continue;
                }

                $prices[$currencyCode] = [
                    'amount' => $amount,
                    'configured_price_id' => $configuredPriceId,
                    'env_key' => $this->envKeyFor($planCode, $currencyCode),
                ];
            }

            if ($prices === []) {
                continue;
            }

            $definitions[$planCode] = [
                'prices' => $prices,
            ];
        }

        if ($definitions === []) {
            throw new RuntimeException('No matching plans or currencies were found in billing.catalog_defaults.');
        }

        return $definitions;
    }

    protected function resolveCatalog(array $selectedPlans, bool $soloOnly, array $selectedCurrencies): array
    {
        $definitions = $this->resolvePlanDefinitions($selectedPlans, $soloOnly, $selectedCurrencies);
        $catalog = [];

        foreach ($definitions as $planCode => $definition) {
            $basePrice = is_array($definition['prices']['CAD'] ?? null)
                ? $definition['prices']['CAD']
                : null;

            $prices = [];
            foreach ($definition['prices'] as $currencyCode => $row) {
                if ($currencyCode === 'CAD') {
                    continue;
                }

                $prices[$currencyCode] = $row;
            }

            if ($prices === []) {
                continue;
            }

            $catalog[$planCode] = [
                'base_price_id' => $basePrice['configured_price_id'] ?? null,
                'base_amount' => (float) ($basePrice['amount'] ?? 0),
                'prices' => $prices,
            ];
        }

        if ($catalog === []) {
            throw new RuntimeException('No matching plans or currencies were found in billing.catalog_defaults.');
        }

        return $catalog;
    }

    protected function envKeyFor(string $planCode, string $currencyCode): string
    {
        return 'STRIPE_PRICE_'.strtoupper($planCode).'_'.strtoupper($currencyCode);
    }

    protected function legacyEnvKeyFor(string $planCode): string
    {
        return 'STRIPE_PRICE_'.strtoupper($planCode);
    }

    protected function amountEnvKeyFor(string $planCode, string $currencyCode): string
    {
        return $this->envKeyFor($planCode, $currencyCode).'_AMOUNT';
    }

    protected function legacyAmountEnvKeyFor(string $planCode): string
    {
        return $this->legacyEnvKeyFor($planCode).'_AMOUNT';
    }

    protected function addResolvedEnvValues(
        array &$resolved,
        string $planCode,
        string $currencyCode,
        string $priceId,
        float $amount,
    ): void {
        $formattedAmount = number_format($amount, 2, '.', '');

        $resolved[$this->envKeyFor($planCode, $currencyCode)] = $priceId;
        $resolved[$this->amountEnvKeyFor($planCode, $currencyCode)] = $formattedAmount;

        if (strtoupper($currencyCode) !== 'CAD') {
            return;
        }

        $resolved[$this->legacyEnvKeyFor($planCode)] = $priceId;
        $resolved[$this->legacyAmountEnvKeyFor($planCode)] = $formattedAmount;
    }

    protected function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    protected function writeEnvValues(string $path, array $values): void
    {
        if (! file_exists($path)) {
            throw new RuntimeException("Env file not found: {$path}");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read env file: {$path}");
        }

        foreach ($values as $key => $value) {
            $pattern = '/^'.preg_quote($key, '/').'=.*/m';
            $replacement = $key.'='.$value;

            if (preg_match($pattern, $contents) === 1) {
                $contents = preg_replace($pattern, $replacement, $contents, 1) ?? $contents;

                continue;
            }

            $contents = rtrim($contents).PHP_EOL.$replacement.PHP_EOL;
        }

        file_put_contents($path, $contents);
    }

    protected function syncDatabasePlanPrices(array $resolvedPlanPrices): void
    {
        $configuredPlans = (array) config('billing.plans', []);
        $configuredDefaults = (array) config('billing.catalog_defaults', []);
        $sortOrderMap = array_flip(array_keys($configuredPlans));

        foreach ($resolvedPlanPrices as $row) {
            $planCode = $row['plan_code'];
            $configuredPlan = $configuredPlans[$planCode] ?? [];
            $configuredDefault = $configuredDefaults[$planCode] ?? [];

            $plan = Plan::query()->updateOrCreate(
                ['code' => $planCode],
                [
                    'name' => $configuredPlan['name'] ?? ucfirst($planCode),
                    'description' => $configuredDefault['description'] ?? null,
                    'is_active' => true,
                    'contact_only' => (bool) ($configuredPlan['contact_only'] ?? $configuredDefault['contact_only'] ?? false),
                    'sort_order' => (int) ($sortOrderMap[$planCode] ?? 0),
                ]
            );

            PlanPrice::query()->updateOrCreate(
                [
                    'plan_id' => $plan->id,
                    'currency_code' => $row['currency_code'],
                    'billing_period' => BillingPeriod::MONTHLY->value,
                ],
                [
                    'amount' => number_format((float) $row['amount'], 2, '.', ''),
                    'stripe_price_id' => $row['stripe_price_id'],
                    'is_active' => true,
                ]
            );
        }
    }
}
