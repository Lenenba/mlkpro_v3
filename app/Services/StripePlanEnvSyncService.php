<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Concerns\InteractsWithStripePlanCatalog;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;
use Stripe\Product;
use Stripe\StripeClient;

class StripePlanEnvSyncService
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
        $definitions = $this->resolvePlanDefinitions(
            $options['plans'],
            (bool) $options['solo_only'],
            $options['currencies'],
        );
        $activePrices = $this->fetchActiveRecurringPrices($client);

        $productCache = [];
        $resolved = [];
        $resolvedPlanPrices = [];
        $items = [];

        foreach ($definitions as $planCode => $definition) {
            $anchor = $this->resolvePlanAnchor($client, $activePrices, $definition, $planCode);

            foreach ($definition['prices'] as $currencyCode => $priceDefinition) {
                $match = $this->matchPriceForDefinition(
                    $client,
                    $activePrices,
                    $productCache,
                    $planCode,
                    $anchor['plan_name'],
                    $anchor['product_id'],
                    $currencyCode,
                    $priceDefinition,
                );

                $price = $match['price'];
                $resolvedAmount = $this->normalizeStripeAmount($price);

                $this->addResolvedEnvValues($resolved, $planCode, $currencyCode, $price->id, $resolvedAmount);
                $resolvedPlanPrices[] = [
                    'plan_code' => $planCode,
                    'currency_code' => $currencyCode,
                    'amount' => $resolvedAmount,
                    'stripe_price_id' => $price->id,
                ];

                $items[] = [
                    'plan_code' => $planCode,
                    'currency_code' => $currencyCode,
                    'amount' => $resolvedAmount,
                    'stripe_price_id' => $price->id,
                    'env_key' => $priceDefinition['env_key'],
                    'action' => $match['action'],
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
                if (! $price instanceof Price || ! $this->isMonthlyRecurringPrice($price)) {
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

    private function matchPriceForDefinition(
        StripeClient $client,
        array $activePrices,
        array &$productCache,
        string $planCode,
        ?string $planName,
        ?string $anchorProductId,
        string $currencyCode,
        array $priceDefinition,
    ): array {
        $configuredPriceId = $priceDefinition['configured_price_id'] ?? null;
        $expectedAmount = (float) ($priceDefinition['amount'] ?? 0);
        $expectedUnitAmount = (int) round($expectedAmount * 100);
        $envKey = (string) ($priceDefinition['env_key'] ?? $this->envKeyFor($planCode, $currencyCode));

        $configured = $this->resolveConfiguredPrice($client, $activePrices, $configuredPriceId, $currencyCode);
        if ($configured) {
            return [
                'price' => $configured,
                'action' => 'CONFIGURED',
            ];
        }

        $candidates = $this->filterCandidatePrices($activePrices, $currencyCode);

        $productMatch = $this->selectByAnchorProduct(
            $candidates,
            $anchorProductId,
            $planCode,
            $currencyCode,
            $expectedUnitAmount,
            $envKey,
        );
        if ($productMatch) {
            return $productMatch;
        }

        $metadataMatch = $this->selectByPriceMetadata($candidates, $planCode, $currencyCode, $expectedUnitAmount, $envKey);
        if ($metadataMatch) {
            return $metadataMatch;
        }

        $productMetadataMatch = $this->selectByProductMetadata(
            $client,
            $candidates,
            $productCache,
            $planCode,
            $currencyCode,
            $expectedUnitAmount,
            $envKey,
        );
        if ($productMetadataMatch) {
            return $productMetadataMatch;
        }

        $productNameMatch = $this->selectByProductName(
            $client,
            $candidates,
            $productCache,
            $planCode,
            $planName,
            $currencyCode,
            $expectedUnitAmount,
            $envKey,
        );
        if ($productNameMatch) {
            return $productNameMatch;
        }

        $amountMatch = $this->selectByAmount($candidates, $planCode, $currencyCode, $expectedUnitAmount, $envKey);
        if ($amountMatch) {
            return $amountMatch;
        }

        throw new RuntimeException(sprintf(
            'No active monthly Stripe price matched plan [%s] currency [%s]. Configure [%s], add plan_code metadata, or create an active monthly price for %.2f %s.',
            $planCode,
            $currencyCode,
            $envKey,
            $expectedAmount,
            $currencyCode,
        ));
    }

    private function resolveConfiguredPrice(
        StripeClient $client,
        array $activePrices,
        ?string $configuredPriceId,
        string $currencyCode,
    ): ?Price {
        if (! $configuredPriceId) {
            return null;
        }

        foreach ($activePrices as $price) {
            if (! $price instanceof Price || $price->id !== $configuredPriceId) {
                continue;
            }

            if ($this->isEligiblePrice($price, $currencyCode)) {
                return $price;
            }

            return null;
        }

        $price = $this->retrievePrice($client, $configuredPriceId);

        return $price && $this->isEligiblePrice($price, $currencyCode) ? $price : null;
    }

    private function resolvePlanAnchor(
        StripeClient $client,
        array $activePrices,
        array $definition,
        string $planCode,
    ): array {
        $planName = data_get(config('billing.plans'), $planCode.'.name');
        $resolvedPlanName = is_string($planName) && trim($planName) !== '' ? trim($planName) : null;

        foreach ($definition['prices'] ?? [] as $currencyCode => $priceDefinition) {
            $configuredPriceId = $priceDefinition['configured_price_id'] ?? null;
            if (! is_string($configuredPriceId) || trim($configuredPriceId) === '') {
                continue;
            }

            $price = $this->resolveConfiguredAnchorPrice($client, $activePrices, $configuredPriceId);
            if (! $price) {
                continue;
            }

            $productId = $this->productIdFor($price);
            if ($productId === null) {
                continue;
            }

            return [
                'plan_name' => $resolvedPlanName,
                'product_id' => $productId,
            ];
        }

        return [
            'plan_name' => $resolvedPlanName,
            'product_id' => null,
        ];
    }

    private function resolveConfiguredAnchorPrice(
        StripeClient $client,
        array $activePrices,
        string $configuredPriceId,
    ): ?Price {
        foreach ($activePrices as $price) {
            if (! $price instanceof Price || $price->id !== $configuredPriceId) {
                continue;
            }

            return $this->isMonthlyRecurringPrice($price) ? $price : null;
        }

        $price = $this->retrievePrice($client, $configuredPriceId);

        return $price && $this->isMonthlyRecurringPrice($price) ? $price : null;
    }

    private function selectByPriceMetadata(
        array $candidates,
        string $planCode,
        string $currencyCode,
        int $expectedUnitAmount,
        string $envKey,
    ): ?array {
        $matches = array_values(array_filter($candidates, function (Price $price) use ($planCode): bool {
            return $this->metadataValue($price->metadata ?? null, 'plan_code') === $planCode;
        }));

        return $this->selectSingleMatch(
            $matches,
            $expectedUnitAmount,
            $planCode,
            $currencyCode,
            'price metadata',
            'PRICE METADATA',
            $envKey,
        );
    }

    private function selectByAnchorProduct(
        array $candidates,
        ?string $anchorProductId,
        string $planCode,
        string $currencyCode,
        int $expectedUnitAmount,
        string $envKey,
    ): ?array {
        if (! is_string($anchorProductId) || trim($anchorProductId) === '') {
            return null;
        }

        $matches = array_values(array_filter($candidates, function (Price $price) use ($anchorProductId): bool {
            return $this->productIdFor($price) === $anchorProductId;
        }));

        return $this->selectSingleMatch(
            $matches,
            $expectedUnitAmount,
            $planCode,
            $currencyCode,
            'the configured Stripe product',
            'PRODUCT',
            $envKey,
        );
    }

    private function selectByProductMetadata(
        StripeClient $client,
        array $candidates,
        array &$productCache,
        string $planCode,
        string $currencyCode,
        int $expectedUnitAmount,
        string $envKey,
    ): ?array {
        $matches = [];

        foreach ($candidates as $price) {
            $productId = $this->productIdFor($price);
            if (! $productId) {
                continue;
            }

            if (! array_key_exists($productId, $productCache)) {
                $productCache[$productId] = $this->loadProduct($client, $productId);
            }

            $product = $productCache[$productId];
            if ($this->metadataValue($product->metadata ?? null, 'plan_code') !== $planCode) {
                continue;
            }

            $matches[] = $price;
        }

        return $this->selectSingleMatch(
            $matches,
            $expectedUnitAmount,
            $planCode,
            $currencyCode,
            'product metadata',
            'PRODUCT METADATA',
            $envKey,
        );
    }

    private function selectByProductName(
        StripeClient $client,
        array $candidates,
        array &$productCache,
        string $planCode,
        ?string $planName,
        string $currencyCode,
        int $expectedUnitAmount,
        string $envKey,
    ): ?array {
        if (! is_string($planName) || trim($planName) === '') {
            return null;
        }

        $normalizedPlanName = $this->normalizePlanIdentity($planName);
        $matches = [];

        foreach ($candidates as $price) {
            $productId = $this->productIdFor($price);
            if (! $productId) {
                continue;
            }

            if (! array_key_exists($productId, $productCache)) {
                $productCache[$productId] = $this->loadProduct($client, $productId);
            }

            $product = $productCache[$productId];
            $normalizedProductName = $this->normalizePlanIdentity((string) ($product->name ?? ''));
            if ($normalizedProductName !== $normalizedPlanName) {
                continue;
            }

            $matches[] = $price;
        }

        return $this->selectSingleMatch(
            $matches,
            $expectedUnitAmount,
            $planCode,
            $currencyCode,
            'the Stripe product name',
            'PRODUCT NAME',
            $envKey,
        );
    }

    private function selectByAmount(
        array $candidates,
        string $planCode,
        string $currencyCode,
        int $expectedUnitAmount,
        string $envKey,
    ): ?array {
        $matches = array_values(array_filter($candidates, function (Price $price) use ($expectedUnitAmount): bool {
            return (int) ($price->unit_amount ?? -1) === $expectedUnitAmount;
        }));

        if ($matches === []) {
            return null;
        }

        if (count($matches) > 1) {
            throw new RuntimeException(sprintf(
                'Multiple active monthly Stripe prices matched plan [%s] currency [%s] by amount %.2f. Configure [%s] or add plan_code metadata to disambiguate.',
                $planCode,
                $currencyCode,
                $expectedUnitAmount / 100,
                $envKey,
            ));
        }

        return [
            'price' => $matches[0],
            'action' => 'AMOUNT',
        ];
    }

    private function selectSingleMatch(
        array $matches,
        int $expectedUnitAmount,
        string $planCode,
        string $currencyCode,
        string $reason,
        string $action,
        string $envKey,
    ): ?array {
        if ($matches === []) {
            return null;
        }

        if (count($matches) === 1) {
            return [
                'price' => $matches[0],
                'action' => $action,
            ];
        }

        $amountMatches = array_values(array_filter($matches, function (Price $price) use ($expectedUnitAmount): bool {
            return (int) ($price->unit_amount ?? -1) === $expectedUnitAmount;
        }));

        if (count($amountMatches) === 1) {
            return [
                'price' => $amountMatches[0],
                'action' => $action,
            ];
        }

        throw new RuntimeException(sprintf(
            'Multiple active monthly Stripe prices matched plan [%s] currency [%s] using %s. Configure [%s] or remove duplicate prices in Stripe.',
            $planCode,
            $currencyCode,
            $reason,
            $envKey,
        ));
    }

    private function filterCandidatePrices(array $activePrices, string $currencyCode): array
    {
        return array_values(array_filter($activePrices, function ($price) use ($currencyCode): bool {
            return $price instanceof Price && $this->isEligiblePrice($price, $currencyCode);
        }));
    }

    private function isEligiblePrice(Price $price, string $currencyCode): bool
    {
        return strtoupper((string) ($price->currency ?? '')) === $currencyCode
            && $this->isMonthlyRecurringPrice($price)
            && (bool) ($price->active ?? false);
    }

    private function isMonthlyRecurringPrice(Price $price): bool
    {
        $interval = $price->recurring->interval ?? null;
        $intervalCount = (int) ($price->recurring->interval_count ?? 1);

        return $interval === 'month' && $intervalCount === 1;
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

    private function normalizeStripeAmount(Price $price): float
    {
        return round(((int) ($price->unit_amount ?? 0)) / 100, 2);
    }
}
