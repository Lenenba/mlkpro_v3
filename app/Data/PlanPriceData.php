<?php

namespace App\Data;

use App\Enums\BillingPeriod;
use App\Enums\CurrencyCode;
use App\Models\PlanPrice;

final readonly class PlanPriceData
{
    public function __construct(
        public int $planId,
        public int $planPriceId,
        public string $planCode,
        public string $planName,
        public CurrencyCode $currencyCode,
        public BillingPeriod $billingPeriod,
        public string $amount,
        public ?string $stripePriceId,
        public bool $isActive,
    ) {}

    public static function fromModel(PlanPrice $planPrice): self
    {
        $planPrice->loadMissing('plan');

        return new self(
            $planPrice->plan_id,
            $planPrice->id,
            (string) ($planPrice->plan?->code ?? ''),
            (string) ($planPrice->plan?->name ?? ''),
            $planPrice->currency_code instanceof CurrencyCode
                ? $planPrice->currency_code
                : (CurrencyCode::tryFromMixed($planPrice->currency_code) ?? CurrencyCode::default()),
            $planPrice->billing_period instanceof BillingPeriod
                ? $planPrice->billing_period
                : (BillingPeriod::tryFromMixed($planPrice->billing_period) ?? BillingPeriod::default()),
            number_format((float) $planPrice->amount, 2, '.', ''),
            $planPrice->stripe_price_id,
            (bool) $planPrice->is_active,
        );
    }

    public function money(): MoneyData
    {
        return MoneyData::fromNumeric($this->amount, $this->currencyCode);
    }
}
