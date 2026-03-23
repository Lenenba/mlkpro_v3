<?php

use App\Models\PlatformSetting;
use App\Services\CompanyFeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('normalizes stale owner-only plan modules from platform settings', function () {
    $planModules = CompanyFeatureService::defaultPlanModules();
    $planModules['solo_pro'] = array_fill_keys(array_keys($planModules['solo_pro'] ?? []), true);

    PlatformSetting::setValue('plan_modules', $planModules);

    $resolved = app(CompanyFeatureService::class)->resolvePlanModules();

    expect($resolved['solo_pro']['jobs'])->toBeTrue()
        ->and($resolved['solo_pro']['tasks'])->toBeTrue()
        ->and($resolved['solo_pro']['sales'])->toBeFalse()
        ->and($resolved['solo_pro']['assistant'])->toBeFalse()
        ->and($resolved['solo_pro']['campaigns'])->toBeFalse()
        ->and($resolved['solo_pro']['loyalty'])->toBeFalse()
        ->and($resolved['solo_pro']['plan_scans'])->toBeFalse()
        ->and($resolved['solo_pro']['planning'])->toBeFalse()
        ->and($resolved['solo_pro']['reservations'])->toBeFalse()
        ->and($resolved['solo_pro']['presence'])->toBeFalse()
        ->and($resolved['solo_pro']['team_members'])->toBeFalse();
});
