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
