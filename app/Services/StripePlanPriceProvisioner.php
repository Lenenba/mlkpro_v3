<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BillingPeriod;
use App\Models\Plan;
use App\Models\PlanPrice;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;
use Stripe\StripeClient;

class StripePlanPriceProvisioner
{
    public function execute(array $options = []): array
    {
        $options = array_merge([
            'dry_run' => false,
            'live' => false,
            'plans' => [],
            'currencies' => [],
            'write_env' => null,
            'sync_db' => false,
        ], $options);

        $secret = (string) config('services.stripe.secret', '');
        if ($secret === '') {
            throw new RuntimeException('Missing Stripe secret. Check STRIPE_SECRET in the current environment.');
        }

        $isLiveKey = str_starts_with($secret, 'sk_live_');
        if ($isLiveKey && ! $options['live']) {
            throw new RuntimeException('Refusing to run against a live Stripe key without --live.');
        }

        $client = new StripeClient($secret);
        $catalog = $this->resolveCatalog($options['plans'], $options['currencies']);

        $resolved = [];
        $resolvedPlanPrices = [];
        $items = [];

        foreach ($catalog as $planCode => $definition) {
            $basePriceId = $definition['base_price_id'];
            if (! is_string($basePriceId) || trim($basePriceId) === '') {
                throw new RuntimeException(sprintf(
                    'Missing base CAD Stripe price for plan [%s]. Expected STRIPE_PRICE_%s_CAD or STRIPE_PRICE_%s.',
                    $planCode,
                    strtoupper($planCode),
                    strtoupper($planCode),
                ));
            }

            try {
                $basePrice = $client->prices->retrieve($basePriceId, []);
            } catch (ApiErrorException $exception) {
                throw new RuntimeException(sprintf(
                    'Failed to retrieve base price [%s] for plan [%s]: %s',
                    $basePriceId,
                    $planCode,
                    $exception->getMessage(),
                ), previous: $exception);
            }

            $baseInterval = $basePrice->recurring->interval ?? null;
            $productId = is_string($basePrice->product) ? $basePrice->product : ($basePrice->product->id ?? null);
            if (! $productId || $baseInterval !== 'month') {
                throw new RuntimeException(sprintf(
                    'Base price [%s] for plan [%s] must be a monthly recurring Stripe price.',
                    $basePriceId,
                    $planCode,
                ));
            }

            $existingPrices = $client->prices->all([
                'product' => $productId,
                'active' => true,
                'limit' => 100,
            ])->data;

            foreach ($definition['prices'] as $currencyCode => $priceDefinition) {
                $envKey = $priceDefinition['env_key'];
                $amount = $priceDefinition['amount'];
                $unitAmount = (int) round($amount * 100);
                $configuredPriceId = $priceDefinition['configured_price_id'];

                $price = $this->resolveExistingConfiguredPrice(
                    $client,
                    $configuredPriceId,
                    $productId,
                    $currencyCode,
                    $unitAmount,
                );

                if (! $price) {
                    $price = $this->findMatchingPrice(
                        $existingPrices,
                        $currencyCode,
                        $unitAmount,
                    );
                }

                $action = 'REUSED';

                if (! $price) {
                    if ($options['dry_run']) {
                        $items[] = [
                            'plan_code' => $planCode,
                            'product_id' => $productId,
                            'currency_code' => $currencyCode,
                            'amount' => $amount,
                            'stripe_price_id' => null,
                            'env_key' => $envKey,
                            'action' => 'WOULD CREATE',
                        ];

                        continue;
                    }

                    $price = $client->prices->create([
                        'product' => $productId,
                        'currency' => strtolower($currencyCode),
                        'unit_amount' => $unitAmount,
                        'recurring' => ['interval' => 'month'],
                        'metadata' => [
                            'plan_code' => $planCode,
                            'currency_code' => $currencyCode,
                            'created_by' => 'artisan_command',
                            'source' => 'multi_currency_billing',
                        ],
                    ]);

                    $action = 'CREATED';
                }

                $resolved[$envKey] = $price->id;
                $resolvedPlanPrices[] = [
                    'plan_code' => $planCode,
                    'currency_code' => $currencyCode,
                    'amount' => $amount,
                    'stripe_price_id' => $price->id,
                ];

                $items[] = [
                    'plan_code' => $planCode,
                    'product_id' => $productId,
                    'currency_code' => $currencyCode,
                    'amount' => $amount,
                    'stripe_price_id' => $price->id,
                    'env_key' => $envKey,
                    'action' => $action,
                ];
            }
        }

        $envUpdated = false;
        $writeEnv = is_string($options['write_env']) ? trim($options['write_env']) : null;
        if (! $options['dry_run'] && $writeEnv) {
            $this->writeEnvValues($writeEnv, $resolved);
            $envUpdated = true;
        }

        $dbSynced = false;
        if ($options['sync_db'] && ! $options['dry_run']) {
            $this->syncDatabasePlanPrices($resolvedPlanPrices);
            $dbSynced = true;
        }

        return [
            'items' => $items,
            'resolved' => $resolved,
            'env_updated' => $envUpdated,
            'db_synced' => $dbSynced,
            'dry_run' => (bool) $options['dry_run'],
        ];
    }

