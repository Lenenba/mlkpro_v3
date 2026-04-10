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
        ->and($resolved['solo_essential']['assistant'])->toBeFalse()
        ->and($resolved['solo_essential']['campaigns'])->toBeFalse()
        ->and($resolved['solo_essential']['loyalty'])->toBeFalse()
        ->and($resolved['solo_essential']['plan_scans'])->toBeFalse()
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
        ->and($planModules['solo_essential']['performance'])->toBe($planModules['starter']['performance'])
        ->and($planModules['solo_essential']['sales'])->toBe($planModules['starter']['sales'])
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
        ->and($planModules['solo_essential']['team_members'])->toBeFalse()
        ->and($planModules['starter']['team_members'])->toBeTrue();
});
