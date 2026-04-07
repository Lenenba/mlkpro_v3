<?php

use App\Enums\BillingPeriod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plans') || ! Schema::hasTable('plan_prices')) {
            return;
        }

        $annualDiscountPercent = max(0, min(100, (float) config('billing.annual_discount_percent', 20)));
        $annualDiscountMultiplier = (100 - $annualDiscountPercent) / 100;
        $configuredDefaults = (array) config('billing.catalog_defaults', []);
        $plans = DB::table('plans')
            ->select('id', 'code', 'contact_only')
            ->get()
            ->keyBy('id');

        DB::table('plan_prices')
            ->where('billing_period', BillingPeriod::MONTHLY->value)
            ->orderBy('id')
            ->chunkById(100, function ($monthlyPrices) use ($annualDiscountMultiplier, $configuredDefaults, $plans): void {
                foreach ($monthlyPrices as $monthlyPrice) {
                    $plan = $plans->get($monthlyPrice->plan_id);
                    if (! $plan || (bool) $plan->contact_only) {
                        continue;
                    }

                    $yearlyAmount = number_format((float) $monthlyPrice->amount * 12 * $annualDiscountMultiplier, 2, '.', '');
                    $existingYearly = DB::table('plan_prices')
                        ->where('plan_id', $monthlyPrice->plan_id)
                        ->where('currency_code', $monthlyPrice->currency_code)
                        ->where('billing_period', BillingPeriod::YEARLY->value)
                        ->first();

                    $configuredPriceId = data_get(
                        $configuredDefaults,
                        $plan->code.'.prices.'.$monthlyPrice->currency_code.'.'.BillingPeriod::YEARLY->value.'.stripe_price_id'
                    );
                    $stripePriceId = $this->normalizeNullableString($existingYearly?->stripe_price_id)
                        ?? $this->normalizeNullableString($configuredPriceId);

                    DB::table('plan_prices')->updateOrInsert(
                        [
                            'plan_id' => $monthlyPrice->plan_id,
                            'currency_code' => $monthlyPrice->currency_code,
                            'billing_period' => BillingPeriod::YEARLY->value,
                        ],
                        [
                            'amount' => $yearlyAmount,
                            'stripe_price_id' => $stripePriceId,
                            'is_active' => (bool) $monthlyPrice->is_active,
                            'created_at' => $existingYearly?->created_at ?? now(),
                            'updated_at' => now(),
                        ]
                    );
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('plan_prices')) {
            return;
        }

        DB::table('plan_prices')
            ->where('billing_period', BillingPeriod::YEARLY->value)
            ->delete();
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
};
