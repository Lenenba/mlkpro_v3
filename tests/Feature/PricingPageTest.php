<?php

use App\Models\SubscriptionPromotion;
use Inertia\Testing\AssertableInertia as Assert;

test('pricing page exposes all public plans and comparison sections', function () {
    $this->get(route('pricing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pricing')
            ->where('megaMenu.display_location', 'header')
            ->where('footerMenu.display_location', 'footer')
            ->where('footerSection.layout', 'footer')
            ->where('supportedCurrencies', ['CAD', 'EUR', 'USD'])
            ->where('selectedCurrencyCode', 'CAD')
            ->where('defaultAudience', 'solo')
            ->has('pricingCatalogs.solo.plans', 3)
            ->where('pricingCatalogs.solo.plans.0.key', 'solo_essential')
            ->where('pricingCatalogs.solo.plans.0.name', 'Solo Core')
            ->where('pricingCatalogs.solo.plans.0.features', [
                'Demandes, devis, factures, jobs et taches',
                'Catalogue, ventes et operations du quotidien',
                'Portail client et page publique',
                'Execution solo simple sans modules avances',
            ])
            ->where('pricingCatalogs.solo.plans.1.key', 'solo_pro')
            ->where('pricingCatalogs.solo.plans.1.name', 'Solo Growth')
            ->where('pricingCatalogs.solo.plans.1.features', [
                'Tout Solo Core',
                'Plus de volume pour jobs et taches',
                'Catalogue et ventes sans logique equipe',
                'Plan solo recommande',
            ])
            ->where('pricingCatalogs.solo.plans.2.key', 'solo_growth')
            ->where('pricingCatalogs.solo.plans.2.name', 'Solo Scale')
            ->where('pricingCatalogs.solo.plans.2.features', [
                'Tout Solo Growth',
                'Reservations et planning en mode solo limite',
                'Assistant, scan de plan, campagnes et fidelite',
                'Automatisation et capacite premium pour scaler seul',
                'Support prioritaire',
            ])
            ->where('pricingCatalogs.solo.plans.1.audience', 'solo')
            ->where('pricingCatalogs.solo.plans.1.onboarding_enabled', true)
            ->where('pricingCatalogs.solo.plans.1.prices_by_period.monthly.billing_period', 'monthly')
            ->where('pricingCatalogs.solo.plans.1.prices_by_period.yearly.billing_period', 'yearly')
            ->where('pricingCatalogs.solo.plans.1.annual_discount_percent', 0)
            ->where('pricingCatalogs.solo.highlightedPlanKey', 'solo_pro')
            ->has('pricingCatalogs.solo.comparisonSections', 3)
            ->has('pricingCatalogs.solo.comparisonSections.0.rows', 6)
            ->where('pricingCatalogs.solo.comparisonSections.0.rows.0.values.0.plan_key', 'solo_essential')
            ->where('pricingCatalogs.solo.comparisonSections.0.rows.0.values.2.plan_key', 'solo_growth')
            ->where('pricingCatalogs.solo.comparisonSections.0.rows.1.values.0.text', 'Unlimited')
            ->where('pricingCatalogs.solo.comparisonSections.0.rows.2.values.1.text', 'Unlimited')
            ->where('pricingCatalogs.solo.comparisonSections.0.rows.3.values.2.text', 'Unlimited')
            ->where('pricingCatalogs.solo.comparisonSections.0.rows.4.values.0.type', 'included')
            ->where('pricingCatalogs.solo.comparisonSections.0.rows.4.values.1.type', 'included')
            ->where('pricingCatalogs.solo.comparisonSections.0.rows.4.values.2.type', 'included')
            ->where('pricingCatalogs.solo.comparisonSections.0.rows.5.values.0.text', 'Unlimited')
            ->where('pricingCatalogs.solo.comparisonSections.1.rows.0.values.0.text', '300')
            ->where('pricingCatalogs.solo.comparisonSections.1.rows.2.values.1.type', 'excluded')
            ->where('pricingCatalogs.solo.comparisonSections.1.rows.3.values.2.text', 'Limited mode')
            ->where('pricingCatalogs.solo.comparisonSections.2.rows.0.values.1.type', 'excluded')
            ->where('pricingCatalogs.solo.comparisonSections.2.rows.0.values.2.text', '3000/mo')
            ->where('pricingCatalogs.solo.comparisonSections.2.rows.3.values.1.type', 'excluded')
            ->where('pricingCatalogs.solo.comparisonSections.2.rows.3.values.2.text', '500')
            ->has('pricingCatalogs.team.plans', 4)
            ->where('pricingCatalogs.team.plans.0.key', 'starter')
            ->where('pricingCatalogs.team.plans.0.name', 'Team Core')
            ->where('pricingCatalogs.team.plans.1.key', 'growth')
            ->where('pricingCatalogs.team.plans.1.name', 'Team Growth')
            ->where('pricingCatalogs.team.plans.2.key', 'scale')
            ->where('pricingCatalogs.team.plans.2.name', 'Team Scale')
            ->where('pricingCatalogs.team.plans.3.key', 'enterprise')
            ->where('pricingCatalogs.team.plans.0.onboarding_enabled', true)
            ->where('pricingCatalogs.team.plans.3.contact_only', true)
            ->where('pricingCatalogs.team.plans.0.prices_by_period.yearly.billing_period', 'yearly')
            ->where('pricingCatalogs.team.highlightedPlanKey', 'growth')
            ->has('pricingCatalogs.team.comparisonSections', 3)
            ->has('pricingCatalogs.team.comparisonSections.0.rows', 7)
            ->where('pricingCatalogs.team.comparisonSections.0.rows.0.values.0.plan_key', 'starter')
            ->where('pricingCatalogs.team.comparisonSections.0.rows.0.values.3.plan_key', 'enterprise')
            ->where('pricingCatalogs.team.comparisonSections.0.rows.0.values.0.text', '5')
            ->where('pricingCatalogs.team.comparisonSections.0.rows.1.values.0.text', 'Unlimited')
            ->where('pricingCatalogs.team.comparisonSections.0.rows.4.values.0.type', 'included')
            ->where('pricingCatalogs.team.comparisonSections.0.rows.6.values.3.type', 'included')
            ->where('pricingCatalogs.team.comparisonSections.1.rows.0.values.0.text', '1000')
            ->where('pricingCatalogs.team.comparisonSections.2.rows.2.values.0.text', '25')
            ->has('pricingPlans', 3)
            ->where('pricingPlans.0.key', 'solo_essential')
            ->where('pricingPlans.0.name', 'Solo Core')
            ->where('highlightedPlanKey', 'solo_pro')
            ->has('comparisonSections', 3)
        );
});

