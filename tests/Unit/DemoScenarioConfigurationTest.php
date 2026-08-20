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

test('engagement targets scale while preserving complete lifecycle contracts', function () {
    $configuration = require dirname(__DIR__, 2).'/config/demo_scenarios.php';
    $volumes = $configuration['volumes'];
    $requiredModules = $configuration['scenarios'][StudioNayaBlueprint::KEY]['required_modules'];
    $scalingMetrics = [
        'mailing_lists',
        'campaigns',
        'campaign_recipients',
        'campaign_events',
        'promotions',
        'promotion_usages',
        'assistant_knowledge_items',
        'assistant_conversations',
        'assistant_messages',
        'social_templates',
        'social_posts',
    ];

    expect($requiredModules)->toContain('campaigns', 'promotions', 'assistant', 'social')
        ->and($volumes['small']['campaigns'])->toBe(3)
        ->and($volumes['medium']['campaigns'])->toBe(6)
        ->and($volumes['large']['campaigns'])->toBe(9)
        ->and($volumes['small']['campaign_events'])->toBe(54)
        ->and($volumes['medium']['campaign_events'])->toBe(270)
        ->and($volumes['large']['campaign_events'])->toBe(810);

    foreach ($scalingMetrics as $metric) {
        expect($volumes['small'][$metric])->toBeLessThan($volumes['medium'][$metric])
            ->and($volumes['medium'][$metric])->toBeLessThan($volumes['large'][$metric]);
    }

    foreach (['small', 'medium', 'large'] as $volume) {
        expect($volumes[$volume]['assistant_settings'])->toBe(1)
            ->and($volumes[$volume]['social_accounts'])->toBe(1)
            ->and($volumes[$volume]['assistant_messages'])
            ->toBe($volumes[$volume]['assistant_conversations'] * 3)
            ->and($volumes[$volume]['campaign_messages'])
            ->toBe($volumes[$volume]['campaign_recipients'])
            ->and($volumes[$volume]['social_targets'])
            ->toBe($volumes[$volume]['social_posts']);
    }
});
