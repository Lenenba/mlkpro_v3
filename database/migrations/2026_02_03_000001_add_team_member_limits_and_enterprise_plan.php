<?php

use App\Models\PlatformSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $plans = config('billing.plans', []);
        if (!$plans) {
            return;
        }

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

        $teamDefaults = [
            'free' => 3,
            'starter' => 10,
            'growth' => 25,
            'scale' => 50,
        ];

        $limitSetting = PlatformSetting::query()->firstOrCreate(
            ['key' => 'plan_limits'],
            ['value' => []]
        );

        $planLimits = is_array($limitSetting->value) ? $limitSetting->value : [];
        foreach ($plans as $planKey => $plan) {
            $current = is_array($planLimits[$planKey] ?? null) ? $planLimits[$planKey] : [];
            foreach ($limitKeys as $limitKey) {
                if (!array_key_exists($limitKey, $current)) {
                    $current[$limitKey] = null;
                }
            }

            if (!is_numeric($current['team_members'] ?? null) && array_key_exists($planKey, $teamDefaults)) {
                $current['team_members'] = $teamDefaults[$planKey];
            }

            $planLimits[$planKey] = $current;
        }

        $limitSetting->value = $planLimits;
        $limitSetting->save();

        $moduleKeys = [
            'quotes',
            'requests',
            'plan_scans',
            'invoices',
            'jobs',
            'products',
            'performance',
            'presence',
            'planning',
            'sales',
            'services',
            'tasks',
            'team_members',
            'assistant',
        ];

        $moduleSetting = PlatformSetting::query()->firstOrCreate(
            ['key' => 'plan_modules'],
            ['value' => []]
        );

        $planModules = is_array($moduleSetting->value) ? $moduleSetting->value : [];
        foreach ($plans as $planKey => $plan) {
            $current = is_array($planModules[$planKey] ?? null) ? $planModules[$planKey] : [];
            foreach ($moduleKeys as $moduleKey) {
                if (!array_key_exists($moduleKey, $current)) {
                    $current[$moduleKey] = true;
                }
            }

            if ($planKey === 'enterprise') {
                $current['assistant'] = true;
            }

            $planModules[$planKey] = $current;
        }

        $moduleSetting->value = $planModules;
        $moduleSetting->save();
    }

    public function down(): void
    {
        // No safe rollback for customized plan settings.
    }
};
