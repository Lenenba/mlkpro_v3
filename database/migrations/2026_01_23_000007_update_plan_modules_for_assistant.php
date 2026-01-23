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
        $defaultAssistantPlan = array_key_exists('scale', $plans)
            ? 'scale'
            : array_key_last($plans);

        $allTrueOrNull = true;
        foreach ($plans as $planKey => $plan) {
            $value = $planModules[$planKey]['assistant'] ?? null;
            if ($value === false) {
                $allTrueOrNull = false;
                break;
            }
        }

        if (!$allTrueOrNull) {
            return;
        }

        foreach ($plans as $planKey => $plan) {
            $planModules[$planKey]['assistant'] = $planKey === $defaultAssistantPlan;
        }

        $setting->value = $planModules;
        $setting->save();
    }

    public function down(): void
    {
        // No safe rollback for customized plan modules.
    }
};
