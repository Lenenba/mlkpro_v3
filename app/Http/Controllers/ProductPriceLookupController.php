<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PriceLookupService;
use App\Services\SupplierDirectory;
use Illuminate\Http\Request;

class ProductPriceLookupController extends Controller
{
    public function __invoke(
        Request $request,
        PriceLookupService $priceLookupService,
        SupplierDirectory $supplierDirectory
    ) {
        $validated = $request->validate([
            'query' => 'required|string|min:2|max:200',
        ]);

        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? 0;
        if (!$user || !$accountId) {
            abort(403);
        }

        $owner = $accountId === $user->id ? $user : User::query()->find($accountId);
        $country = $owner?->company_country ?: config('suppliers.default_country', 'Canada');
        $province = $owner?->company_province;
        $city = $owner?->company_city;

        $suppliers = $supplierDirectory->all($country);
        $customSuppliers = $this->resolveCustomSuppliers($owner?->company_supplier_preferences);
        $suppliers = $this->mergeSuppliers($suppliers, $customSuppliers);
        $preferences = $this->resolveSupplierPreferences($owner?->company_supplier_preferences, $suppliers);

        $baseQuery = trim($validated['query']);
        $query = trim(implode(' ', array_filter([$baseQuery, $city, $province, $country])));

        $sources = $priceLookupService->search($query, $preferences['enabled'], [
            'country' => $country,
            'province' => $province,
            'city' => $city,
            'suppliers' => $suppliers,
        ]);

        return response()->json([
            'query' => $query,
            'provider' => $priceLookupService->providerName(),
            'provider_ready' => $priceLookupService->isConfigured(),
            'enabled_suppliers' => $this->resolveSupplierNames($suppliers, $preferences['enabled']),
            'preferred_suppliers' => $this->resolveSupplierNames($suppliers, $preferences['preferred']),
            'sources' => $sources,
        ]);
    }

    private function resolveSupplierPreferences(?array $preferences, array $suppliers): array
    {
        $preferences = is_array($preferences) ? $preferences : [];
        $limit = (int) config('suppliers.preferred_limit', 4);
        $keys = collect($suppliers)->pluck('key')->filter()->values()->all();
        $defaultEnabled = collect($suppliers)
            ->filter(fn (array $supplier) => !empty($supplier['default_enabled']))
            ->pluck('key')
            ->values()
            ->all();
        $enabled = isset($preferences['enabled']) ? (array) $preferences['enabled'] : ($defaultEnabled ?: $keys);
        $enabled = array_values(array_intersect($keys, (array) $enabled));
        if (!$enabled) {
            $enabled = $keys;
        }

        $preferred = $preferences['preferred'] ?? array_slice($enabled, 0, $limit);
        $preferred = array_values(array_intersect($enabled, (array) $preferred));
        $preferred = array_slice($preferred, 0, $limit);

        return [
            'enabled' => $enabled,
            'preferred' => $preferred,
        ];
    }

    private function resolveCustomSuppliers(?array $preferences): array
    {
        if (!is_array($preferences)) {
            return [];
        }

        $custom = $preferences['custom_suppliers'] ?? [];
        if (!is_array($custom)) {
            return [];
        }

        return array_values(array_filter($custom, function ($supplier) {
            return is_array($supplier) && !empty($supplier['key']);
        }));
    }

    private function mergeSuppliers(array $suppliers, array $customSuppliers): array
    {
        $byKey = [];
        foreach ($suppliers as $supplier) {
            if (is_array($supplier) && !empty($supplier['key'])) {
                $byKey[$supplier['key']] = $supplier;
            }
        }

        foreach ($customSuppliers as $supplier) {
            if (is_array($supplier) && !empty($supplier['key'])) {
                $byKey[$supplier['key']] = $supplier;
            }
        }

        return array_values($byKey);
    }

    private function resolveSupplierNames(array $suppliers, array $keys): array
    {
        $map = collect($suppliers)->keyBy('key');

        return array_values(array_filter(array_map(function ($key) use ($map) {
            return $map[$key]['name'] ?? null;
        }, $keys)));
    }
}
