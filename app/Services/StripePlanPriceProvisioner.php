<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BillingPeriod;
use App\Services\Concerns\InteractsWithStripePlanCatalog;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;
use Stripe\StripeClient;

class StripePlanPriceProvisioner
{
    use InteractsWithStripePlanCatalog;

    public function execute(array $options = []): array
    {
        $options = array_merge([
            'dry_run' => false,
            'live' => false,
            'plans' => [],
            'solo_only' => false,
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
        $catalog = $this->resolveCatalog($options['plans'], (bool) $options['solo_only'], $options['currencies']);

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

            $baseAmount = $this->normalizeStripeAmount($basePrice);
            $this->addResolvedEnvValues($resolved, $planCode, 'CAD', $basePrice->id, $baseAmount, BillingPeriod::MONTHLY);
            $resolvedPlanPrices[] = [
                'plan_code' => $planCode,
                'currency_code' => 'CAD',
                'billing_period' => BillingPeriod::MONTHLY->value,
                'amount' => $baseAmount,
                'stripe_price_id' => $basePrice->id,
            ];

            $items[] = [
                'plan_code' => $planCode,
                'product_id' => $productId,
                'currency_code' => 'CAD',
                'billing_period' => BillingPeriod::MONTHLY->value,
                'amount' => $baseAmount,
                'stripe_price_id' => $basePrice->id,
                'env_key' => $this->envKeyFor($planCode, 'CAD', BillingPeriod::MONTHLY),
                'action' => 'BASE',
            ];

            $existingPrices = $client->prices->all([
                'product' => $productId,
                'active' => true,
                'limit' => 100,
            ])->data;

            foreach ($definition['prices'] as $priceDefinition) {
                $currencyCode = $priceDefinition['currency_code'];
                $billingPeriod = $this->normalizeBillingPeriod($priceDefinition['billing_period'] ?? null);
                $envKey = $priceDefinition['env_key'];
                $amount = (float) $priceDefinition['amount'];
                $unitAmount = (int) round($amount * 100);
                $configuredPriceId = $priceDefinition['configured_price_id'];

                $price = $this->resolveExistingConfiguredPrice(
                    $client,
                    $configuredPriceId,
                    $productId,
                    $currencyCode,
                    $unitAmount,
                    $billingPeriod,
                );

                if (! $price) {
                    $price = $this->findMatchingPrice(
                        $existingPrices,
                        $currencyCode,
                        $unitAmount,
                        $billingPeriod,
                    );
                }

                $action = 'REUSED';

                if (! $price) {
                    if ($options['dry_run']) {
                        $items[] = [
                            'plan_code' => $planCode,
                            'product_id' => $productId,
                            'currency_code' => $currencyCode,
                            'billing_period' => $billingPeriod->value,
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
                        'recurring' => ['interval' => $this->stripeIntervalFor($billingPeriod)],
                        'metadata' => [
                            'plan_code' => $planCode,
                            'currency_code' => $currencyCode,
                            'billing_period' => $billingPeriod->value,
                            'created_by' => 'artisan_command',
                            'source' => 'multi_currency_billing',
                        ],
                    ]);

                    $action = 'CREATED';
                }

                $resolvedAmount = $this->normalizeStripeAmount($price);
                $this->addResolvedEnvValues(
                    $resolved,
                    $planCode,
                    $currencyCode,
                    $price->id,
                    $resolvedAmount,
                    $billingPeriod
                );
                $resolvedPlanPrices[] = [
                    'plan_code' => $planCode,
                    'currency_code' => $currencyCode,
                    'billing_period' => $billingPeriod->value,
                    'amount' => $resolvedAmount,
                    'stripe_price_id' => $price->id,
                ];

                $items[] = [
                    'plan_code' => $planCode,
                    'product_id' => $productId,
                    'currency_code' => $currencyCode,
                    'billing_period' => $billingPeriod->value,
                    'amount' => $resolvedAmount,
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

    private function normalizeStripeAmount(Price $price): float
    {
        return round(((int) ($price->unit_amount ?? 0)) / 100, 2);
    }

    private function resolveExistingConfiguredPrice(
        StripeClient $client,
        ?string $configuredPriceId,
        string $productId,
        string $currencyCode,
        int $unitAmount,
        BillingPeriod|string|null $billingPeriod = null,
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
            $priceInterval !== $this->stripeIntervalFor($billingPeriod) ||
            $priceUnitAmount !== $unitAmount
        ) {
            return null;
        }

        return $price;
    }

    private function findMatchingPrice(
        array $prices,
        string $currencyCode,
        int $unitAmount,
        BillingPeriod|string|null $billingPeriod = null,
    ): ?Price {
        $expectedInterval = $this->stripeIntervalFor($billingPeriod);

        foreach ($prices as $price) {
            $priceCurrency = strtoupper((string) ($price->currency ?? ''));
            $priceInterval = $price->recurring->interval ?? null;
            $priceUnitAmount = (int) ($price->unit_amount ?? -1);

            if ($priceCurrency !== $currencyCode) {
                continue;
            }

            if ($priceInterval !== $expectedInterval) {
                continue;
            }

            if ($priceUnitAmount !== $unitAmount) {
                continue;
            }

            return $price;
        }

        return null;
    }
}
