<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Models\PlatformSetting;
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
        $planLimits = PlatformSetting::getValue('plan_limits', []);
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
            'plans' => $plans,
            'plan_limits' => $planLimits,
        ]);
    }

    public function update(Request $request): RedirectResponse
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

        $limitKeys = [
            'quotes',
            'invoices',
            'jobs',
            'products',
            'services',
            'tasks',
            'team_members',
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

        $this->logAudit($request, 'platform_settings.updated');

        return redirect()->back()->with('success', 'Platform settings updated.');
    }
}
