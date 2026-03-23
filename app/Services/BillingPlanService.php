<?php

namespace App\Services;

use App\Data\PlanPriceData;
use App\Enums\BillingPeriod;
use App\Enums\CurrencyCode;
use App\Exceptions\Billing\PlanPriceNotFoundException;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\PlanDisplay;
use Laravel\Paddle\Cashier;

class BillingPlanService
{
    public function supportedCurrencies(): array
    {
        return CurrencyCode::values();
    }

    public function priceMatrix(): array
    {
        $plans = Plan::query()
            ->with(['prices' => fn ($query) => $query->orderBy('currency_code')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->keyBy('code');

        $matrix = [];
        foreach ($this->configuredPlanCodes() as $planCode) {
            $plan = $plans->get($planCode);
            foreach (CurrencyCode::cases() as $currency) {
                $planPrice = $plan?->prices
                    ->first(fn (PlanPrice $price) => $price->currency_code === $currency && $price->billing_period === BillingPeriod::MONTHLY);

                $matrix[$planCode][$currency->value] = [
                    'currency_code' => $currency->value,
                    'billing_period' => BillingPeriod::MONTHLY->value,
                    'amount' => $planPrice?->amount !== null ? number_format((float) $planPrice->amount, 2, '.', '') : null,
                    'stripe_price_id' => $planPrice?->stripe_price_id,
                    'is_active' => $planPrice ? (bool) $planPrice->is_active : true,
                ];
            }
        }

        return $matrix;
    }

    public function plansForCurrency(CurrencyCode|string $currencyCode): array
    {
        $currency = CurrencyCode::tryFromMixed($currencyCode) ?? CurrencyCode::default();
        $planDisplayOverrides = PlatformSetting::getValue('plan_display', []);
        $plans = Plan::query()
            ->with(['prices' => fn ($query) => $query->where('is_active', true)])
            ->get()
            ->keyBy('code');

        return collect($this->configuredPlans())
            ->map(function (array $configuredPlan, string $planCode) use ($currency, $planDisplayOverrides, $plans) {
                $display = PlanDisplay::merge($configuredPlan, $planCode, $planDisplayOverrides);
                $plan = $plans->get($planCode);
                $planPrice = $plan?->prices
                    ?->first(fn (PlanPrice $price) => $price->currency_code === $currency && $price->billing_period === BillingPeriod::MONTHLY);
                $metadata = $this->metadataForConfiguredPlan($configuredPlan);

                $amount = $planPrice?->amount !== null
                    ? number_format((float) $planPrice->amount, 2, '.', '')
                    : null;

                return [
                    'key' => $planCode,
                    'plan_id' => $plan?->id,
                    'plan_price_id' => $planPrice?->id,
                    'name' => $display['name'],
                    'badge' => $display['badge'],
                    'features' => $display['features'],
                    'price_id' => $planPrice?->stripe_price_id,
                    'price' => $amount ?? ($display['price'] ?? null),
                    'amount' => $amount,
                    'currency_code' => $amount !== null ? $currency->value : null,
                    'billing_period' => BillingPeriod::MONTHLY->value,
                    'display_price' => $amount !== null
                        ? $this->formatMoney((float) $amount, $currency)
                        : $this->resolveLegacyDisplayPrice($display['price'] ?? null),
                    'contact_only' => (bool) ($configuredPlan['contact_only'] ?? $plan?->contact_only ?? false),
                    'team_members_min' => is_numeric($configuredPlan['team_members_min'] ?? null)
                        ? (int) $configuredPlan['team_members_min']
                        : null,
                    'audience' => $metadata['audience'],
                    'owner_only' => $metadata['owner_only'],
                    'recommended' => $metadata['recommended'],
                    'onboarding_enabled' => $metadata['onboarding_enabled'],
                    'prices_by_currency' => $this->currencyOptionsForPlan($planCode),
                ];
            })
            ->values()
            ->all();
    }

    public function plansForTenant(User $tenant): array
    {
        $currency = app(ResolveTenantCurrency::class)->forUser($tenant)->currencyCode;

        return $this->plansForCurrency($currency);
    }

    public function resolvePlanPriceForTenant(
        User $tenant,
        string $planCode,
        BillingPeriod|string|null $billingPeriod = null
    ): PlanPriceData {
        $currency = app(ResolveTenantCurrency::class)->forUser($tenant)->currencyCode;

        return $this->resolveActivePlanPrice($planCode, $currency, $billingPeriod);
    }

    public function resolveActivePlanPrice(
        string $planCode,
        CurrencyCode|string $currencyCode,
        BillingPeriod|string|null $billingPeriod = null
    ): PlanPriceData {
        $currency = CurrencyCode::tryFromMixed($currencyCode) ?? CurrencyCode::default();
        $period = $billingPeriod instanceof BillingPeriod
            ? $billingPeriod
            : (BillingPeriod::tryFromMixed($billingPeriod) ?? BillingPeriod::default());

        $planPrice = PlanPrice::query()
            ->select('plan_prices.*')
            ->join('plans', 'plans.id', '=', 'plan_prices.plan_id')
            ->where('plans.code', $planCode)
            ->where('plan_prices.currency_code', $currency->value)
            ->where('plan_prices.billing_period', $period->value)
            ->where('plan_prices.is_active', true)
            ->with('plan')
            ->first();

        if (! $planPrice) {
            throw new PlanPriceNotFoundException(sprintf(
                'No active plan price exists for plan [%s] in currency [%s] and period [%s].',
                $planCode,
                $currency->value,
                $period->value,
            ));
        }

        return PlanPriceData::fromModel($planPrice);
    }

    public function resolveByStripePriceId(?string $stripePriceId): ?PlanPriceData
    {
        if (! $stripePriceId) {
            return null;
        }

        $planPrice = PlanPrice::query()
            ->where('stripe_price_id', $stripePriceId)
            ->with('plan')
            ->first();

        return $planPrice ? PlanPriceData::fromModel($planPrice) : null;
    }

    public function resolvePlanCodeByStripePriceId(?string $stripePriceId): ?string
    {
        return $this->resolveByStripePriceId($stripePriceId)?->planCode;
    }

    public function stripePriceIdsForPlan(string $planCode): array
    {
        return PlanPrice::query()
            ->select('plan_prices.stripe_price_id')
            ->join('plans', 'plans.id', '=', 'plan_prices.plan_id')
            ->where('plans.code', $planCode)
            ->whereNotNull('plan_prices.stripe_price_id')
            ->pluck('plan_prices.stripe_price_id')
            ->filter()
            ->values()
            ->all();
    }

    public function upsertPricing(array $pricingPayload): void
    {
        foreach ($pricingPayload as $planCode => $currencies) {
            $plan = Plan::query()->where('code', $planCode)->first();
            if (! $plan) {
                continue;
            }

            foreach ($currencies as $currencyCode => $row) {
                $currency = CurrencyCode::tryFromMixed($currencyCode);
                if (! $currency) {
                    continue;
                }

                $period = BillingPeriod::tryFromMixed($row['billing_period'] ?? null) ?? BillingPeriod::default();
                $amount = array_key_exists('amount', $row) && $row['amount'] !== '' && $row['amount'] !== null
                    ? number_format((float) $row['amount'], 2, '.', '')
                    : null;

                PlanPrice::query()->updateOrCreate(
                    [
                        'plan_id' => $plan->id,
                        'currency_code' => $currency->value,
                        'billing_period' => $period->value,
                    ],
                    [
                        'amount' => $amount ?? '0.00',
                        'stripe_price_id' => $this->normalizeNullableString($row['stripe_price_id'] ?? null),
                        'is_active' => array_key_exists('is_active', $row) ? (bool) $row['is_active'] : true,
                    ]
                );
            }
        }
    }

    public function formatMoney(float $amount, CurrencyCode|string $currencyCode): string
    {
        $currency = CurrencyCode::tryFromMixed($currencyCode) ?? CurrencyCode::default();

        return Cashier::formatAmount((int) round($amount * 100), $currency->value);
    }

    private function currencyOptionsForPlan(string $planCode): array
    {
        $prices = PlanPrice::query()
            ->select('plan_prices.*')
            ->join('plans', 'plans.id', '=', 'plan_prices.plan_id')
            ->where('plans.code', $planCode)
            ->where('plan_prices.billing_period', BillingPeriod::MONTHLY->value)
            ->get()
            ->keyBy(fn (PlanPrice $price) => (string) $price->currency_code->value);

        return collect(CurrencyCode::cases())
            ->mapWithKeys(function (CurrencyCode $currency) use ($prices) {
                /** @var PlanPrice|null $price */
                $price = $prices->get($currency->value);

                return [
                    $currency->value => [
                        'currency_code' => $currency->value,
                        'amount' => $price?->amount !== null ? number_format((float) $price->amount, 2, '.', '') : null,
                        'stripe_price_id' => $price?->stripe_price_id,
                        'display_price' => $price?->amount !== null
                            ? $this->formatMoney((float) $price->amount, $currency)
                            : null,
                        'is_active' => $price ? (bool) $price->is_active : false,
                    ],
                ];
            })
            ->all();
    }

    private function configuredPlans(): array
    {
        return config('billing.plans', []);
    }

    public function configuredPlan(string $planCode): array
    {
        return $this->configuredPlans()[$planCode] ?? [];
    }

    public function isOwnerOnlyPlan(string $planCode): bool
    {
        return (bool) ($this->configuredPlan($planCode)['owner_only'] ?? false);
    }

    public function onboardingPlanKeys(array $preferred = []): array
    {
        $plans = $this->configuredPlans();
        $order = $preferred !== [] ? $preferred : array_keys($plans);

        return array_values(array_filter($order, function (string $planCode) use ($plans): bool {
            if (! array_key_exists($planCode, $plans)) {
                return false;
            }

            return (bool) ($plans[$planCode]['onboarding_enabled'] ?? false);
        }));
    }

    private function configuredPlanCodes(): array
    {
        return array_keys($this->configuredPlans());
    }

    private function metadataForConfiguredPlan(array $configuredPlan): array
    {
        $audience = (string) ($configuredPlan['audience'] ?? 'team');

        return [
            'audience' => in_array($audience, ['solo', 'team'], true) ? $audience : 'team',
            'owner_only' => (bool) ($configuredPlan['owner_only'] ?? false),
            'recommended' => (bool) ($configuredPlan['recommended'] ?? false),
            'onboarding_enabled' => (bool) ($configuredPlan['onboarding_enabled'] ?? false),
        ];
    }

    private function resolveLegacyDisplayPrice(mixed $raw): ?string
    {
        $rawValue = is_string($raw) ? trim($raw) : $raw;
        if (is_numeric($rawValue)) {
            return Cashier::formatAmount((int) round((float) $rawValue * 100), CurrencyCode::default()->value);
        }

        if (is_string($rawValue) && $rawValue !== '') {
            return $rawValue;
        }

        return null;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
