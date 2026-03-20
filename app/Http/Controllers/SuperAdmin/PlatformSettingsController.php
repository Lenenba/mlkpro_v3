<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Models\PlatformSetting;
use App\Services\BillingPlanService;
use App\Support\PlanDisplay;
use App\Support\PlatformPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlatformSettingsController extends BaseSuperAdminController
{
    public function edit(Request $request): Response
    {
        $this->authorizePermission($request, PlatformPermissions::SETTINGS_MANAGE);

        $maintenance = PlatformSetting::getValue('maintenance', [
            'enabled' => false,
            'message' => '',
        ]);
        $templates = PlatformSetting::getValue('templates', [
            'email_default' => '',
            'quote_default' => '',
            'invoice_default' => '',
        ]);
        $publicNavigation = PlatformSetting::getValue('public_navigation', [
            'contact_form_url' => '',
        ]);
        $planLimits = PlatformSetting::getValue('plan_limits', []);
        $planModules = PlatformSetting::getValue('plan_modules', []);
        $planDisplayOverrides = PlatformSetting::getValue('plan_display', []);
        $planDisplay = PlanDisplay::normalize(config('billing.plans', []), $planDisplayOverrides);
        $plans = collect(config('billing.plans', []))
            ->map(function (array $plan, string $key) {
                return [
                    'key' => $key,
                    'name' => $plan['name'] ?? $key,
                    'price_id' => $plan['price_id'] ?? null,
                ];
            })
            ->values()
            ->all();

        return Inertia::render('SuperAdmin/Settings/Edit', [
            'maintenance' => $maintenance,
            'templates' => $templates,
            'public_navigation' => $publicNavigation,
            'plans' => $plans,
            'plan_prices' => app(BillingPlanService::class)->priceMatrix(),
            'plan_limits' => $planLimits,
            'plan_modules' => $planModules,
            'plan_display' => $planDisplay,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::SETTINGS_MANAGE);
        $isSuperadmin = (bool) $request->user()?->isSuperadmin();

        $validated = $request->validate([
            'maintenance.enabled' => 'required|boolean',
            'maintenance.message' => 'nullable|string|max:500',
            'templates.email_default' => 'nullable|string|max:5000',
            'templates.quote_default' => 'nullable|string|max:5000',
            'templates.invoice_default' => 'nullable|string|max:5000',
            'public_navigation.contact_form_url' => 'nullable|string|max:2048',
            'plan_limits' => 'nullable|array',
            'plan_limits.*' => 'array',
            'plan_limits.*.*' => 'nullable|numeric|min:0',
            'plan_modules' => 'nullable|array',
            'plan_modules.*' => 'array',
            'plan_modules.*.*' => 'nullable|boolean',
            'plan_display' => 'nullable|array',
            'plan_display.*' => 'array',
            'plan_display.*.name' => 'nullable|string|max:120',
            'plan_display.*.price' => 'nullable',
            'plan_display.*.badge' => 'nullable|string|max:40',
            'plan_display.*.features' => 'nullable|array',
            'plan_display.*.features.*' => 'nullable|string|max:140',
            'plan_prices' => 'nullable|array',
            'plan_prices.*' => 'array',
            'plan_prices.*.*.amount' => 'nullable|numeric|min:0',
            'plan_prices.*.*.stripe_price_id' => 'nullable|string|max:255',
            'plan_prices.*.*.currency_code' => 'nullable|string|size:3',
            'plan_prices.*.*.billing_period' => 'nullable|string|max:20',
            'plan_prices.*.*.is_active' => 'nullable|boolean',
        ]);

        PlatformSetting::setValue('maintenance', [
            'enabled' => (bool) $validated['maintenance']['enabled'],
            'message' => $validated['maintenance']['message'] ?? '',
        ]);

        PlatformSetting::setValue('templates', [
            'email_default' => $validated['templates']['email_default'] ?? '',
            'quote_default' => $validated['templates']['quote_default'] ?? '',
            'invoice_default' => $validated['templates']['invoice_default'] ?? '',
        ]);

        PlatformSetting::setValue('public_navigation', $this->sanitizePublicNavigation(
            $validated['public_navigation'] ?? []
        ));

        $limitKeys = [
            'quotes',
            'requests',
            'plan_scan_quotes',
            'invoices',
            'jobs',
            'products',
            'services',
            'tasks',
            'team_members',
            'assistant_requests',
        ];
        $moduleKeys = [
            'quotes',
            'requests',
            'reservations',
            'plan_scans',
            'invoices',
            'jobs',
            'products',
            'performance',
            'presence',
            'planning',
            'services',
            'tasks',
            'team_members',
            'assistant',
            'loyalty',
            'campaigns',
        ];
        $limitsPayload = [];
        $inputLimits = $validated['plan_limits'] ?? [];
        foreach (config('billing.plans', []) as $planKey => $plan) {
            $planInput = $inputLimits[$planKey] ?? [];
            foreach ($limitKeys as $limitKey) {
                $value = $planInput[$limitKey] ?? null;
                $limitsPayload[$planKey][$limitKey] = is_numeric($value) ? max(0, (int) $value) : null;
            }
        }

        PlatformSetting::setValue('plan_limits', $limitsPayload);

        if (array_key_exists('plan_modules', $validated) && ! $isSuperadmin) {
            abort(403);
        }

        if ($isSuperadmin && array_key_exists('plan_modules', $validated)) {
            $modulesPayload = [];
            $inputModules = $validated['plan_modules'] ?? [];
            foreach (config('billing.plans', []) as $planKey => $plan) {
                $planInput = $inputModules[$planKey] ?? [];
                foreach ($moduleKeys as $moduleKey) {
                    $value = $planInput[$moduleKey] ?? null;
                    $modulesPayload[$planKey][$moduleKey] = $value === null ? true : (bool) $value;
                }
            }

            PlatformSetting::setValue('plan_modules', $modulesPayload);
        }

        $displayPayload = [];
        $inputDisplay = $validated['plan_display'] ?? [];
        foreach (config('billing.plans', []) as $planKey => $plan) {
            $planInput = $inputDisplay[$planKey] ?? [];
            $name = is_string($planInput['name'] ?? null) ? trim($planInput['name']) : '';
            $badge = is_string($planInput['badge'] ?? null) ? trim($planInput['badge']) : '';
            $price = $planInput['price'] ?? null;
            if (is_string($price)) {
                $price = trim($price);
                $price = $price === '' ? null : $price;
            }
            $features = $planInput['features'] ?? [];
            if (! is_array($features)) {
                $features = [];
            }
            $features = collect($features)
                ->map(fn ($feature) => is_string($feature) ? trim($feature) : '')
                ->filter()
                ->values()
                ->all();

            $displayPayload[$planKey] = [
                'name' => $name !== '' ? $name : ($plan['name'] ?? ucfirst($planKey)),
                'price' => $price,
                'badge' => $badge !== '' ? $badge : null,
                'features' => $features,
            ];
        }

        PlatformSetting::setValue('plan_display', $displayPayload);
        app(BillingPlanService::class)->upsertPricing($validated['plan_prices'] ?? []);

        $this->logAudit($request, 'platform_settings.updated');

        return redirect()->back()->with('success', 'Platform settings updated.');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function sanitizePublicNavigation(array $input): array
    {
        $contactFormUrl = trim((string) ($input['contact_form_url'] ?? ''));

        if ($contactFormUrl !== '' && ! str_starts_with($contactFormUrl, '/')) {
            $contactFormUrl = filter_var($contactFormUrl, FILTER_VALIDATE_URL) ? $contactFormUrl : '';
        }

        return [
            'contact_form_url' => $contactFormUrl,
        ];
    }
}
