<?php

use App\Models\PlatformSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $setting = PlatformSetting::query()->where('key', 'plan_modules')->first();
        if (!$setting) {
            return;
        }

        $planModules = is_array($setting->value) ? $setting->value : [];
        if (empty($planModules)) {
            return;
        }

        foreach ($planModules as $planKey => $modules) {
            $current = is_array($modules) ? $modules : [];
            if (!array_key_exists('reservations', $current)) {
                $current['reservations'] = true;
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

