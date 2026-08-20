<?php

use App\Services\Demo\Scenarios\StudioNaya\StudioNayaBlueprint;

test('studio naya blueprint exposes its fictional salon identity and differentiated team', function () {
    $identity = StudioNayaBlueprint::identity();
    $employees = StudioNayaBlueprint::employees();

    expect($identity)
        ->toMatchArray([
            'name' => 'Studio Naya Coiffure',
            'category_key' => 'hair_salon',
            'primary_locale' => 'fr_CA',
            'currency_code' => 'CAD',
            'timezone' => 'America/Toronto',
            'operating_history_months' => 18,
        ])
        ->and($identity['email'])->toEndWith('.example')
        ->and($identity['business_hours'][1])->toBeNull()
        ->and($identity['business_hours'][7])->toBeNull()
        ->and($employees)->toHaveCount(5)
        ->and(array_column($employees, 'name'))->toBe([
            'Maya Koné',
            'Sarah Mbaye',
            'Alicia Tremblay',
            'Kevin Diallo',
            'Emma Roy',
        ]);

    foreach ($employees as $employee) {
        expect(array_keys($employee['schedule']))->toBe([2, 3, 4, 5, 6])
            ->and($employee['specialties'])->not->toBeEmpty()
            ->and($employee['absence_templates'])->not->toBeEmpty();
    }

    $schedules = array_map(
        static fn (array $employee): string => json_encode($employee['schedule'], JSON_THROW_ON_ERROR),
        $employees
    );

    expect(array_unique($schedules))->toHaveCount(5);
});

test('studio naya service and product catalogs are complete and internally coherent', function () {
    $categories = StudioNayaBlueprint::serviceCategories();
    $services = StudioNayaBlueprint::services();
    $suppliers = StudioNayaBlueprint::suppliers();
    $products = StudioNayaBlueprint::products();
    $categoryKeys = array_column($categories, 'key');
    $serviceKeys = array_column($services, 'key');
    $supplierKeys = array_column($suppliers, 'key');
    $productKeys = array_column($products, 'key');

    expect($services)->toHaveCount(28)
        ->and(count($services))->toBeGreaterThanOrEqual(25)
        ->and(count($services))->toBeLessThanOrEqual(30)
        ->and(array_unique($serviceKeys))->toHaveCount(count($serviceKeys))
        ->and($products)->toHaveCount(18)
        ->and(array_unique($productKeys))->toHaveCount(count($productKeys));

    foreach ($services as $service) {
        expect($service['category_key'])->toBeIn($categoryKeys)
            ->and($service['duration_minutes'])->toBeGreaterThan(0)
            ->and($service['price'])->toBeGreaterThanOrEqual(0)
            ->and($service['buffer_before_minutes'])->toBeGreaterThanOrEqual(0)
            ->and($service['buffer_after_minutes'])->toBeGreaterThanOrEqual(0)
            ->and($service['tags'])->not->toBeEmpty()
            ->and($service['calendar_color'])->toMatch('/^#[0-9A-F]{6}$/')
            ->and($service['metadata'])->toHaveKeys([
                'demand_profile',
                'seasonal',
                'bundle',
                'price_history',
                'consumables',
            ]);

        foreach ($service['metadata']['consumables'] as $consumable) {
            expect($consumable['product_key'])->toBeIn($productKeys)
                ->and($consumable['quantity'])->toBeGreaterThan(0);
        }
    }

    expect(array_filter($services, static fn (array $service): bool => ! $service['active']))->not->toBeEmpty()
        ->and(array_filter($services, static fn (array $service): bool => $service['metadata']['seasonal']))->not->toBeEmpty()
        ->and(array_filter($services, static fn (array $service): bool => $service['metadata']['bundle']))->not->toBeEmpty()
        ->and(array_filter($services, static fn (array $service): bool => $service['metadata']['price_history'] !== []))->not->toBeEmpty();

    foreach ($products as $product) {
        expect($product['supplier_key'])->toBeIn($supplierKeys)
            ->and($product['cost'])->toBeGreaterThan(0)
            ->and($product['price'])->toBeGreaterThanOrEqual(0)
            ->and($product['stock_on_hand'])->toBeGreaterThanOrEqual(0)
            ->and($product['reorder_threshold'])->toBeGreaterThanOrEqual(0)
            ->and($product['metadata']['stock_state'])->toBeIn(['healthy', 'low', 'out_of_stock']);

        if ($product['retail']) {
            expect($product['price'])->toBeGreaterThan($product['cost']);
        }
    }
});

test('studio naya skill matrix references known employees and services', function () {
    $employeeKeys = array_column(StudioNayaBlueprint::employees(), 'key');
    $services = StudioNayaBlueprint::services();
    $serviceKeys = array_column($services, 'key');
    $activeServiceKeys = array_column(
        array_filter($services, static fn (array $service): bool => $service['active']),
        'key'
    );
    $matrix = StudioNayaBlueprint::employeeServiceMatrix();
    $bookableAcrossTeam = [];

    expect(array_keys($matrix))->toBe($employeeKeys);

    foreach ($matrix as $employeeKey => $skills) {
        expect($employeeKey)->toBeIn($employeeKeys)
            ->and(array_intersect($skills['bookable_service_keys'], $skills['assist_only_service_keys']))->toBe([]);

        foreach (array_merge($skills['bookable_service_keys'], $skills['assist_only_service_keys']) as $serviceKey) {
            expect($serviceKey)->toBeIn($serviceKeys);
        }

        $bookableAcrossTeam = array_merge($bookableAcrossTeam, $skills['bookable_service_keys']);
    }

    expect(array_values(array_diff($activeServiceKeys, array_unique($bookableAcrossTeam))))->toBe([])
        ->and($matrix['emma_roy']['bookable_service_keys'])->toHaveCount(3)
        ->and($matrix['emma_roy']['assist_only_service_keys'])->not->toBeEmpty();
});

test('studio naya declares the five named client stories and operating templates', function () {
    $stories = StudioNayaBlueprint::clientStories();
    $expenses = StudioNayaBlueprint::expenseTemplates();
    $seasonality = StudioNayaBlueprint::seasonality();
    $paymentMethods = StudioNayaBlueprint::paymentMethods();

    expect($stories)->toHaveCount(5)
        ->and(array_column($stories, 'name'))->toBe([
            'Aïcha Martin',
            'Samantha Joseph',
            'Nadia Pierre',
            'Marc-André Beaulieu',
            'Chloé Nguyen',
        ])
        ->and($expenses)->toHaveCount(13)
        ->and($seasonality['monthly_demand_multipliers'])->toHaveCount(12)
        ->and($seasonality['weekday_demand_weights'][1])->toBe(0.0)
        ->and($seasonality['weekday_demand_weights'][7])->toBe(0.0)
        ->and(round(array_sum(array_column($paymentMethods, 'weight')), 2))->toBe(1.0);

    foreach ($stories as $story) {
        expect($story['profile']['internal_note'])->not->toBeEmpty()
            ->and($story['expected_records'])->not->toBeEmpty()
            ->and($story['timeline'])->not->toBeEmpty();
    }
});
