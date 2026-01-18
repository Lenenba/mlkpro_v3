<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use App\Models\Warehouse;
use App\Services\CompanyNotificationPreferenceService;
use App\Services\SupplierDirectory;
use App\Services\UsageLimitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        $customSuppliers = $this->normalizeCustomSuppliers($user->company_supplier_preferences['custom_suppliers'] ?? [], $supplierCountry);
        $suppliers = $this->mergeSuppliers($suppliers, $customSuppliers);
        $supplierPreferences = $this->resolveSupplierPreferences($user->company_supplier_preferences, $suppliers);
        $accountId = $user->accountOwnerId();
        $warehouses = Warehouse::query()
            ->forAccount($accountId)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'address', 'city', 'state', 'postal_code', 'country', 'is_default', 'is_active']);
        $notificationSettings = app(CompanyNotificationPreferenceService::class)->resolveFor($user);

        return $this->inertiaOrJson('Settings/Company', [
            'company' => [
                'company_name' => $user->company_name,
                'company_logo' => $companyLogo,
                'company_description' => $user->company_description,
                'company_country' => $user->company_country,
                'company_province' => $user->company_province,
                'company_city' => $user->company_city,
                'company_timezone' => $user->company_timezone,
                'company_type' => $user->company_type,
                'fulfillment' => $user->company_fulfillment ?? null,
                'company_notification_settings' => $notificationSettings,
            ],
            'categories' => ProductCategory::forAccount($accountId)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name']),
            'usage_limits' => $usageLimits,
            'suppliers' => $suppliers,
            'supplier_preferences' => $supplierPreferences,
            'preferred_limit' => config('suppliers.preferred_limit', 4),
            'warehouses' => $warehouses,
            'api_tokens' => $user->tokens()
                ->orderByDesc('created_at')
                ->get(['id', 'name', 'abilities', 'last_used_at', 'created_at', 'expires_at']),
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
        $preferredLimit = (int) config('suppliers.preferred_limit', 4);
        $supplierKeys = collect($suppliers)->pluck('key')->filter()->values()->all();
        $preparedCustomSuppliers = $this->prepareCustomSuppliers($request->input('custom_suppliers', []));
        if ($preparedCustomSuppliers) {
            $request->merge(['custom_suppliers' => $preparedCustomSuppliers]);
            $customKeys = collect($preparedCustomSuppliers)->pluck('key')->filter()->values()->all();
            $supplierKeys = array_values(array_unique(array_merge($supplierKeys, $customKeys)));
        }

        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'company_logo' => 'nullable|image|max:2048',
            'company_description' => 'nullable|string|max:2000',
            'company_country' => 'nullable|string|max:255',
            'company_province' => 'nullable|string|max:255',
            'company_city' => 'nullable|string|max:255',
            'company_timezone' => 'nullable|string|max:255',
            'company_type' => 'required|string|in:services,products',
            'company_fulfillment' => 'nullable|array',
            'company_fulfillment.delivery_enabled' => 'nullable|boolean',
            'company_fulfillment.pickup_enabled' => 'nullable|boolean',
            'company_fulfillment.delivery_fee' => 'nullable|numeric|min:0',
            'company_fulfillment.delivery_zone' => 'nullable|string|max:255',
            'company_fulfillment.pickup_address' => 'nullable|string|max:500',
            'company_fulfillment.prep_time_minutes' => 'nullable|integer|min:0|max:1440',
            'company_fulfillment.delivery_notes' => 'nullable|string|max:500',
            'company_fulfillment.pickup_notes' => 'nullable|string|max:500',
            'company_notification_settings' => 'nullable|array',
            'company_notification_settings.task_day' => 'nullable|array',
            'company_notification_settings.task_day.email' => 'nullable|boolean',
            'company_notification_settings.task_day.sms' => 'nullable|boolean',
            'company_notification_settings.task_day.whatsapp' => 'nullable|boolean',
            'custom_suppliers' => 'nullable|array',
            'custom_suppliers.*.key' => 'required|string|max:80',
            'custom_suppliers.*.name' => 'required|string|max:255',
            'custom_suppliers.*.url' => 'required|url|max:255',
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

        $customSuppliers = $this->normalizeCustomSuppliers($validated['custom_suppliers'] ?? [], $supplierCountry);
        $suppliers = $this->mergeSuppliers($suppliers, $customSuppliers);
        $supplierKeys = collect($suppliers)->pluck('key')->filter()->values()->all();

        $enabledSuppliers = array_values(array_unique($validated['supplier_enabled'] ?? []));
        $preferredSuppliers = array_values(array_unique($validated['supplier_preferred'] ?? []));
        if (!$enabledSuppliers) {
            $enabledSuppliers = $supplierKeys;
        }
        $preferredSuppliers = array_values(array_intersect($preferredSuppliers, $enabledSuppliers));

        if (count($preferredSuppliers) > $preferredLimit) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Validation error.',
                    'errors' => [
                        'supplier_preferred' => ["Selectionnez jusqu a {$preferredLimit} fournisseurs preferes."],
                    ],
                ], 422);
            }

            return redirect()->back()->withErrors([
                'supplier_preferred' => "Selectionnez jusqu a {$preferredLimit} fournisseurs preferes.",
            ]);
        }

        $notificationSettings = $user->company_notification_settings;
        if (array_key_exists('company_notification_settings', $validated)) {
            $notificationSettings = app(CompanyNotificationPreferenceService::class)
                ->mergeSettings($user, $validated['company_notification_settings'] ?? []);
        }

        $user->update([
            'company_name' => $validated['company_name'],
            'company_logo' => $companyLogoPath,
            'company_description' => $validated['company_description'] ?? null,
            'company_country' => $validated['company_country'] ?? null,
            'company_province' => $validated['company_province'] ?? null,
            'company_city' => $validated['company_city'] ?? null,
            'company_timezone' => $validated['company_timezone'] ?? null,
            'company_type' => $validated['company_type'],
            'company_supplier_preferences' => [
                'enabled' => $enabledSuppliers,
                'preferred' => $preferredSuppliers,
                'custom_suppliers' => $customSuppliers,
            ],
            'company_fulfillment' => $validated['company_fulfillment'] ?? $user->company_fulfillment,
            'company_notification_settings' => $notificationSettings,
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

    private function prepareCustomSuppliers($suppliers): array
    {
        if (!is_array($suppliers)) {
            return [];
        }

        $prepared = [];
        foreach ($suppliers as $supplier) {
            if (!is_array($supplier)) {
                continue;
            }

            $key = isset($supplier['key']) && $supplier['key'] !== ''
                ? (string) $supplier['key']
                : 'custom_' . Str::uuid()->toString();
            $name = trim((string) ($supplier['name'] ?? ''));
            $url = trim((string) ($supplier['url'] ?? ''));
            if ($url && !str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                $url = 'https://' . $url;
            }

            $prepared[] = [
                'key' => $key,
                'name' => $name,
                'url' => $url,
            ];
        }

        return $prepared;
    }

    private function normalizeCustomSuppliers(array $suppliers, ?string $defaultCountry = null): array
    {
        $normalized = [];
        foreach ($suppliers as $supplier) {
            if (!is_array($supplier)) {
                continue;
            }

            $key = trim((string) ($supplier['key'] ?? ''));
            $name = trim((string) ($supplier['name'] ?? ''));
            $url = trim((string) ($supplier['url'] ?? ''));
            if ($key === '' || $name === '' || $url === '') {
                continue;
            }

            if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                $url = 'https://' . $url;
            }

            $domain = parse_url($url, PHP_URL_HOST);
            $domain = $domain ? strtolower((string) $domain) : null;
            if ($domain && str_starts_with($domain, 'www.')) {
                $domain = substr($domain, 4);
            }

            $normalized[$key] = [
                'key' => $key,
                'name' => $name,
                'url' => $url,
                'domains' => $domain ? [$domain] : [],
                'country' => $supplier['country'] ?? $defaultCountry,
                'default_enabled' => false,
                'is_custom' => true,
            ];
        }

        return array_values($normalized);
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
}
