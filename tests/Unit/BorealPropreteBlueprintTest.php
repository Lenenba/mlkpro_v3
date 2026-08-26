<?php

use App\Enums\DemoDataVolume;
use App\Services\Demo\Scenarios\BorealProprete\BorealPropreteBlueprint;

test('boreal proprete exposes a distinct Quebec cleaning identity and team', function () {
    $definition = BorealPropreteBlueprint::definition();
    $identity = $definition['identity'];
    $employees = $definition['employees'];

    expect($definition)
        ->toMatchArray([
            'key' => 'boreal_proprete_services',
            'version' => 1,
            'default_volume' => 'medium',
            'history_months' => 12,
            'future_weeks' => 4,
        ])
        ->and($identity)->toMatchArray([
            'name' => 'Boréal Propreté Services',
            'owner_name' => 'Amélie Gagnon',
            'category_key' => 'cleaning_company',
            'company_sector' => 'nettoyage',
            'business_preset' => 'service_general',
            'primary_locale' => 'fr_CA',
            'currency_code' => 'CAD',
            'timezone' => 'America/Toronto',
            'operating_history_months' => 12,
        ])
        ->and($identity['email'])->toEndWith('.example')
        ->and($identity['operating_model']['primary_record'])->toBe('work')
        ->and($identity['operating_model']['queue_enabled'])->toBeFalse()
        ->and($identity['operating_model']['tips_enabled'])->toBeFalse()
        ->and($employees)->toHaveCount(7)
        ->and(array_column($employees, 'name'))->toBe([
            'Amélie Gagnon',
            'Mariam Diallo',
            'José Alvarez',
            'Fatou Ndiaye',
            'Alexandre Nguyen',
            'Naomi Saint-Pierre',
            'Samuel Roy',
        ]);

    foreach ($employees as $employee) {
        expect($employee['specialties'])->not->toBeEmpty()
            ->and($employee['permissions'])->not->toBeEmpty()
            ->and($employee['schedule'])->not->toBeEmpty()
            ->and($employee['absence_templates'])->not->toBeEmpty()
            ->and($employee['performance_profile'])->toHaveKeys([
                'weekly_capacity_hours',
                'on_time_target',
                'quality_target',
            ]);
    }

    $schedules = array_map(
        static fn (array $employee): string => json_encode($employee['schedule'], JSON_THROW_ON_ERROR),
        $employees,
    );

    expect(array_unique($schedules))->toHaveCount(7);
});

test('boreal proprete volumes preserve exact operating targets and scale monotonically', function () {
    $small = BorealPropreteBlueprint::targetsForVolume('small');
    $medium = BorealPropreteBlueprint::targetsForVolume(DemoDataVolume::Medium);
    $large = BorealPropreteBlueprint::targetsForVolume('large');

    expect($small)->toMatchArray([
        'employees' => 7,
        'services' => 18,
        'products' => 18,
        'customers' => 24,
        'properties' => 30,
        'quotes' => 16,
        'works' => 150,
        'tasks' => 480,
        'invoices' => 80,
        'payments' => 90,
        'expenses' => 60,
        'work_ratings' => 24,
        'campaigns' => 3,
    ])->and($medium)->toMatchArray([
        'employees' => 7,
        'services' => 18,
        'products' => 18,
        'customers' => 90,
        'properties' => 118,
        'quotes' => 65,
        'works' => 720,
        'tasks' => 2400,
        'work_checklist_items' => 3200,
        'work_media' => 420,
        'invoices' => 360,
        'payments' => 420,
        'expenses' => 144,
        'inventory_movements' => 1800,
        'work_ratings' => 140,
        'campaigns' => 6,
    ])->and($large)->toMatchArray([
        'employees' => 7,
        'services' => 18,
        'products' => 18,
        'customers' => 240,
        'properties' => 320,
        'quotes' => 180,
        'works' => 2400,
        'tasks' => 8000,
        'invoices' => 1200,
        'payments' => 1400,
        'expenses' => 360,
        'work_ratings' => 420,
        'campaigns' => 9,
    ]);

    foreach (['customers', 'properties', 'prospects', 'service_requests', 'quotes', 'works', 'tasks', 'invoices', 'payments', 'expenses', 'inventory_movements', 'work_ratings', 'campaigns'] as $key) {
        expect($small[$key])->toBeLessThan($medium[$key])
            ->and($medium[$key])->toBeLessThan($large[$key]);
    }

    foreach (['employees', 'services', 'products', 'offer_packages'] as $key) {
        expect($small[$key])->toBe($medium[$key])
            ->and($medium[$key])->toBe($large[$key]);
    }
});

