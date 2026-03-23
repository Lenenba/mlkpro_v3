<?php

use App\Models\PlatformSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $setting = PlatformSetting::query()->firstOrCreate(
            ['key' => 'plan_limits'],
            ['value' => []]
        );

        $limits = is_array($setting->value) ? $setting->value : [];
        $plans = config('billing.plans', []);
        if (! is_array($plans) || $plans === []) {
            return;
        }

        foreach (array_keys($plans) as $planKey) {
            $current = is_array($limits[$planKey] ?? null) ? $limits[$planKey] : [];

            foreach (['quotes', 'requests', 'invoices', 'products', 'services'] as $key) {
                $current[$key] = null;
            }

            $limits[$planKey] = $current;
        }

        $setting->value = $limits;
        $setting->save();
    }

    public function down(): void
    {
        // No safe rollback for customized plan limits.
    }
};
