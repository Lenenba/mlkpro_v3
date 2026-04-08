<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BillingPeriod;
use App\Services\Concerns\InteractsWithStripePlanCatalog;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;
use Stripe\Product;
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

        $client = $this->makeClient($secret);
        $catalog = $this->resolveCatalog($options['plans'], (bool) $options['solo_only'], $options['currencies']);
        $configuredPlans = (array) config('billing.plans', []);
        $activePrices = $this->fetchActiveRecurringPrices($client);
        $productCache = [];

        $resolved = [];
        $resolvedPlanPrices = [];
        $items = [];

        foreach ($catalog as $planCode => $definition) {
            $basePriceId = $definition['base_price_id'];
            $planName = $this->normalizeNullableString($configuredPlans[$planCode]['name'] ?? null);
            if (! is_string($basePriceId) || trim($basePriceId) === '') {
                throw new RuntimeException(sprintf(
                    'Missing base CAD Stripe price for plan [%s]. Expected STRIPE_PRICE_%s_CAD or STRIPE_PRICE_%s.',
                    $planCode,
                    strtoupper($planCode),
                    strtoupper($planCode),
                ));
            }

            $basePrice = $this->retrievePrice($client, $basePriceId);
            if (! $basePrice) {
                throw new RuntimeException(sprintf(
                    'Failed to retrieve base price [%s] for plan [%s].',
                    $basePriceId,
                    $planCode,
                ));
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

            $existingPrices = array_values(array_filter($activePrices, function ($price) use ($productId): bool {
                return $price instanceof Price && $this->productIdFor($price) === $productId;
            }));

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
                        $planCode,
                        $productId,
                        $currencyCode,
                        $unitAmount,
                        $billingPeriod,
                        $envKey,
                    );
                }

                if (! $price) {
                    $price = $this->findMatchingCatalogPrice(
                        $client,
                        $activePrices,
                        $productCache,
                        $planCode,
                        $planName,
                        $currencyCode,
                        $unitAmount,
                        $billingPeriod,
                        $envKey,
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
                    $activePrices[] = $price;
                    $existingPrices[] = $price;
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

    protected function makeClient(string $secret): StripeClient
    {
        return new StripeClient($secret);
    }

    protected function fetchActiveRecurringPrices(StripeClient $client): array
    {
        $prices = [];
        $startingAfter = null;

        do {
            $params = [
                'active' => true,
                'type' => 'recurring',
                'limit' => 100,
            ];

            if (is_string($startingAfter) && $startingAfter !== '') {
                $params['starting_after'] = $startingAfter;
            }

            $page = $client->prices->all($params);

            foreach ($page->data as $price) {
                if (! $price instanceof Price || ! $this->isSupportedRecurringPrice($price)) {
                    continue;
                }

                $prices[] = $price;
            }

            $lastPrice = $page->data === [] ? null : $page->data[array_key_last($page->data)];
            $startingAfter = ($page->has_more ?? false) && $lastPrice instanceof Price ? $lastPrice->id : null;
        } while ($startingAfter !== null);

        return $prices;
    }

    protected function retrievePrice(StripeClient $client, string $priceId): ?Price
    {
        try {
            $price = $client->prices->retrieve($priceId, []);
        } catch (ApiErrorException) {
            return null;
        }

        return $price instanceof Price ? $price : null;
    }

    protected function loadProduct(StripeClient $client, string $productId): Product
    {
        try {
            $product = $client->products->retrieve($productId, []);
        } catch (ApiErrorException $exception) {
            throw new RuntimeException(sprintf(
                'Failed to retrieve Stripe product [%s]: %s',
                $productId,
                $exception->getMessage(),
            ), previous: $exception);
        }

        if (! $product instanceof Product) {
            throw new RuntimeException(sprintf('Unexpected Stripe product payload for [%s].', $productId));
        }

        return $product;
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
        string $planCode,
        string $productId,
        string $currencyCode,
        int $unitAmount,
        BillingPeriod|string|null $billingPeriod = null,
        string $envKey = '',
    ): ?Price {
        $expectedInterval = $this->stripeIntervalFor($billingPeriod);
        $matches = [];

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

            $matches[] = $price;
        }

        if ($matches === []) {
            return null;
        }

        if (count($matches) > 1) {
            throw new RuntimeException(sprintf(
                'Multiple active %s Stripe prices matched plan [%s] currency [%s] on Stripe product [%s] for %.2f. Configure [%s] or remove duplicate prices in Stripe.',
                $this->periodLabel($billingPeriod),
                $planCode,
                $currencyCode,
                $productId,
                $unitAmount / 100,
                $envKey,
            ));
        }

        return $matches[0];
    }

    private function findMatchingCatalogPrice(
        StripeClient $client,
        array $activePrices,
        array &$productCache,
        string $planCode,
        ?string $planName,
        string $currencyCode,
        int $unitAmount,
        BillingPeriod|string|null $billingPeriod,
        string $envKey,
    ): ?Price {
        $candidates = array_values(array_filter($activePrices, function ($price) use ($currencyCode, $billingPeriod): bool {
            return $price instanceof Price && $this->isEligiblePrice($price, $currencyCode, $billingPeriod);
        }));

        $metadataMatch = $this->selectCatalogMatch(
            array_values(array_filter($candidates, function (Price $price) use ($planCode, $billingPeriod): bool {
                return $this->metadataValue($price->metadata ?? null, 'plan_code') === $planCode
                    && $this->metadataValue($price->metadata ?? null, 'billing_period') === $this->periodLabel($billingPeriod);
            })),
            $planCode,
            $currencyCode,
            $billingPeriod,
            $unitAmount,
            'price metadata',
            $envKey,
        );
        if ($metadataMatch) {
            return $metadataMatch;
        }

        $productMetadataMatches = [];
        $productNameMatches = [];
        $normalizedPlanName = $planName ? $this->normalizePlanIdentity($planName) : null;

        foreach ($candidates as $price) {
            $productId = $this->productIdFor($price);
            if (! $productId) {
                continue;
            }

            if (! array_key_exists($productId, $productCache)) {
                $productCache[$productId] = $this->loadProduct($client, $productId);
            }

            $product = $productCache[$productId];

            if ($this->metadataValue($product->metadata ?? null, 'plan_code') === $planCode) {
                $productMetadataMatches[] = $price;
            }

            if ($normalizedPlanName !== null && $this->normalizePlanIdentity((string) ($product->name ?? '')) === $normalizedPlanName) {
                $productNameMatches[] = $price;
            }
        }

        $productMetadataMatch = $this->selectCatalogMatch(
            $productMetadataMatches,
            $planCode,
            $currencyCode,
            $billingPeriod,
            $unitAmount,
            'product metadata',
            $envKey,
        );
        if ($productMetadataMatch) {
            return $productMetadataMatch;
        }

        return $this->selectCatalogMatch(
            $productNameMatches,
            $planCode,
            $currencyCode,
            $billingPeriod,
            $unitAmount,
            'product name',
            $envKey,
        );
    }

    private function selectCatalogMatch(
        array $matches,
        string $planCode,
        string $currencyCode,
        BillingPeriod|string|null $billingPeriod,
        int $unitAmount,
        string $reason,
        string $envKey,
    ): ?Price {
        if ($matches === []) {
            return null;
        }

        $amountMatches = array_values(array_filter($matches, function (Price $price) use ($unitAmount): bool {
            return (int) ($price->unit_amount ?? -1) === $unitAmount;
        }));

        if (count($amountMatches) === 1) {
            return $amountMatches[0];
        }

        if (count($amountMatches) > 1) {
            throw new RuntimeException(sprintf(
                'Multiple active %s Stripe prices matched plan [%s] currency [%s] using %s for %.2f. Configure [%s] or remove duplicate prices in Stripe.',
                $this->periodLabel($billingPeriod),
                $planCode,
                $currencyCode,
                $reason,
                $unitAmount / 100,
                $envKey,
            ));
        }

        if (count($matches) === 1) {
            $existing = $matches[0];

            throw new RuntimeException(sprintf(
                'Found an active %s Stripe price [%s] for plan [%s] currency [%s] using %s, but its amount is %.2f instead of %.2f. Refusing to create a duplicate. Update [%s] or run billing:stripe-sync-env to adopt the existing Stripe catalog.',
                $this->periodLabel($billingPeriod),
                $existing->id,
                $planCode,
                $currencyCode,
                $reason,
                $this->normalizeStripeAmount($existing),
                $unitAmount / 100,
                $envKey,
            ));
        }

        throw new RuntimeException(sprintf(
            'Multiple active %s Stripe prices matched plan [%s] currency [%s] using %s, but none matched %.2f. Refusing to create a duplicate. Configure [%s] or clean up the Stripe catalog first.',
            $this->periodLabel($billingPeriod),
            $planCode,
            $currencyCode,
            $reason,
            $unitAmount / 100,
            $envKey,
        ));
    }

    private function isEligiblePrice(
        Price $price,
        string $currencyCode,
        BillingPeriod|string|null $billingPeriod,
    ): bool {
        return strtoupper((string) ($price->currency ?? '')) === $currencyCode
            && $this->matchesBillingPeriod($price, $billingPeriod)
            && (bool) ($price->active ?? false);
    }

    private function isSupportedRecurringPrice(Price $price): bool
    {
        $interval = $price->recurring->interval ?? null;
        $intervalCount = (int) ($price->recurring->interval_count ?? 1);

        return is_string($interval) && $this->supportsRecurringInterval($interval, $intervalCount);
    }

    private function matchesBillingPeriod(Price $price, BillingPeriod|string|null $billingPeriod): bool
    {
        $interval = $price->recurring->interval ?? null;
        $intervalCount = (int) ($price->recurring->interval_count ?? 1);

        return is_string($interval)
            && $this->supportsRecurringInterval($interval, $intervalCount)
            && $interval === $this->stripeIntervalFor($billingPeriod);
    }

    private function productIdFor(Price $price): ?string
    {
        $product = $price->product ?? null;

        if (is_string($product) && trim($product) !== '') {
            return $product;
        }

        $productId = is_object($product) ? ($product->id ?? null) : null;

        return is_string($productId) && trim($productId) !== '' ? $productId : null;
    }

    private function metadataValue(mixed $metadata, string $key): ?string
    {
        $value = null;

        if (is_array($metadata)) {
            $value = $metadata[$key] ?? null;
        } elseif ($metadata instanceof \ArrayAccess && isset($metadata[$key])) {
            $value = $metadata[$key];
        } elseif (is_object($metadata)) {
            $value = $metadata->{$key} ?? null;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = trim(strtolower($value));

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizePlanIdentity(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }
}
