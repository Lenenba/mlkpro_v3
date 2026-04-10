<?php

use App\Models\Billing\StripeSubscription;
use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyFeatureService;
use App\Services\UsageLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function planEntitlementOwnerRoleId(): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Account owner role']
    )->id;
}

function planEntitlementExpectedLimits(): array
{
    $limitKeys = array_keys(UsageLimitService::LIMIT_KEYS);

    return collect(config('billing.plans', []))
        ->mapWithKeys(function (array $plan, string $planKey) use ($limitKeys): array {
            $configuredLimits = is_array($plan['default_limits'] ?? null) ? $plan['default_limits'] : [];
            $resolved = [];

            foreach ($limitKeys as $limitKey) {
                $value = $configuredLimits[$limitKey] ?? null;
                $resolved[$limitKey] = is_numeric($value) ? max(0, (int) $value) : null;
            }

            return [$planKey => $resolved];
        })
        ->all();
}

function planEntitlementCanonicalize(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    foreach ($value as $key => $item) {
        $value[$key] = planEntitlementCanonicalize($item);
    }

    ksort($value);

    return $value;
}

it('syncs plan modules and limits from billing config into platform settings', function () {
    PlatformSetting::setValue('plan_modules', [
        'legacy' => ['quotes' => false],
    ]);
    PlatformSetting::setValue('plan_limits', [
        'legacy' => ['jobs' => 9],
    ]);

    $this->artisan('billing:sync-plan-entitlements')
        ->expectsOutputToContain('Synchronized')
        ->assertExitCode(0);

    expect(planEntitlementCanonicalize(PlatformSetting::getValue('plan_modules', [])))
        ->toBe(planEntitlementCanonicalize(CompanyFeatureService::defaultPlanModules()))
        ->and(planEntitlementCanonicalize(PlatformSetting::getValue('plan_limits', [])))
        ->toBe(planEntitlementCanonicalize(planEntitlementExpectedLimits()));
});

it('syncs the redesigned core and growth entitlements from billing config', function () {
    $this->artisan('billing:sync-plan-entitlements')->assertExitCode(0);

    $modules = PlatformSetting::getValue('plan_modules', []);
    $limits = PlatformSetting::getValue('plan_limits', []);

    expect($modules['solo_essential']['jobs'] ?? null)->toBeTrue()
        ->and($modules['solo_essential']['assistant'] ?? null)->toBeFalse()
        ->and($modules['solo_essential']['campaigns'] ?? null)->toBeFalse()
        ->and($modules['starter']['jobs'] ?? null)->toBeTrue()
        ->and($modules['starter']['assistant'] ?? null)->toBeTrue()
        ->and($modules['starter']['team_members'] ?? null)->toBeTrue()
        ->and($modules['solo_pro']['campaigns'] ?? null)->toBeFalse()
        ->and($modules['solo_growth']['assistant'] ?? null)->toBeTrue()
        ->and($modules['solo_growth']['reservations'] ?? null)->toBeTrue()
        ->and($modules['growth']['loyalty'] ?? null)->toBeTrue()
        ->and($limits['solo_essential']['jobs'] ?? null)->toBe(300)
        ->and($limits['solo_essential']['assistant_requests'] ?? null)->toBe(0)
        ->and($limits['starter']['jobs'] ?? null)->toBe(1000)
        ->and($limits['starter']['team_members'] ?? null)->toBe(5)
        ->and($limits['solo_pro']['plan_scan_quotes'] ?? null)->toBe(0)
        ->and($limits['growth']['assistant_requests'] ?? null)->toBe(2500)
        ->and($limits['scale']['tasks'] ?? null)->toBe(75000);
});

it('can sync only the selected plans without touching the others', function () {
    PlatformSetting::setValue('plan_modules', [
        'starter' => ['assistant' => false],
        'growth' => ['assistant' => false],
    ]);
    PlatformSetting::setValue('plan_limits', [
        'starter' => ['jobs' => 999],
        'growth' => ['jobs' => 999],
    ]);

    $this->artisan('billing:sync-plan-entitlements', [
        '--plans' => 'starter',
    ])->assertExitCode(0);

    $modules = PlatformSetting::getValue('plan_modules', []);
    $limits = PlatformSetting::getValue('plan_limits', []);
    $expectedModules = CompanyFeatureService::defaultPlanModules();
    $expectedLimits = planEntitlementExpectedLimits();

    expect(planEntitlementCanonicalize($modules['starter'] ?? null))->toBe(planEntitlementCanonicalize($expectedModules['starter']))
        ->and(planEntitlementCanonicalize($limits['starter'] ?? null))->toBe(planEntitlementCanonicalize($expectedLimits['starter']))
        ->and($modules['growth']['assistant'] ?? null)->toBeFalse()
        ->and($limits['growth']['jobs'] ?? null)->toBe(999);
});

it('can clear tenant feature and limit overrides for the selected plans', function () {
    config()->set('billing.provider_effective', 'stripe');

    $ownerRoleId = planEntitlementOwnerRoleId();

    $soloGrowthOwner = User::query()->create([
        'name' => 'Solo Growth Owner',
        'email' => 'solo-growth-owner@example.com',
        'password' => 'password',
        'role_id' => $ownerRoleId,
        'onboarding_completed_at' => now(),
        'company_features' => ['assistant' => false],
        'company_limits' => ['assistant_requests' => 12],
    ]);

    StripeSubscription::query()->create([
        'user_id' => $soloGrowthOwner->id,
        'stripe_id' => 'sub_solo_growth',
        'status' => 'active',
        'plan_code' => 'solo_growth',
    ]);

    $starterOwner = User::query()->create([
        'name' => 'Starter Owner',
        'email' => 'starter-owner@example.com',
        'password' => 'password',
        'role_id' => $ownerRoleId,
        'onboarding_completed_at' => now(),
        'company_features' => ['requests' => false],
        'company_limits' => ['jobs' => 45],
    ]);

    StripeSubscription::query()->create([
        'user_id' => $starterOwner->id,
        'stripe_id' => 'sub_starter',
        'status' => 'active',
        'plan_code' => 'starter',
    ]);

    $this->artisan('billing:sync-plan-entitlements', [
        '--plans' => 'solo_growth',
        '--reset-tenant-overrides' => true,
    ])
        ->expectsOutputToContain('Tenant overrides: 1 matched, 1 updated')
        ->assertExitCode(0);

    expect($soloGrowthOwner->fresh()->company_features)->toBeNull()
        ->and($soloGrowthOwner->fresh()->company_limits)->toBeNull()
        ->and($starterOwner->fresh()->company_features)->toBe(['requests' => false])
        ->and($starterOwner->fresh()->company_limits)->toBe(['jobs' => 45]);
});
