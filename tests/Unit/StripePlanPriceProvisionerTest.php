<?php

use App\Services\StripePlanPriceProvisioner;

it('expands the solo shortcut into the 3 owner-only solo plans', function () {
    $resolved = app(StripePlanPriceProvisioner::class)->resolveSelectedPlanCodes(['solo'], false);

    expect($resolved)->toBe([
        'solo_essential',
        'solo_pro',
        'solo_growth',
    ]);
});

it('merges the solo flag with explicit plans without duplicates', function () {
    $resolved = app(StripePlanPriceProvisioner::class)->resolveSelectedPlanCodes([
        'starter',
        'solo_pro',
        'solo',
    ], true);

    expect($resolved)->toBe([
        'solo_essential',
        'solo_pro',
        'solo_growth',
        'starter',
    ]);
});

it('writes both CAD-specific and legacy env keys for base CAD prices', function () {
    $service = app(StripePlanPriceProvisioner::class);
    $resolved = [];

    $invoke = \Closure::bind(
        function (array &$resolved): void {
            $this->addResolvedEnvValues($resolved, 'starter', 'CAD', 'price_cad_starter', 29.00);
        },
        $service,
        StripePlanPriceProvisioner::class
    );

    $invoke($resolved);

    expect($resolved)->toBe([
        'STRIPE_PRICE_STARTER_CAD' => 'price_cad_starter',
        'STRIPE_PRICE_STARTER_CAD_AMOUNT' => '29.00',
        'STRIPE_PRICE_STARTER' => 'price_cad_starter',
        'STRIPE_PRICE_STARTER_AMOUNT' => '29.00',
    ]);
});

it('writes currency-specific env keys without touching legacy keys for non-CAD prices', function () {
    $service = app(StripePlanPriceProvisioner::class);
    $resolved = [];

    $invoke = \Closure::bind(
        function (array &$resolved): void {
            $this->addResolvedEnvValues($resolved, 'starter', 'USD', 'price_usd_starter', 24.00);
        },
        $service,
        StripePlanPriceProvisioner::class
    );

    $invoke($resolved);

    expect($resolved)->toBe([
        'STRIPE_PRICE_STARTER_USD' => 'price_usd_starter',
        'STRIPE_PRICE_STARTER_USD_AMOUNT' => '24.00',
    ]);
});
