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

                foreach ($this->extractPriceDefinitionsForCurrency($row, $planCode, $currencyCode) as $priceDefinition) {
                    $amount = (float) ($priceDefinition['amount'] ?? 0);
                    $configuredPriceId = $this->normalizeNullableString($priceDefinition['configured_price_id'] ?? null);

                    if ($amount <= 0 && $configuredPriceId === null) {
                        continue;
                    }

                    $prices[] = $priceDefinition;
                }
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
            $basePrice = collect($definition['prices'])->first(function (array $row): bool {
                return $row['currency_code'] === 'CAD'
                    && $row['billing_period'] === BillingPeriod::MONTHLY->value;
            });

            $prices = array_values(array_filter($definition['prices'], function (array $row): bool {
                return ! (
                    $row['currency_code'] === 'CAD'
                    && $row['billing_period'] === BillingPeriod::MONTHLY->value
                );
            }));

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

    protected function envKeyFor(
        string $planCode,
        string $currencyCode,
        BillingPeriod|string|null $billingPeriod = null
    ): string {
        $prefix = 'STRIPE_PRICE_'.strtoupper($planCode).'_'.strtoupper($currencyCode);
        $period = $this->normalizeBillingPeriod($billingPeriod);

        return $period === BillingPeriod::YEARLY ? $prefix.'_YEARLY' : $prefix;
    }

    protected function legacyEnvKeyFor(string $planCode, BillingPeriod|string|null $billingPeriod = null): string
    {
        $prefix = 'STRIPE_PRICE_'.strtoupper($planCode);
        $period = $this->normalizeBillingPeriod($billingPeriod);

        return $period === BillingPeriod::YEARLY ? $prefix.'_YEARLY' : $prefix;
    }

    protected function amountEnvKeyFor(
        string $planCode,
        string $currencyCode,
        BillingPeriod|string|null $billingPeriod = null
    ): string {
        return $this->envKeyFor($planCode, $currencyCode, $billingPeriod).'_AMOUNT';
    }

    protected function legacyAmountEnvKeyFor(string $planCode, BillingPeriod|string|null $billingPeriod = null): string
    {
        return $this->legacyEnvKeyFor($planCode, $billingPeriod).'_AMOUNT';
    }

    protected function addResolvedEnvValues(
        array &$resolved,
        string $planCode,
        string $currencyCode,
        string $priceId,
        float $amount,
        BillingPeriod|string|null $billingPeriod = null,
    ): void {
        $period = $this->normalizeBillingPeriod($billingPeriod);
        $formattedAmount = number_format($amount, 2, '.', '');

        $resolved[$this->envKeyFor($planCode, $currencyCode, $period)] = $priceId;
        $resolved[$this->amountEnvKeyFor($planCode, $currencyCode, $period)] = $formattedAmount;

        if (strtoupper($currencyCode) !== 'CAD') {
            return;
        }

        $resolved[$this->legacyEnvKeyFor($planCode, $period)] = $priceId;
        $resolved[$this->legacyAmountEnvKeyFor($planCode, $period)] = $formattedAmount;
    }

    protected function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    protected function normalizeBillingPeriod(BillingPeriod|string|null $billingPeriod = null): BillingPeriod
    {
        return $billingPeriod instanceof BillingPeriod
            ? $billingPeriod
            : (BillingPeriod::tryFromMixed($billingPeriod) ?? BillingPeriod::default());
    }

    protected function stripeIntervalFor(BillingPeriod|string|null $billingPeriod = null): string
    {
        return $this->normalizeBillingPeriod($billingPeriod) === BillingPeriod::YEARLY ? 'year' : 'month';
    }

    protected function supportsRecurringInterval(string $interval, int $intervalCount = 1): bool
    {
        if ($intervalCount !== 1) {
            return false;
        }

        return in_array($interval, ['month', 'year'], true);
    }

    protected function periodLabel(BillingPeriod|string|null $billingPeriod = null): string
    {
        return $this->normalizeBillingPeriod($billingPeriod)->value;
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
            $billingPeriod = $this->normalizeBillingPeriod($row['billing_period'] ?? null);

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
                    'billing_period' => $billingPeriod->value,
                ],
                [
                    'amount' => number_format((float) $row['amount'], 2, '.', ''),
                    'stripe_price_id' => $row['stripe_price_id'],
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * @return array<int, array{currency_code: string, billing_period: string, amount: float, configured_price_id: ?string, env_key: string}>
     */
    private function extractPriceDefinitionsForCurrency(mixed $row, string $planCode, string $currencyCode): array
    {
        if (! is_array($row)) {
            return [];
        }

        if (array_key_exists('amount', $row) || array_key_exists('stripe_price_id', $row)) {
            return [[
                'currency_code' => $currencyCode,
                'billing_period' => BillingPeriod::MONTHLY->value,
                'amount' => (float) ($row['amount'] ?? 0),
                'configured_price_id' => $this->normalizeNullableString($row['stripe_price_id'] ?? null),
                'env_key' => $this->envKeyFor($planCode, $currencyCode, BillingPeriod::MONTHLY),
            ]];
        }

        $definitions = [];
        foreach (BillingPeriod::cases() as $period) {
            $periodRow = $row[$period->value] ?? null;
            if (! is_array($periodRow)) {
                continue;
            }

            $definitions[] = [
                'currency_code' => $currencyCode,
                'billing_period' => $period->value,
                'amount' => (float) ($periodRow['amount'] ?? 0),
                'configured_price_id' => $this->normalizeNullableString($periodRow['stripe_price_id'] ?? null),
                'env_key' => $this->envKeyFor($planCode, $currencyCode, $period),
            ];
        }

        return $definitions;
    }
}
