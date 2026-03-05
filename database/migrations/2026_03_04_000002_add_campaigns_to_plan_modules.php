<?php

use App\Models\PlatformSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $setting = PlatformSetting::query()->where('key', 'plan_modules')->first();
        if (!$setting || !is_array($setting->value)) {
            return;
        }

        $plans = config('billing.plans', []);
        if (!$plans) {
            return;
        }

        $planModules = $setting->value;
        foreach ($plans as $planKey => $plan) {
            $current = is_array($planModules[$planKey] ?? null) ? $planModules[$planKey] : [];
            if (!array_key_exists('campaigns', $current)) {
                $current['campaigns'] = true;
            }
            $planModules[$planKey] = $current;
        }

        $setting->value = $planModules;
        $setting->save();
    }

    public function down(): void
    {
        // No safe rollback for customized plan modules.
    }
};

