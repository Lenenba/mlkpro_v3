<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Models\PlatformSetting;
use App\Support\PlatformPermissions;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PlatformSettingsController extends BaseController
{
    private array $limitKeys = [
        'quotes',
        'requests',
        'plan_scan_quotes',
        'invoices',
        'jobs',
        'products',
        'services',
        'tasks',
        'team_members',
    ];

    private array $moduleKeys = [
        'quotes',
        'requests',
        'plan_scans',
        'invoices',
        'jobs',
        'products',
        'services',
        'tasks',
        'team_members',
        'assistant',
    ];

    public function show(Request $request)
    {
        $this->authorizePermission($request, PlatformPermissions::SETTINGS_MANAGE);

        $maintenance = PlatformSetting::getValue('maintenance', ['enabled' => false, 'message' => '']);
        $templates = PlatformSetting::getValue('templates', [
            'email_default' => '',
            'quote_default' => '',
            'invoice_default' => '',
        ]);
        $planLimits = PlatformSetting::getValue('plan_limits', []);
        $planModules = PlatformSetting::getValue('plan_modules', []);
        $rawPlans = config('billing.plans', []);
        $plans = collect($rawPlans)
            ->map(function (array $plan, string $key) {
                return [
                    'key' => $key,
                    'name' => $plan['name'] ?? $key,
                    'price_id' => $plan['price_id'] ?? null,
                ];
            })
            ->values()
            ->all();

        $planKeys = array_keys($rawPlans);
        if (empty($planKeys)) {
            $planKeys = array_values(array_unique(array_merge(array_keys($planLimits), array_keys($planModules))));
        }
        if (empty($planKeys)) {
            $planKeys = ['free'];
        }
        if (empty($plans)) {
            $plans = collect($planKeys)
                ->map(fn (string $key) => ['key' => $key, 'name' => $key, 'price_id' => null])
                ->values()
                ->all();
        }

        foreach ($planKeys as $planKey) {
            $planInput = $planLimits[$planKey] ?? [];
            foreach ($this->limitKeys as $limitKey) {
                $planLimits[$planKey][$limitKey] = array_key_exists($limitKey, $planInput)
                    ? $planInput[$limitKey]
                    : null;
            }
            $moduleInput = $planModules[$planKey] ?? [];
            foreach ($this->moduleKeys as $moduleKey) {
                $planModules[$planKey][$moduleKey] = array_key_exists($moduleKey, $moduleInput)
                    ? (bool) $moduleInput[$moduleKey]
                    : true;
            }
        }

        return $this->jsonResponse([
            'maintenance' => $maintenance,
            'templates' => $templates,
            'plans' => $plans,
            'plan_limits' => $planLimits,
            'plan_modules' => $planModules,
        ]);
    }

    public function update(Request $request)
    {
        $this->authorizePermission($request, PlatformPermissions::SETTINGS_MANAGE);

        $validated = $request->validate([
            'maintenance.enabled' => 'required|boolean',
            'maintenance.message' => 'nullable|string|max:500',
            'templates.email_default' => 'nullable|string|max:5000',
            'templates.quote_default' => 'nullable|string|max:5000',
            'templates.invoice_default' => 'nullable|string|max:5000',
            'plan_limits' => 'nullable|array',
            'plan_limits.*' => 'array',
            'plan_limits.*.*' => 'nullable|numeric|min:0',
            'plan_modules' => 'nullable|array',
            'plan_modules.*' => 'array',
            'plan_modules.*.*' => 'nullable|boolean',
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

        $limitsPayload = $this->buildLimitPayload($validated['plan_limits'] ?? []);
        PlatformSetting::setValue('plan_limits', $limitsPayload);

        $modulesPayload = $this->buildModulePayload($validated['plan_modules'] ?? []);
        PlatformSetting::setValue('plan_modules', $modulesPayload);

        $this->logAudit($request, 'platform_settings.updated');

        return $this->jsonResponse(['message' => 'Platform settings updated.']);
    }

    private function buildLimitPayload(array $inputLimits): array
    {
        $payload = [];
        foreach (config('billing.plans', []) as $planKey => $plan) {
            $planInput = $inputLimits[$planKey] ?? [];
            foreach ($this->limitKeys as $limitKey) {
                $value = $planInput[$limitKey] ?? null;
                $payload[$planKey][$limitKey] = is_numeric($value) ? max(0, (int) $value) : null;
            }
        }

        return $payload;
    }

    private function buildModulePayload(array $inputModules): array
    {
        $payload = [];
        foreach (config('billing.plans', []) as $planKey => $plan) {
            $planInput = $inputModules[$planKey] ?? [];
            foreach ($this->moduleKeys as $moduleKey) {
                $value = $planInput[$moduleKey] ?? null;
                $payload[$planKey][$moduleKey] = $value === null ? true : (bool) $value;
            }
        }

        return $payload;
    }
}