test('pricing page can switch public currency and remember the selected pricing catalog', function () {
    $this->get(route('pricing', ['currency' => 'USD', 'audience' => 'team']))
        ->assertOk()
        ->assertSessionHas('public_pricing_currency', 'USD')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pricing')
            ->where('selectedCurrencyCode', 'USD')
            ->where('defaultAudience', 'team')
            ->where('pricingCatalogs.team.plans.0.name', 'Team Core')
            ->where('pricingCatalogs.team.plans.0.prices_by_period.monthly.currency_code', 'USD')
            ->where('pricingPlans.0.prices_by_period.monthly.currency_code', 'USD')
        );

    $this->withSession(['public_pricing_currency' => 'USD'])
        ->get(route('pricing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pricing')
            ->where('selectedCurrencyCode', 'USD')
            ->where('pricingCatalogs.solo.plans.0.prices_by_period.monthly.currency_code', 'USD')
        );
});

test('pricing page exposes discounted subscription pricing when a promotion is active', function () {
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

    $this->get(route('pricing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pricing')
            ->where('pricingCatalogs.team.plans.0.is_discounted', true)
            ->where('pricingCatalogs.team.plans.0.name', 'Team Core')
            ->where('pricingCatalogs.team.plans.0.promotion.discount_percent', 25)
            ->where('pricingCatalogs.team.plans.0.prices_by_period.monthly.is_discounted', true)
            ->where('pricingCatalogs.team.plans.0.prices_by_period.monthly.promotion.discount_percent', 25)
            ->where('pricingCatalogs.team.plans.0.prices_by_period.yearly.is_discounted', true)
            ->where('pricingCatalogs.team.plans.0.prices_by_period.yearly.promotion.discount_percent', 35)
        );
});