test('boreal proprete catalogs and material recipes are internally coherent', function () {
    $categories = BorealPropreteBlueprint::serviceCategories();
    $services = BorealPropreteBlueprint::services();
    $suppliers = BorealPropreteBlueprint::suppliers();
    $products = BorealPropreteBlueprint::products();
    $categoryKeys = array_column($categories, 'key');
    $serviceKeys = array_column($services, 'key');
    $supplierKeys = array_column($suppliers, 'key');
    $productKeys = array_column($products, 'key');

    expect($services)->toHaveCount(18)
        ->and(array_unique($serviceKeys))->toHaveCount(18)
        ->and($products)->toHaveCount(18)
        ->and(array_unique($productKeys))->toHaveCount(18)
        ->and(array_filter($products, static fn (array $product): bool => $product['metadata']['stock_state'] === 'low'))->toHaveCount(2);

    foreach ($services as $service) {
        expect($service['category_key'])->toBeIn($categoryKeys)
            ->and($service['duration_minutes'])->toBeGreaterThan(0)
            ->and($service['crew_size'])->toBeGreaterThan(0)
            ->and($service['price'])->toBeGreaterThanOrEqual(0)
            ->and($service['tags'])->not->toBeEmpty()
            ->and($service['calendar_color'])->toMatch('/^#[0-9A-F]{6}$/')
            ->and($service['metadata'])->toHaveKeys([
                'demand_profile',
                'seasonal',
                'bundle',
                'requires_site_assessment',
                'quality_recovery',
                'price_history',
            ]);

        foreach ($service['materials'] as $material) {
            expect($material['product_key'])->toBeIn($productKeys)
                ->and($material['quantity'])->toBeGreaterThan(0);
        }
    }

    foreach ($products as $product) {
        expect($product['supplier_key'])->toBeIn($supplierKeys)
            ->and($product['cost'])->toBeGreaterThan(0)
            ->and($product['price'])->toBeGreaterThan($product['cost'])
            ->and($product['stock_on_hand'])->toBeGreaterThanOrEqual(0)
            ->and($product['reorder_threshold'])->toBeGreaterThanOrEqual(0)
            ->and($product['retail'])->toBeFalse()
            ->and($product['metadata']['stock_state'])->toBeIn(['healthy', 'low', 'out_of_stock']);
    }
});

test('boreal proprete assignments and offers reference known services', function () {
    $employeeKeys = array_column(BorealPropreteBlueprint::employees(), 'key');
    $serviceKeys = array_column(BorealPropreteBlueprint::services(), 'key');
    $matrix = BorealPropreteBlueprint::employeeServiceMatrix();
    $coveredLeadServices = [];

    expect(array_keys($matrix))->toBe($employeeKeys);

    foreach ($matrix as $employeeKey => $assignment) {
        expect($employeeKey)->toBeIn($employeeKeys)
            ->and(array_intersect($assignment['lead_service_keys'], $assignment['support_service_keys']))->toBe([]);

        foreach (array_merge($assignment['lead_service_keys'], $assignment['support_service_keys']) as $serviceKey) {
            expect($serviceKey)->toBeIn($serviceKeys);
        }

        $coveredLeadServices = [...$coveredLeadServices, ...$assignment['lead_service_keys']];
    }

    expect(array_values(array_diff($serviceKeys, array_unique($coveredLeadServices))))->toBe([])
        ->and(BorealPropreteBlueprint::offerPackages())->toHaveCount(6);

    foreach (BorealPropreteBlueprint::offerPackages() as $offer) {
        expect($offer['status'])->toBe('active')
            ->and($offer['price'])->toBeGreaterThan(0)
            ->and($offer['items'])->not->toBeEmpty();

        foreach ($offer['items'] as $item) {
            expect($item['service_key'])->toBeIn($serviceKeys)
                ->and($item['quantity'])->toBeGreaterThan(0);
        }
    }
});

test('boreal proprete exposes seven connected stories and an annual operating rhythm', function () {
    $stories = BorealPropreteBlueprint::clientStories();
    $seasonality = BorealPropreteBlueprint::seasonality();
    $qualityProtocols = BorealPropreteBlueprint::qualityProtocols();
    $paymentMethods = BorealPropreteBlueprint::paymentMethods();
    $clientTypes = array_unique(array_column(array_column($stories, 'profile'), 'client_type'));

    expect($stories)->toHaveCount(7)
        ->and(array_column($stories, 'name'))->toBe([
            'Groupe Lavoie Immeubles',
            'Clinique du Parc',
            'Camille Fortin',
            'Gestion Loft 514',
            'Construction Horizon',
            'Élodie Nguyen',
            'Atelier Mile End',
        ])
        ->and($clientTypes)->toContain('company', 'individual')
        ->and(array_column($stories, 'lifecycle_state'))->toContain('active_customer', 'qualified_prospect')
        ->and($qualityProtocols)->toHaveCount(4)
        ->and($qualityProtocols['incident_recovery']['status_path'])->toBe(['dispute', 'in_progress', 'pending_review', 'validated', 'closed'])
        ->and(BorealPropreteBlueprint::expenseTemplates())->toHaveCount(12)
        ->and($seasonality['monthly_demand_multipliers'])->toHaveCount(12)
        ->and($seasonality['events'])->toHaveCount(6)
        ->and(round(array_sum(array_column($paymentMethods, 'weight')), 2))->toBe(1.0);

    foreach ($stories as $story) {
        expect($story['profile']['internal_note'])->not->toBeEmpty()
            ->and($story['profile']['service_keys'])->not->toBeEmpty()
            ->and($story['profile']['billing'])->toHaveKeys(['mode', 'cycle', 'grouping', 'delay_days'])
            ->and($story['expected_records'])->not->toBeEmpty()
            ->and($story['timeline'])->not->toBeEmpty();

        $offsets = array_column($story['timeline'], 'offset_days');
        $sortedOffsets = $offsets;
        sort($sortedOffsets);
        expect($offsets)->toBe($sortedOffsets);
    }
});
