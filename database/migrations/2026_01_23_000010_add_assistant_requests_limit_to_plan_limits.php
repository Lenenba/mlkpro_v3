<?php

use App\Models\PlatformSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $setting = PlatformSetting::query()->where('key', 'plan_limits')->first();
        if (!$setting || !is_array($setting->value)) {
            return;
        }

        $plans = config('billing.plans', []);
        if (!$plans) {
            return;
        }

        $limits = $setting->value;
        foreach ($plans as $planKey => $plan) {
            if (!array_key_exists($planKey, $limits)) {
                $limits[$planKey] = [];
            }
            if (!array_key_exists('assistant_requests', $limits[$planKey])) {
                $limits[$planKey]['assistant_requests'] = null;
            }
        }

        $setting->value = $limits;
        $setting->save();
    }

    public function down(): void
    {
        // No safe rollback for customized plan limits.
    }
};