    private function resolveCatalog(array $selectedPlans, array $selectedCurrencies): array
    {
        $defaults = (array) config('billing.catalog_defaults', []);

        $catalog = [];

        foreach ($defaults as $planCode => $definition) {
            if (($definition['contact_only'] ?? false) === true) {
                continue;
            }

            if ($selectedPlans !== [] && ! in_array($planCode, $selectedPlans, true)) {
                continue;
            }

            $prices = [];
            foreach ((array) ($definition['prices'] ?? []) as $currencyCode => $row) {
                $currencyCode = strtoupper((string) $currencyCode);

                if ($currencyCode === 'CAD') {
                    continue;
                }

                if ($selectedCurrencies !== [] && ! in_array($currencyCode, $selectedCurrencies, true)) {
                    continue;
                }

                if (! is_array($row)) {
                    continue;
                }

                $amount = (float) ($row['amount'] ?? 0);
                if ($amount <= 0) {
                    continue;
                }

                $prices[$currencyCode] = [
                    'amount' => $amount,
                    'configured_price_id' => $this->normalizeNullableString($row['stripe_price_id'] ?? null),
                    'env_key' => $this->envKeyFor($planCode, $currencyCode),
                ];
            }

            if ($prices === []) {
                continue;
            }

            $catalog[$planCode] = [
                'base_price_id' => $this->normalizeNullableString(
                    $definition['prices']['CAD']['stripe_price_id']
                        ?? $definition['prices']['cad']['stripe_price_id']
                        ?? null
                ),
                'prices' => $prices,
            ];
        }

        if ($catalog === []) {
            throw new RuntimeException('No matching plans or currencies were found in billing.catalog_defaults.');
        }

        return $catalog;
    }

    private function envKeyFor(string $planCode, string $currencyCode): string
    {
        return 'STRIPE_PRICE_'.strtoupper($planCode).'_'.strtoupper($currencyCode);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function resolveExistingConfiguredPrice(
        StripeClient $client,
        ?string $configuredPriceId,
        string $productId,
        string $currencyCode,
        int $unitAmount,
    ): ?Price {
        if (! $configuredPriceId) {
            return null;
        }

        try {
            $price = $client->prices->retrieve($configuredPriceId, []);
        } catch (ApiErrorException) {
            return null;
        }

        $priceProductId = is_string($price->product) ? $price->product : ($price->product->id ?? null);
        $priceCurrency = strtoupper((string) ($price->currency ?? ''));
        $priceInterval = $price->recurring->interval ?? null;
        $priceUnitAmount = (int) ($price->unit_amount ?? -1);

        if (
            $priceProductId !== $productId ||
            $priceCurrency !== $currencyCode ||
            $priceInterval !== 'month' ||
            $priceUnitAmount !== $unitAmount
        ) {
            return null;
        }

        return $price;
    }

    private function findMatchingPrice(array $prices, string $currencyCode, int $unitAmount): ?Price
    {
        foreach ($prices as $price) {
            $priceCurrency = strtoupper((string) ($price->currency ?? ''));
            $priceInterval = $price->recurring->interval ?? null;
            $priceUnitAmount = (int) ($price->unit_amount ?? -1);

            if ($priceCurrency !== $currencyCode) {
                continue;
            }

            if ($priceInterval !== 'month') {
                continue;
            }

            if ($priceUnitAmount !== $unitAmount) {
                continue;
            }

            return $price;
        }

        return null;
    }

    private function writeEnvValues(string $path, array $values): void
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

    private function syncDatabasePlanPrices(array $resolvedPlanPrices): void
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
