<?php

use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function performanceAuthRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function performanceAuthOwner(string $companyType = 'services'): User
{
    return User::query()->create([
        'name' => 'Performance Owner',
        'email' => 'performance-owner-'.Str::lower(Str::random(8)).'@example.com',
        'password' => 'password',
        'role_id' => performanceAuthRoleId('owner'),
        'company_type' => $companyType,
        'company_sector' => $companyType === 'products' ? 'retail' : 'salon',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'team_members' => true,
            'performance' => true,
            'jobs' => $companyType !== 'products',
            'tasks' => $companyType !== 'products',
            'sales' => $companyType === 'products',
        ],
    ]);
}

function performanceAuthMember(User $owner, array $overrides = []): TeamMember
{
    $identifier = Str::lower(Str::random(8));
    $employee = User::query()->create([
        'name' => $overrides['name'] ?? 'Performance Member',
        'email' => $overrides['email'] ?? "performance-member-{$identifier}@example.com",
        'password' => 'password',
        'role_id' => performanceAuthRoleId('employee'),
        'onboarding_completed_at' => now(),
    ]);

    return TeamMember::query()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => $overrides['role'] ?? 'member',
        'title' => $overrides['title'] ?? 'Staff',
        'permissions' => $overrides['permissions'] ?? [],
        'is_active' => $overrides['is_active'] ?? true,
    ]);
}

it('blocks service members from team performance and other employee performance', function () {
    $owner = performanceAuthOwner();
    $member = performanceAuthMember($owner, [
        'permissions' => ['jobs.view', 'tasks.view', 'update_tasks'],
    ]);
    $otherMember = performanceAuthMember($owner, [
        'permissions' => ['jobs.view', 'tasks.view'],
    ]);

    $memberUser = $member->user()->firstOrFail();
    $otherUser = $otherMember->user()->firstOrFail();

    $this->actingAs($memberUser)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('performance.index'))
        ->assertForbidden();

    $this->actingAs($memberUser)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('performance.employee.show', $otherUser))
        ->assertForbidden();

    $this->actingAs($memberUser)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('performance.employee.show', $memberUser))
        ->assertOk()
        ->assertJsonPath('employee.id', $memberUser->id);
});

it('allows admins and report viewers to see team performance', function () {
    $owner = performanceAuthOwner();
    $lead = performanceAuthMember($owner, [
        'permissions' => ['view_team_reports'],
    ]);
    $admin = performanceAuthMember($owner, [
        'role' => 'admin',
        'permissions' => [],
    ]);
    $otherMember = performanceAuthMember($owner);

    $leadUser = $lead->user()->firstOrFail();
    $adminUser = $admin->user()->firstOrFail();
    $otherUser = $otherMember->user()->firstOrFail();

    $this->actingAs($leadUser)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('performance.index'))
        ->assertOk();

    $this->actingAs($leadUser)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('performance.employee.show', $otherUser))
        ->assertOk()
        ->assertJsonPath('employee.id', $otherUser->id);

    $this->actingAs($adminUser)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('performance.index'))
        ->assertOk();
});

it('does not treat point of sale access as product team performance access', function () {
    $owner = performanceAuthOwner('products');
    $seller = performanceAuthMember($owner, [
        'role' => 'seller',
        'permissions' => ['sales.pos'],
    ]);

    $sellerUser = $seller->user()->firstOrFail();

    $this->actingAs($sellerUser)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('performance.index'))
        ->assertForbidden();
});
