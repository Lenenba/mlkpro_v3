<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use App\Services\SupplierDirectory;
use App\Services\UsageLimitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CompanySettingsController extends Controller
{
    public function edit(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $companyLogo = null;
        if ($user->company_logo) {
            $path = $user->company_logo;
            $companyLogo = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
                ? $path
                : Storage::disk('public')->url($path);
        }

        $usageLimits = app(UsageLimitService::class)->buildForUser($user);
        $supplierDirectory = app(SupplierDirectory::class);
        $supplierCountry = $request->input('company_country')
            ?: ($user->company_country ?: config('suppliers.default_country'));
        $suppliers = $supplierDirectory->all($supplierCountry);
        $supplierPreferences = $this->resolveSupplierPreferences($user->company_supplier_preferences, $suppliers);
        $accountId = $user->accountOwnerId();

        return $this->inertiaOrJson('Settings/Company', [
            'company' => [
                'company_name' => $user->company_name,
                'company_logo' => $companyLogo,
                'company_description' => $user->company_description,
                'company_country' => $user->company_country,
                'company_province' => $user->company_province,
                'company_city' => $user->company_city,
                'company_type' => $user->company_type,
            ],
            'categories' => ProductCategory::forAccount($accountId)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name']),
            'usage_limits' => $usageLimits,
            'suppliers' => $suppliers,
            'supplier_preferences' => $supplierPreferences,
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $supplierDirectory = app(SupplierDirectory::class);
        $supplierCountry = $user->company_country ?: config('suppliers.default_country');
        $suppliers = $supplierDirectory->all($supplierCountry);
        $supplierKeys = collect($suppliers)->pluck('key')->filter()->values()->all();

        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'company_logo' => 'nullable|image|max:2048',
            'company_description' => 'nullable|string|max:2000',
            'company_country' => 'nullable|string|max:255',
            'company_province' => 'nullable|string|max:255',
            'company_city' => 'nullable|string|max:255',
            'company_type' => 'required|string|in:services,products',
            'supplier_enabled' => 'nullable|array',
            'supplier_enabled.*' => ['string', Rule::in($supplierKeys)],
            'supplier_preferred' => 'nullable|array',
            'supplier_preferred.*' => ['string', Rule::in($supplierKeys)],
        ]);

        $companyLogoPath = $user->company_logo;
        if ($request->hasFile('company_logo')) {
            $companyLogoPath = $request->file('company_logo')->store('company/logos', 'public');

            if ($user->company_logo && !str_starts_with($user->company_logo, 'http://') && !str_starts_with($user->company_logo, 'https://')) {
                Storage::disk('public')->delete($user->company_logo);
            }
        }

        $enabledSuppliers = array_values(array_unique($validated['supplier_enabled'] ?? []));
        $preferredSuppliers = array_values(array_unique($validated['supplier_preferred'] ?? []));
        if (!$enabledSuppliers) {
            $enabledSuppliers = $supplierKeys;
        }
        $preferredSuppliers = array_values(array_intersect($preferredSuppliers, $enabledSuppliers));

        if (count($preferredSuppliers) > 2) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Validation error.',
                    'errors' => [
                        'supplier_preferred' => ['Selectionnez jusqu a 2 fournisseurs preferes.'],
                    ],
                ], 422);
            }

            return redirect()->back()->withErrors([
                'supplier_preferred' => 'Selectionnez jusqu a 2 fournisseurs preferes.',
            ]);
        }

        $user->update([
            'company_name' => $validated['company_name'],
            'company_logo' => $companyLogoPath,
            'company_description' => $validated['company_description'] ?? null,
            'company_country' => $validated['company_country'] ?? null,
            'company_province' => $validated['company_province'] ?? null,
            'company_city' => $validated['company_city'] ?? null,
            'company_type' => $validated['company_type'],
            'company_supplier_preferences' => [
                'enabled' => $enabledSuppliers,
                'preferred' => $preferredSuppliers,
            ],
        ]);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Company settings updated.',
                'company' => $user->fresh(),
            ]);
        }

        return redirect()->back()->with('success', 'Company settings updated.');
    }

    private function resolveSupplierPreferences(?array $preferences, array $suppliers): array
    {
        $preferences = is_array($preferences) ? $preferences : [];
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

        $preferred = $preferences['preferred'] ?? array_slice($enabled, 0, 2);
        $preferred = array_values(array_intersect($enabled, (array) $preferred));
        $preferred = array_slice($preferred, 0, 2);

        return [
            'enabled' => $enabled,
            'preferred' => $preferred,
        ];
    }
}
