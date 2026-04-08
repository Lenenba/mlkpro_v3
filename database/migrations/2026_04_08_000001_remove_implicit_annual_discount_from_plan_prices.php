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

        $plans = DB::table('plans')
            ->select('id', 'contact_only')
            ->get()
            ->keyBy('id');

        DB::table('plan_prices')
            ->where('billing_period', BillingPeriod::MONTHLY->value)
            ->orderBy('id')
            ->chunkById(100, function ($monthlyPrices) use ($plans): void {
                foreach ($monthlyPrices as $monthlyPrice) {
                    $plan = $plans->get($monthlyPrice->plan_id);
                    if (! $plan || (bool) $plan->contact_only) {
                        continue;
                    }

                    $yearlyAmount = number_format((float) $monthlyPrice->amount * 12, 2, '.', '');
                    $existingYearly = DB::table('plan_prices')
                        ->where('plan_id', $monthlyPrice->plan_id)
                        ->where('currency_code', $monthlyPrice->currency_code)
                        ->where('billing_period', BillingPeriod::YEARLY->value)
                        ->first();

                    $existingYearlyAmount = $existingYearly
                        ? number_format((float) $existingYearly->amount, 2, '.', '')
                        : null;

                    DB::table('plan_prices')->updateOrInsert(
                        [
                            'plan_id' => $monthlyPrice->plan_id,
                            'currency_code' => $monthlyPrice->currency_code,
                            'billing_period' => BillingPeriod::YEARLY->value,
                        ],
                        [
                            'amount' => $yearlyAmount,
                            'stripe_price_id' => $existingYearlyAmount === $yearlyAmount
                                ? $this->normalizeNullableString($existingYearly?->stripe_price_id)
                                : null,
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
            ->orderBy('id')
            ->chunkById(100, function ($yearlyPrices): void {
                foreach ($yearlyPrices as $yearlyPrice) {
                    $monthlyPrice = DB::table('plan_prices')
                        ->where('plan_id', $yearlyPrice->plan_id)
                        ->where('currency_code', $yearlyPrice->currency_code)
                        ->where('billing_period', BillingPeriod::MONTHLY->value)
                        ->first();

                    if (! $monthlyPrice) {
                        continue;
                    }

                    DB::table('plan_prices')
                        ->where('id', $yearlyPrice->id)
                        ->update([
                            'amount' => number_format((float) $monthlyPrice->amount * 12 * 0.8, 2, '.', ''),
                            'stripe_price_id' => null,
                            'updated_at' => now(),
                        ]);
                }
            });
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
