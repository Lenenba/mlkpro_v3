<?php

namespace App\Services;

class SupplierDirectory
{
    public function all(?string $country = null): array
    {
        $suppliers = config('suppliers.suppliers', []);
        if (!$country) {
            return array_values($suppliers);
        }

        $normalized = strtolower((string) $country);

        return array_values(array_filter($suppliers, function (array $supplier) use ($normalized) {
            return strtolower((string) ($supplier['country'] ?? '')) === $normalized;
        }));
    }

    public function keys(?string $country = null): array
    {
        return array_values(array_map(fn (array $supplier) => $supplier['key'], $this->all($country)));
    }

    public function findByKey(string $key): ?array
    {
        foreach (config('suppliers.suppliers', []) as $supplier) {
            if (($supplier['key'] ?? null) === $key) {
                return $supplier;
            }
        }

        return null;
    }

    public function findByDomain(string $domain): ?array
    {
        $domain = strtolower($domain);

        foreach (config('suppliers.suppliers', []) as $supplier) {
            $domains = $supplier['domains'] ?? [];
            foreach ($domains as $candidate) {
                $candidate = strtolower((string) $candidate);
                if ($domain === $candidate || str_ends_with($domain, '.' . $candidate)) {
                    return $supplier;
                }
            }
        }

        return null;
    }
}
