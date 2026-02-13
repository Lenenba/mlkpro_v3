<?php

use App\Http\Controllers\OnboardingController;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

function onboardingInviteRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name . ' role']
    )->id;
}

function onboardingInviteOwner(array $attributes = []): User
{
    $defaults = [
        'name' => 'Onboarding Invite Owner',
        'email' => 'owner-' . Str::lower(Str::random(10)) . '@example.com',
        'password' => 'password',
        'role_id' => onboardingInviteRoleId('owner'),
        'company_type' => 'services',
        'company_sector' => 'service_general',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'team_members' => true,
            'jobs' => false,
            'tasks' => true,
            'quotes' => false,
            'reservations' => false,
            'sales' => false,
        ],
    ];

    return User::query()->create(array_merge($defaults, $attributes));
}

function invokeOnboardingInviteApply(User $owner, array $invites): array
{
    $controller = app(OnboardingController::class);
    $method = new ReflectionMethod(OnboardingController::class, 'applyInvitesFromSession');
    $method->setAccessible(true);

    $request = Request::create('/onboarding/billing', 'GET');
    $session = app('session')->driver();
    $session->start();
    $session->put('onboarding_invites', $invites);
    $request->setLaravelSession($session);

    /** @var array{passwords: array, count: int} $result */
    $result = $method->invoke($controller, $request, $owner);

    return $result;
}

test('onboarding invites generate permissions only for enabled modules', function () {
    onboardingInviteRoleId('employee');
    $owner = onboardingInviteOwner([
        'company_features' => [
            'team_members' => true,
            'jobs' => false,
            'tasks' => true,
            'quotes' => false,
            'reservations' => false,
            'sales' => false,
        ],
    ]);

    $result = invokeOnboardingInviteApply($owner, [[
        'name' => 'Invite Admin',
        'email' => 'invite-admin-' . Str::lower(Str::random(10)) . '@example.com',
        'role' => 'admin',
    ]]);

    expect($result['count'])->toBe(1);

    $member = TeamMember::query()
        ->where('account_id', $owner->id)
        ->latest('id')
        ->first();

    expect($member)->not->toBeNull();
    expect($member->permissions)->toBe([
        'tasks.view',
        'tasks.create',
        'tasks.edit',
        'tasks.delete',
    ]);
});

test('onboarding invites keep reservation permissions when reservation module is enabled', function () {
    onboardingInviteRoleId('employee');
    $owner = onboardingInviteOwner([
        'company_sector' => 'salon',
        'company_features' => [
            'team_members' => true,
            'jobs' => false,
            'tasks' => false,
            'quotes' => false,
            'reservations' => true,
            'sales' => false,
        ],
    ]);

    $result = invokeOnboardingInviteApply($owner, [[
        'name' => 'Invite Member',
        'email' => 'invite-member-' . Str::lower(Str::random(10)) . '@example.com',
        'role' => 'member',
    ]]);

    expect($result['count'])->toBe(1);

    $member = TeamMember::query()
        ->where('account_id', $owner->id)
        ->latest('id')
        ->first();

    expect($member)->not->toBeNull();
    expect($member->permissions)->toBe([
        'reservations.view',
        'reservations.queue',
    ]);
});
