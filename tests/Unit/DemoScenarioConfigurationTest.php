<?php

use App\Services\Demo\Scenarios\BorealProprete\BorealPropreteBlueprint;
use App\Services\Demo\Scenarios\BorealProprete\BorealPropreteScenario;
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
        ->and($volumes['medium']['invoices'])->toBe(1100)
        ->and($volumes['medium']['offer_packages'])->toBe(7)
        ->and($volumes['medium']['offer_package_items'])->toBe(20)
        ->and($volumes['medium']['pack_invoice_lines'])->toBe(18)
        ->and($volumes['medium']['customer_packages'])->toBe(36)
        ->and($volumes['medium']['customer_package_usages'])->toBe(118);
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
        ->and($volumes['large']['products'])->toBe(18)
        ->and($volumes['small']['offer_packages'])->toBe($volumes['large']['offer_packages'])
        ->and($volumes['small']['offer_package_items'])->toBe($volumes['large']['offer_package_items']);

    foreach (['pack_invoice_lines', 'customer_packages', 'customer_package_usages', 'package_behavior_events'] as $metric) {
        expect($volumes['small'][$metric])->toBeLessThan($volumes['medium'][$metric])
            ->and($volumes['medium'][$metric])->toBeLessThan($volumes['large'][$metric]);
    }
});

test('immersive target fallbacks stay aligned with every configured volume', function () {
    $configuration = require dirname(__DIR__, 2).'/config/demo_scenarios.php';

    foreach (['small', 'medium', 'large'] as $volume) {
        expect(collect($configuration['volumes'][$volume])
            ->only(StudioNayaBlueprint::IMMERSIVE_TARGET_KEYS)
            ->all())
            ->toBe(StudioNayaBlueprint::immersiveTargetsForVolume($volume));
    }
});

test('studio naya offers reference real catalog entries and expose complete sellable packages', function () {
    $blueprint = StudioNayaBlueprint::definition();
    $serviceKeys = collect($blueprint['services'])->pluck('key');
    $productKeys = collect($blueprint['products'])->pluck('key');
    $catalogPrices = collect($blueprint['services'])
        ->concat($blueprint['products'])
        ->mapWithKeys(fn (array $item): array => [$item['key'] => (float) $item['price']]);
    $offers = collect($blueprint['offer_packages']);
    $items = $offers->flatMap(fn (array $offer): array => $offer['items']);
    $packEconomicsAreCoherent = $offers
        ->where('type', 'pack')
        ->every(function (array $offer) use ($catalogPrices): bool {
            $retailValue = round((float) collect($offer['items'])->sum(
                fn (array $item): float => (float) $catalogPrices->get($item['key']) * (float) $item['quantity'],
            ), 2);

            return abs(
                $retailValue - (float) $offer['price'] - (float) data_get($offer, 'metadata.savings_amount'),
            ) <= 0.009;
        });

    expect($offers)->toHaveCount(7)
        ->and($offers->where('type', 'pack'))->toHaveCount(3)
        ->and($offers->where('type', 'forfait'))->toHaveCount(4)
        ->and($offers->pluck('key')->unique())->toHaveCount(7)
        ->and($offers->every(fn (array $offer): bool => $offer['status'] === 'active'
            && $offer['is_public'] === true
            && $offer['price'] > 0
            && count($offer['items']) > 0))
        ->toBeTrue()
        ->and($items)->toHaveCount(20)
        ->and($items->every(fn (array $item): bool => $item['catalog'] === 'service'
            ? $serviceKeys->contains($item['key'])
            : $productKeys->contains($item['key'])))
        ->toBeTrue()
        ->and($packEconomicsAreCoherent)->toBeTrue();
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

test('demo scenario configuration registers the boreal cleaning narrative independently', function () {
    $configuration = require dirname(__DIR__, 2).'/config/demo_scenarios.php';
    $scenario = $configuration['scenarios'][BorealPropreteBlueprint::KEY];
    $previewMetrics = [
        'employees',
        'services',
        'products',
        'customers',
        'properties',
        'prospects',
        'service_requests',
        'quotes',
        'works',
        'tasks',
        'work_checklist_items',
        'work_media',
        'invoices',
        'payments',
        'expenses',
        'inventory_movements',
    ];

    expect($scenario)
        ->toMatchArray([
            'blueprint' => BorealPropreteBlueprint::class,
            'generator' => BorealPropreteScenario::class,
            'company_type' => 'services',
            'company_sector' => 'nettoyage',
            'seed_profile' => 'immersive',
            'default_volume' => 'medium',
            'available_volumes' => ['small', 'medium', 'large'],
            'history_months' => 12,
            'future_weeks' => 4,
            'reference_timezone' => 'America/Toronto',
            'preview_metrics' => $previewMetrics,
        ])
        ->and($scenario['required_modules'])->toBe([
            'requests',
            'quotes',
            'services',
            'jobs',
            'tasks',
            'planning',
            'presence',
            'invoices',
            'expenses',
            'accounting',
            'team_members',
            'performance',
            'products',
        ])
        ->and($scenario['preview_metrics'])
        ->not->toContain(
            'offer_packages',
            'campaigns',
            'campaign_runs',
            'campaign_recipients',
            'campaign_events',
            'activity_logs',
            'team_attendances',
        );
});
