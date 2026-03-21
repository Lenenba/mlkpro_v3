<?php

use Inertia\Testing\AssertableInertia as Assert;

test('pricing page exposes all public plans and comparison sections', function () {
    $this->get(route('pricing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pricing')
            ->where('megaMenu.display_location', 'header')
            ->where('footerMenu.display_location', 'footer')
            ->where('footerSection.layout', 'footer')
            ->has('pricingPlans', 5)
            ->where('pricingPlans.0.key', 'free')
            ->where('pricingPlans.1.key', 'starter')
            ->where('pricingPlans.2.key', 'growth')
            ->where('pricingPlans.3.key', 'scale')
            ->where('pricingPlans.4.key', 'enterprise')
            ->where('highlightedPlanKey', 'growth')
            ->has('comparisonSections', 3)
            ->where('comparisonSections.0.rows.0.values.0.plan_key', 'free')
            ->where('comparisonSections.0.rows.0.values.4.plan_key', 'enterprise')
        );
});
