<?php

use App\Services\StripePlanPriceProvisioner;

it('excludes solo plans from the default catalog unless explicitly requested', function () {
    $service = app(StripePlanPriceProvisioner::class);

    $invoke = \Closure::bind(
        function (): array {
            return array_keys($this->resolveCatalog([], false, []));
        },
        $service,
        StripePlanPriceProvisioner::class
    );

    expect($invoke())->toBe([
        'starter',
        'growth',
        'scale',
    ]);
});
