<?php

use App\Services\Demo\DemoWorkspaceCatalog;
use App\Services\Demo\Scenarios\BorealProprete\BorealPropreteBlueprint;
use Illuminate\Support\Arr;
use Tests\TestCase;

uses(TestCase::class);

test('catalog exposes boreal as an immersive cleaning preset with a dedicated journey', function () {
    /** @var DemoWorkspaceCatalog $catalog */
    $catalog = app(DemoWorkspaceCatalog::class);
    $preset = collect($catalog->presets())->firstWhere('key', BorealPropreteBlueprint::KEY);
    $journey = collect($catalog->scenarioPacks())->firstWhere('key', 'boreal_proprete_complete');
    $sector = collect($catalog->sectors())->firstWhere('value', 'nettoyage');

    expect($sector)->toBe([
        'value' => 'nettoyage',
        'label' => 'Nettoyage professionnel',
    ])->and($preset)->toBeArray()
        ->and($preset)->toMatchArray([
            'company_type' => 'services',
            'company_sector' => 'nettoyage',
            'company_name' => 'Boréal Propreté Services',
            'prospect_name' => 'Amélie Gagnon',
            'seed_profile' => 'immersive',
            'scenario_key' => BorealPropreteBlueprint::KEY,
            'data_volume' => 'medium',
            'scenario_version' => 1,
            'team_size' => 7,
            'locale' => 'fr',
            'timezone' => 'America/Toronto',
            'scenario_packs' => ['boreal_proprete_complete'],
            'extra_access_roles' => ['manager', 'staff'],
        ])->and($preset['modules'])->toBe([
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
        ])->and(array_values(array_diff($preset['modules'], $catalog->moduleKeys())))->toBe([])
        ->and($preset['branding_profile'])->toMatchArray([
            'name' => 'Boréal Propreté Services',
            'logo_url' => '/images/presets/company-3.svg',
            'tagline' => 'Des espaces impeccables, des passages prouvés.',
            'contact_email' => 'bonjour@boreal-proprete.example',
            'phone' => '+1 438 555 0196',
            'city' => 'Longueuil',
            'province' => 'QC',
            'primary_color' => '#0F766E',
            'secondary_color' => '#164E63',
            'accent_color' => '#38BDF8',
            'surface_color' => '#F0FDFA',
        ])->and($journey)->toBeArray()
        ->and($journey['sectors'])->toBe(['nettoyage'])
        ->and($journey['required_modules'])->toBe($preset['modules'])
        ->and($journey['ordered_actions'])->toHaveCount(6)
        ->and($preset['suggested_flow'])->toContain(
            'Groupe Lavoie Immeubles',
            'Construction Horizon',
            'checklists',
            'factures',
        )->and($catalog->defaultScenarioPacks('services', 'nettoyage', $preset['modules']))
        ->toContain('boreal_proprete_complete', 'service_quote_to_invoice');
});

test('scenario definitions expose only verified boreal volume preview metrics', function () {
    /** @var DemoWorkspaceCatalog $catalog */
    $catalog = app(DemoWorkspaceCatalog::class);
    $definition = $catalog->scenarioDefinition(BorealPropreteBlueprint::KEY);
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

    expect($definition)->toBeArray()
        ->and($definition)->toMatchArray([
            'key' => BorealPropreteBlueprint::KEY,
            'label' => 'Boréal Propreté Services',
            'version' => 1,
            'company_type' => 'services',
            'company_sector' => 'nettoyage',
            'seed_profile' => 'immersive',
            'default_volume' => 'medium',
            'available_volumes' => ['small', 'medium', 'large'],
            'preview_metrics' => $previewMetrics,
            'history_months' => 12,
            'future_weeks' => 4,
            'reference_timezone' => 'America/Toronto',
        ])->and($definition['data_volumes'])->toHaveCount(3)
        ->and(array_column($definition['data_volumes'], 'value'))->toBe(['small', 'medium', 'large']);

    foreach ($definition['data_volumes'] as $volume) {
        $expected = Arr::only(
            BorealPropreteBlueprint::targetsForVolume($volume['value']),
            $previewMetrics,
        );

        expect($volume['counts'])->toBe($expected)
            ->and(array_keys($volume['counts']))->toBe($previewMetrics)
            ->and($volume['counts'])->not->toHaveKeys([
                'offer_packages',
                'campaigns',
                'campaign_runs',
                'campaign_recipients',
                'campaign_events',
                'activity_logs',
                'team_attendances',
            ]);
    }
});
