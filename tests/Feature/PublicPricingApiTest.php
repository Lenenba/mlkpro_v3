<?php

use App\Models\SubscriptionPromotion;

test('public pricing api exposes the default audience without comparison sections by default', function () {
    $this->getJson('/api/v1/public/pricing')
        ->assertOk()
        ->assertJsonPath('currency_code', 'CAD')
        ->assertJsonPath('default_audience', 'solo')
        ->assertJsonPath('audience', 'solo')
        ->assertJsonPath('available_audiences.0', 'solo')
        ->assertJsonPath('available_audiences.1', 'team')
        ->assertJsonPath('highlighted_plan_key', 'solo_pro')
        ->assertJsonPath('plans.0.key', 'solo_essential')
        ->assertJsonPath('plans.0.name', 'Solo Core')
        ->assertJsonPath('plans.1.key', 'solo_pro')
        ->assertJsonPath('plans.1.name', 'Solo Growth')
        ->assertJsonPath('plans.1.audience', 'solo')
        ->assertJsonPath('plans.1.onboarding_enabled', true)
        ->assertJsonPath('plans.1.prices_by_period.monthly.billing_period', 'monthly')
        ->assertJsonPath('plans.1.prices_by_period.yearly.billing_period', 'yearly')
        ->assertJsonPath('plans.1.annual_discount_percent', 0)
        ->assertJsonCount(3, 'plans')
        ->assertJsonCount(0, 'comparison_sections');
});

test('public pricing api can return comparison sections and promotion aware pricing for a specific audience and currency', function () {
    SubscriptionPromotion::query()->updateOrCreate(
        ['key' => SubscriptionPromotion::GLOBAL_KEY],
        [
            'name' => 'Global subscription promotion',
            'is_enabled' => true,
            'monthly_discount_percent' => 25,
            'yearly_discount_percent' => 35,
            'monthly_stripe_coupon_id' => 'coupon_promo_25',
            'yearly_stripe_coupon_id' => 'coupon_promo_35',
        ]
    );

    $this->getJson('/api/v1/public/pricing?audience=team&currency=USD&include=comparison_sections')
        ->assertOk()
        ->assertJsonPath('currency_code', 'USD')
        ->assertJsonPath('default_audience', 'team')
        ->assertJsonPath('audience', 'team')
        ->assertJsonPath('highlighted_plan_key', 'growth')
        ->assertJsonPath('plans.0.key', 'starter')
        ->assertJsonPath('plans.0.name', 'Team Core')
        ->assertJsonPath('plans.0.prices_by_period.monthly.currency_code', 'USD')
        ->assertJsonPath('plans.0.prices_by_period.monthly.is_discounted', true)
        ->assertJsonPath('plans.0.prices_by_period.monthly.promotion.discount_percent', 25)
        ->assertJsonPath('plans.0.prices_by_period.yearly.is_discounted', true)
        ->assertJsonPath('plans.0.prices_by_period.yearly.promotion.discount_percent', 35)
        ->assertJsonPath('plans.0.promotion.discount_percent', 25)
        ->assertJsonCount(4, 'plans')
        ->assertJsonCount(3, 'comparison_sections')
        ->assertJsonPath('comparison_sections.0.rows.0.values.0.text', '5')
        ->assertJsonPath('comparison_sections.2.rows.2.values.0.text', '25')
        ->assertJsonPath('comparison_sections.2.rows.3.values.1.text', '2500/mo');
});

test('public pricing api validates unknown currencies and audiences', function () {
    $this->getJson('/api/v1/public/pricing?currency=GBP')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['currency']);

    $this->getJson('/api/v1/public/pricing?audience=agency')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['audience']);
});
