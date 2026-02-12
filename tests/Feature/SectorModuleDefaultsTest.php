<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
});

function sectorModuleRoleId(string $name, string $description): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $description]
    )->id;
}

function sectorModuleOwner(string $sector, string $email): User
{
    return User::query()->create([
        'name' => ucfirst($sector) . ' Owner',
        'email' => $email,
        'password' => 'password',
        'role_id' => sectorModuleRoleId('owner', 'Account owner role'),
        'company_type' => 'services',
        'company_sector' => $sector,
        'onboarding_completed_at' => now(),
        'company_features' => [],
    ]);
}

it('disables reservations module by default for non-salon sectors', function () {
    $owner = sectorModuleOwner('service_general', 'service-general-owner@example.com');

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.index'))
        ->assertForbidden()
        ->assertJsonPath('message', 'Module unavailable for your plan.');
});

it('enables reservations but disables sales/service pipeline modules by default for salon and restaurant', function () {
    $salonOwner = sectorModuleOwner('salon', 'salon-default-owner@example.com');
    $restaurantOwner = sectorModuleOwner('restaurant', 'restaurant-default-owner@example.com');

    foreach ([$salonOwner, $restaurantOwner] as $owner) {
        $this->actingAs($owner)
            ->withSession(['two_factor_passed' => true])
            ->getJson(route('reservation.index'))
            ->assertOk();

        foreach ([
            'quote.index',
            'request.index',
            'plan-scans.index',
            'product.index',
            'jobs.index',
            'task.index',
        ] as $routeName) {
            $this->actingAs($owner)
                ->withSession(['two_factor_passed' => true])
                ->getJson(route($routeName))
                ->assertForbidden()
                ->assertJsonPath('message', 'Module unavailable for your plan.');
        }
    }
});

it('allows reservations for non-salon sectors when explicitly enabled by feature override', function () {
    $owner = sectorModuleOwner('service_general', 'service-general-override-owner@example.com');
    $owner->update([
        'company_features' => array_replace(
            (array) ($owner->company_features ?? []),
            ['reservations' => true]
        ),
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.index'))
        ->assertOk();
});
