<?php

use App\Services\Demo\Scenarios\StudioNaya\StudioNayaBlueprint;

test('demo scenario volumes keep studio naya medium defaults and requested counts', function () {
    $configuration = require dirname(__DIR__, 2).'/config/demo_scenarios.php';
    $volumes = $configuration['volumes'];
    $scenario = $configuration['scenarios'][StudioNayaBlueprint::KEY];

    expect($scenario['blueprint'])->toBe(StudioNayaBlueprint::class)
        ->and($scenario['default_volume'])->toBe('medium')
        ->and($scenario['available_volumes'])->toBe(['small', 'medium', 'large'])
        ->and($scenario['required_modules'])->toContain(
            'services',
            'reservations',
            'invoices',
            'products',
            'sales',
            'accounting',
        )
        ->and($scenario['history_months'])->toBe(18)
        ->and($volumes['medium']['customers'])->toBe(300)
        ->and($volumes['medium']['reservations'])->toBe(1800)
        ->and($volumes['medium']['invoices'])->toBe(1100);
});

test('small and large demo volumes bracket the medium operating volume', function () {
    $configuration = require dirname(__DIR__, 2).'/config/demo_scenarios.php';
    $volumes = $configuration['volumes'];

    foreach (['customers', 'reservations', 'invoices', 'payments', 'quotes', 'sales'] as $metric) {
        expect($volumes['small'][$metric])->toBeLessThan($volumes['medium'][$metric])
            ->and($volumes['large'][$metric])->toBeGreaterThan($volumes['medium'][$metric]);
    }

    expect($volumes['small']['employees'])->toBe(5)
        ->and($volumes['medium']['services'])->toBe(28)
        ->and($volumes['large']['products'])->toBe(18);
});
