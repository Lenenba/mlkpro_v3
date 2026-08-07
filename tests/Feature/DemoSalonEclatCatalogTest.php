<?php

use App\Services\CompanyFeatureService;
use App\Services\Demo\DemoWorkspaceCatalog;

it('exposes an immersive Salon Eclat preset without changing the lean salon preset', function () {
    /** @var DemoWorkspaceCatalog $catalog */
    $catalog = app(DemoWorkspaceCatalog::class);
    $presets = collect($catalog->presets());
    $preset = $presets->firstWhere('key', 'salon_eclat_complete');
    $leanPreset = $presets->firstWhere('key', 'salon_queue');
    $expectedModules = [
        'services',
        'reservations',
        'planning',
        'presence',
        'invoices',
        'expenses',
        'accounting',
        'team_members',
        'performance',
        'products',
        'sales',
        'promotions',
        'loyalty',
        'campaigns',
        'assistant',
        'social',
    ];
    $fieldServiceModules = ['requests', 'quotes', 'plan_scans', 'jobs', 'tasks'];
    $leanModules = $catalog->defaultModules('services', 'salon');

    expect($preset)->toBeArray()
        ->and($preset['company_name'] ?? null)->toBe('Salon Éclat')
        ->and($preset['prospect_name'] ?? null)->toBe('Amina Diallo')
        ->and($preset['prospect_email'] ?? null)->toBe('amina.diallo@example.test')
        ->and($preset['seed_profile'] ?? null)->toBe('immersive')
        ->and($preset['team_size'] ?? null)->toBe(3)
        ->and($preset['locale'] ?? null)->toBe('fr')
        ->and($preset['timezone'] ?? null)->toBe('America/Toronto')
        ->and($preset['modules'] ?? null)->toBe($expectedModules)
        ->and(array_values(array_intersect($preset['modules'] ?? [], $fieldServiceModules)))->toBe([])
        ->and($leanPreset)->toBeArray()
        ->and($leanPreset['modules'] ?? null)->toBe($leanModules)
        ->and($leanModules)->not->toContain('products', 'sales', 'promotions', 'loyalty', 'campaigns', 'assistant', 'social');
});

it('uses only canonical feature keys for every immersive salon module', function () {
    /** @var DemoWorkspaceCatalog $catalog */
    $catalog = app(DemoWorkspaceCatalog::class);
    $preset = collect($catalog->presets())->firstWhere('key', 'salon_eclat_complete');
    $catalogModuleKeys = $catalog->moduleKeys();
    $planModuleKeys = collect(CompanyFeatureService::defaultPlanModules())
        ->flatMap(fn (array $modules): array => array_keys($modules))
        ->unique()
        ->values()
        ->all();

    expect($catalogModuleKeys)->toContain('social')
        ->and($planModuleKeys)->toContain('social')
        ->and(array_values(array_diff($preset['modules'] ?? [], $catalogModuleKeys)))->toBe([])
        ->and(array_values(array_diff($preset['modules'] ?? [], $planModuleKeys)))->toBe([]);
});

it('declares a compatible complete Salon Eclat scenario without adding it to the lean flow', function () {
    /** @var DemoWorkspaceCatalog $catalog */
    $catalog = app(DemoWorkspaceCatalog::class);
    $preset = collect($catalog->presets())->firstWhere('key', 'salon_eclat_complete');
    $scenario = collect($catalog->scenarioPacks())->firstWhere('key', 'salon_eclat_complete');
    $leanModules = $catalog->defaultModules('services', 'salon');
    $completeModules = $preset['modules'] ?? [];

    expect($scenario)->toBeArray()
        ->and($preset['scenario_packs'] ?? [])->toBe([
            'salon_queue',
            'reservation_to_service',
            'salon_eclat_complete',
        ])
        ->and(array_values(array_diff($scenario['required_modules'] ?? [], $completeModules)))->toBe([])
        ->and($catalog->defaultScenarioPacks('services', 'salon', $completeModules))
        ->toContain('salon_queue', 'reservation_to_service', 'salon_eclat_complete')
        ->and($catalog->defaultScenarioPacks('services', 'salon', $leanModules))
        ->toContain('salon_queue', 'reservation_to_service')
        ->not->toContain('salon_eclat_complete')
        ->and($preset['suggested_flow'] ?? '')
        ->toContain('taxes', 'tip', 'invoice', 'loyalty');
});
