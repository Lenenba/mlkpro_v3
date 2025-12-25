<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PriceLookupService
{
    private const SEARCH_TIMEOUT_SECONDS = 4;
    private const STORE_TIMEOUT_SECONDS = 4;
    private const SEARCH_RESULTS = 6;
    private const MAX_PRODUCT_CHECKS = 1;

    public function __construct(private SupplierDirectory $supplierDirectory)
    {
    }

    public function isConfigured(): bool
    {
        return (bool) config('services.serpapi.key');
    }

    public function providerName(): string
    {
        return 'SerpAPI';
    }

    public function search(string $query, array $supplierKeys, array $context = []): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $supplierKeys = array_values(array_filter(array_unique($supplierKeys)));
        if (!$supplierKeys) {
            return [];
        }

        $country = $context['country'] ?? config('suppliers.default_country', 'Canada');
        $province = $context['province'] ?? null;
        $city = $context['city'] ?? null;

        $location = trim(implode(', ', array_filter([$city, $province, $country])));
        $cacheKey = 'price_lookup:' . md5($query . '|' . implode(',', $supplierKeys) . '|' . $location);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $results = $this->fetchShoppingResults($query);
        if (!$results) {
            return [];
        }

        $normalized = $this->normalizeResults($results, $supplierKeys);
        if ($normalized) {
            Cache::put($cacheKey, $normalized, now()->addHours(12));
        }

        return $normalized;
    }

    private function fetchShoppingResults(string $query): array
    {
        try {
            $response = Http::timeout(self::SEARCH_TIMEOUT_SECONDS)->get('https://serpapi.com/search.json', [
                'engine' => 'google_shopping',
                'q' => $query,
                'gl' => 'ca',
                'hl' => 'fr',
                'num' => self::SEARCH_RESULTS,
                'api_key' => config('services.serpapi.key'),
            ]);
        } catch (\Throwable $exception) {
            return [];
        }

        if (!$response->ok()) {
            return [];
        }

        $results = $response->json('shopping_results', []);
        if (!is_array($results)) {
            return [];
        }

        return $results;
    }

    private function normalizeResults(array $results, array $supplierKeys): array
    {
        $entries = [];

        $checked = 0;
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            if ($checked >= self::MAX_PRODUCT_CHECKS) {
                break;
            }

            $stores = $this->fetchStores($result);
            if (!$stores) {
                $checked++;
                continue;
            }

            foreach ($stores as $store) {
                if (!is_array($store)) {
                    continue;
                }

                $link = $store['link'] ?? null;
                if (!$link) {
                    continue;
                }

                $domain = parse_url($link, PHP_URL_HOST);
                $supplier = $domain ? $this->supplierDirectory->findByDomain($domain) : null;
                if (!$supplier) {
                    $supplier = $this->findSupplierByName($store['name'] ?? null, $supplierKeys);
                }
                if (!$supplier) {
                    continue;
                }

                $supplierKey = $supplier['key'] ?? null;
                if (!$supplierKey || !in_array($supplierKey, $supplierKeys, true)) {
                    continue;
                }

                $price = $this->extractPrice($store);
                if ($price === null) {
                    continue;
                }

                $entries[] = [
                    'supplier_key' => $supplierKey,
                    'name' => $supplier['name'] ?? ($store['name'] ?? $domain),
                    'url' => $link,
                    'price' => $price,
                    'currency' => 'CAD',
                    'title' => $store['title'] ?? ($result['title'] ?? null),
                    'image_url' => $store['image_url'] ?? null,
                    'domain' => $domain,
                    'source_label' => $store['name'] ?? null,
                ];
            }

            $checked++;
        }

        $bySupplier = [];
        foreach ($entries as $entry) {
            $key = $entry['supplier_key'];
            if (!isset($bySupplier[$key]) || $entry['price'] < $bySupplier[$key]['price']) {
                $bySupplier[$key] = $entry;
            }
        }

        $filtered = array_values($bySupplier);
        usort($filtered, fn ($a, $b) => $a['price'] <=> $b['price']);

        return $filtered;
    }

    private function fetchStores(array $result): array
    {
        $apiUrl = $result['serpapi_immersive_product_api'] ?? null;
        if (!$apiUrl) {
            return [];
        }

        $apiUrl = $this->appendApiKey($apiUrl);

        try {
            $response = Http::timeout(self::STORE_TIMEOUT_SECONDS)->get($apiUrl);
        } catch (\Throwable $exception) {
            return [];
        }

        if (!$response->ok()) {
            return [];
        }

        $stores = $response->json('product_results.stores', []);
        if (!is_array($stores)) {
            return [];
        }

        $thumbnails = $response->json('product_results.thumbnails', []);
        $imageUrl = is_array($thumbnails) ? ($thumbnails[0] ?? null) : null;
        if (!$imageUrl) {
            $imageUrl = $result['thumbnail'] ?? $result['serpapi_thumbnail'] ?? null;
        }

        return array_map(function ($store) use ($imageUrl) {
            if (!is_array($store)) {
                return $store;
            }
            if ($imageUrl && !isset($store['image_url'])) {
                $store['image_url'] = $imageUrl;
            }
            return $store;
        }, $stores);
    }

    private function appendApiKey(string $url): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . 'api_key=' . urlencode((string) config('services.serpapi.key'));
    }

    private function findSupplierByName(?string $name, array $supplierKeys): ?array
    {
        if (!$name) {
            return null;
        }

        $needle = strtolower($name);
        foreach (config('suppliers.suppliers', []) as $supplier) {
            $key = $supplier['key'] ?? null;
            $supplierName = strtolower((string) ($supplier['name'] ?? ''));
            if (!$key || !$supplierName) {
                continue;
            }
            if (!in_array($key, $supplierKeys, true)) {
                continue;
            }
            if (str_contains($needle, $supplierName) || str_contains($supplierName, $needle)) {
                return $supplier;
            }
        }

        return null;
    }

    private function extractPrice(array $result): ?float
    {
        $numeric = $result['extracted_price'] ?? null;
        if (is_numeric($numeric)) {
            return (float) $numeric;
        }

        $raw = $result['price'] ?? null;
        if (!$raw || !is_string($raw)) {
            return null;
        }

        $normalized = preg_replace('/[^0-9.,]/', '', $raw);
        if (!$normalized) {
            return null;
        }

        $normalized = str_replace(',', '.', $normalized);
        $normalized = preg_replace('/\.(?=.*\.)/', '', $normalized);

        return is_numeric($normalized) ? (float) $normalized : null;
    }
}
