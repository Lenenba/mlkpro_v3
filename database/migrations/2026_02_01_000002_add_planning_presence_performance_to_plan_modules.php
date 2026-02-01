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
        $moduleKeys = ['performance', 'presence', 'planning'];

        foreach ($plans as $planKey => $plan) {
            $current = $planModules[$planKey] ?? [];
            foreach ($moduleKeys as $moduleKey) {
                if (!array_key_exists($moduleKey, $current)) {
                    $current[$moduleKey] = true;
                }
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
