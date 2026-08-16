<?php

namespace App\Services;

use App\Support\Billing\DefaultPlanCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ConfiguredPlanCatalogReconciler
{
    /**
     * @return array{plans_created: int, prices_created: int, prices_repaired: int, custom_prices_preserved: int}
     */
    public function reconcile(): array
    {
        $summary = [
            'plans_created' => 0,
            'prices_created' => 0,
            'prices_repaired' => 0,
            'custom_prices_preserved' => 0,
        ];

        if (! Schema::hasTable('plans') || ! Schema::hasTable('plan_prices')) {
            return $summary;
        }

        return DB::transaction(function () use ($summary): array {
            $result = $summary;
            $nextSortOrder = ((int) DB::table('plans')->max('sort_order')) + 1;

            foreach (DefaultPlanCatalog::periodicDefinitions() as $definition) {
                $plan = DB::table('plans')
                    ->where('code', $definition['code'])
                    ->first();

                if (! $plan) {
                    $planId = DB::table('plans')->insertGetId([
                        'code' => $definition['code'],
                        'name' => $definition['name'],
                        'description' => $definition['description'],
                        'is_active' => (bool) ($definition['is_active'] ?? true),
                        'contact_only' => (bool) ($definition['contact_only'] ?? false),
                        'sort_order' => $nextSortOrder++,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $result['plans_created']++;
                } else {
                    $planId = (int) $plan->id;
                }

                foreach ($definition['prices'] as $currencyCode => $periods) {
                    foreach ($periods as $billingPeriod => $configuredPrice) {
                        $existingPrice = DB::table('plan_prices')
                            ->where('plan_id', $planId)
                            ->where('currency_code', $currencyCode)
                            ->where('billing_period', $billingPeriod)
                            ->first();

                        $configuredPriceId = $this->normalizeNullableString(
                            $configuredPrice['stripe_price_id'] ?? null
                        );
                        $configuredAmount = number_format((float) ($configuredPrice['amount'] ?? 0), 2, '.', '');

                        if (! $existingPrice) {
                            DB::table('plan_prices')->insert([
                                'plan_id' => $planId,
                                'currency_code' => $currencyCode,
                                'billing_period' => $billingPeriod,
                                'amount' => $configuredAmount,
                                'stripe_price_id' => $configuredPriceId,
                                'is_active' => true,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            $result['prices_created']++;

                            continue;
                        }

                        $existingPriceId = $this->normalizeNullableString($existingPrice->stripe_price_id ?? null);

                        // A configured Stripe price is authoritative when the row has no
                        // mapping yet or already points to that same immutable Stripe price.
                        // A different non-empty mapping is treated as an intentional override.
                        if ($configuredPriceId === null) {
                            continue;
                        }
                        if ($existingPriceId !== null && $existingPriceId !== $configuredPriceId) {
                            $result['custom_prices_preserved']++;

                            continue;
                        }

                        $updates = [];
                        if ($existingPriceId !== $configuredPriceId) {
                            $updates['stripe_price_id'] = $configuredPriceId;
                        }
                        if (number_format((float) $existingPrice->amount, 2, '.', '') !== $configuredAmount) {
                            $updates['amount'] = $configuredAmount;
                        }

                        if ($updates !== []) {
                            $updates['updated_at'] = now();

                            DB::table('plan_prices')
                                ->where('id', $existingPrice->id)
                                ->update($updates);
                            $result['prices_repaired']++;
                        }
                    }
                }
            }

            return $result;
        });
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
