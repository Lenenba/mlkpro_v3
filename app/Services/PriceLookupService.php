<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PriceLookupService
{
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

        return Cache::remember($cacheKey, now()->addHours(12), function () use ($query, $supplierKeys, $location) {
            try {
                $response = Http::timeout(12)->get('https://serpapi.com/search.json', [
                    'engine' => 'google_shopping',
                    'q' => $query,
                    'gl' => 'ca',
                    'hl' => 'fr',
                    'location' => $location ?: 'Canada',
                    'num' => 10,
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

            return $this->normalizeResults($results, $supplierKeys);
        });
    }

    private function normalizeResults(array $results, array $supplierKeys): array
    {
        $entries = [];

        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            $link = $result['link'] ?? null;
            if (!$link) {
                continue;
            }

            $domain = parse_url($link, PHP_URL_HOST);
            if (!$domain) {
                continue;
            }

            $supplier = $this->supplierDirectory->findByDomain($domain);
            if (!$supplier) {
                continue;
            }

            $supplierKey = $supplier['key'] ?? null;
            if (!$supplierKey || !in_array($supplierKey, $supplierKeys, true)) {
                continue;
            }

            $price = $this->extractPrice($result);
            if ($price === null) {
                continue;
            }

            $entries[] = [
                'supplier_key' => $supplierKey,
                'name' => $supplier['name'] ?? ($result['source'] ?? $domain),
                'url' => $link,
                'price' => $price,
                'currency' => 'CAD',
                'title' => $result['title'] ?? null,
                'domain' => $domain,
                'source_label' => $result['source'] ?? null,
            ];
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
