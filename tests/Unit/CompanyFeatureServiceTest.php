<?php

use App\Models\PlatformSetting;
use App\Services\CompanyFeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('normalizes stale owner-only plan modules from platform settings', function () {
    $planModules = CompanyFeatureService::defaultPlanModules();
    $planModules['solo_essential'] = array_fill_keys(array_keys($planModules['solo_essential'] ?? []), true);

    PlatformSetting::setValue('plan_modules', $planModules);

    $resolved = app(CompanyFeatureService::class)->resolvePlanModules();

    expect($resolved['solo_essential']['jobs'])->toBeTrue()
        ->and($resolved['solo_essential']['tasks'])->toBeTrue()
        ->and($resolved['solo_essential']['sales'])->toBeTrue()
        ->and($resolved['solo_essential']['expenses'])->toBeTrue()
        ->and($resolved['solo_essential']['accounting'])->toBeFalse()
        ->and($resolved['solo_essential']['assistant'])->toBeFalse()
        ->and($resolved['solo_essential']['campaigns'])->toBeFalse()
        ->and($resolved['solo_essential']['loyalty'])->toBeFalse()
        ->and($resolved['solo_essential']['plan_scans'])->toBeFalse()
        ->and($resolved['solo_essential']['performance'])->toBeFalse()
        ->and($resolved['solo_essential']['planning'])->toBeFalse()
        ->and($resolved['solo_essential']['reservations'])->toBeFalse()
        ->and($resolved['solo_essential']['presence'])->toBeFalse()
        ->and($resolved['solo_essential']['team_members'])->toBeFalse();
});

it('keeps owner-only solo plans on the simplified module path until solo growth', function () {
    $planModules = CompanyFeatureService::defaultPlanModules();

    expect($planModules['solo_essential']['quotes'])->toBe($planModules['starter']['quotes'])
        ->and($planModules['solo_essential']['requests'])->toBe($planModules['starter']['requests'])
        ->and($planModules['solo_essential']['invoices'])->toBe($planModules['starter']['invoices'])
        ->and($planModules['solo_essential']['jobs'])->toBe($planModules['starter']['jobs'])
        ->and($planModules['solo_essential']['products'])->toBe($planModules['starter']['products'])
        ->and($planModules['solo_essential']['performance'])->toBeFalse()
        ->and($planModules['solo_pro']['performance'])->toBeFalse()
        ->and($planModules['solo_growth']['performance'])->toBeFalse()
        ->and($planModules['starter']['performance'])->toBeTrue()
        ->and($planModules['solo_essential']['sales'])->toBe($planModules['starter']['sales'])
        ->and($planModules['solo_essential']['expenses'])->toBeTrue()
        ->and($planModules['solo_essential']['accounting'])->toBeFalse()
        ->and($planModules['solo_essential']['services'])->toBe($planModules['starter']['services'])
        ->and($planModules['solo_essential']['tasks'])->toBe($planModules['starter']['tasks'])
        ->and($planModules['solo_essential']['plan_scans'])->toBeFalse()
        ->and($planModules['solo_pro']['plan_scans'])->toBeFalse()
        ->and($planModules['solo_growth']['plan_scans'])->toBeTrue()
        ->and($planModules['solo_essential']['planning'])->toBeFalse()
        ->and($planModules['solo_pro']['planning'])->toBeFalse()
        ->and($planModules['solo_growth']['planning'])->toBeTrue()
        ->and($planModules['solo_essential']['assistant'])->toBeFalse()
        ->and($planModules['solo_pro']['assistant'])->toBeFalse()
        ->and($planModules['solo_growth']['assistant'])->toBeTrue()
        ->and($planModules['solo_growth']['expenses'])->toBeTrue()
        ->and($planModules['solo_growth']['accounting'])->toBeFalse()
        ->and($planModules['solo_essential']['campaigns'])->toBeFalse()
        ->and($planModules['solo_pro']['campaigns'])->toBeFalse()
        ->and($planModules['solo_growth']['campaigns'])->toBeTrue()
        ->and($planModules['solo_essential']['loyalty'])->toBeFalse()
        ->and($planModules['solo_pro']['loyalty'])->toBeFalse()
        ->and($planModules['solo_growth']['loyalty'])->toBeTrue()
        ->and($planModules['solo_essential']['reservations'])->toBeFalse()
        ->and($planModules['solo_pro']['reservations'])->toBeFalse()
        ->and($planModules['solo_growth']['reservations'])->toBeTrue()
        ->and($planModules['solo_essential']['presence'])->toBeFalse()
        ->and($planModules['starter']['presence'])->toBeTrue()
        ->and($planModules['starter']['expenses'])->toBeTrue()
        ->and($planModules['starter']['accounting'])->toBeFalse()
        ->and($planModules['solo_essential']['team_members'])->toBeFalse()
        ->and($planModules['starter']['team_members'])->toBeTrue();
});

it('disables accounting automatically when expenses are not enabled', function () {
    $ownerRoleId = \App\Models\Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Account owner role']
    )->id;

    $owner = \App\Models\User::query()->create([
        'name' => 'Accounting Dependency Owner',
        'email' => 'accounting-dependency-owner@example.com',
        'password' => 'password',
        'role_id' => $ownerRoleId,
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'expenses' => false,
            'accounting' => true,
        ],
    ]);

    $resolved = app(CompanyFeatureService::class)->resolveEffectiveFeatures($owner);

    expect($resolved['expenses'] ?? null)->toBeFalse()
        ->and($resolved['accounting'] ?? null)->toBeFalse();
});

it('does not infer loyalty availability from stored loyalty data when the feature is disabled', function () {
    $ownerRoleId = \App\Models\Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Account owner role']
    )->id;

    $owner = \App\Models\User::query()->create([
        'name' => 'Loyalty Disabled Owner',
        'email' => 'loyalty-disabled-owner@example.com',
        'password' => 'password',
        'role_id' => $ownerRoleId,
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'loyalty' => false,
        ],
    ]);

    \App\Models\LoyaltyProgram::query()->create([
        'user_id' => $owner->id,
        'is_enabled' => true,
        'points_per_currency_unit' => 2,
        'minimum_spend' => 10,
        'rounding_mode' => 'round',
        'points_label' => 'pts',
    ]);

    $resolved = app(CompanyFeatureService::class)->resolveEffectiveFeatures($owner);

    expect($resolved['loyalty'] ?? null)->toBeFalse()
        ->and(app(CompanyFeatureService::class)->hasFeature($owner, 'loyalty'))->toBeFalse()
        ->and(app(CompanyFeatureService::class)->resolveEnabledFeatures($owner))->not->toHaveKey('loyalty');
});
