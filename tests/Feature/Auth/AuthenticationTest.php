<?php

use App\Models\Customer;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\TeamMemberAttendance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('login screen inherits the selected public locale', function () {
    $this->withSession(['locale' => 'es'])
        ->get('/login')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')
            ->where('locale', 'es')
        );
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('login replaces a stale open attendance with an available session using the company local day', function () {
    $now = CarbonImmutable::parse('2026-09-03 00:30:00', 'America/Toronto');
    $this->travelTo($now);

    $owner = User::factory()->create([
        'company_timezone' => 'America/Toronto',
        'company_features' => [
            'presence' => true,
            'sales' => false,
            'jobs' => false,
            'tasks' => false,
            'reservations' => false,
        ],
        'company_time_settings' => [
            'auto_clock_in' => true,
            'auto_clock_out' => true,
            'manual_clock' => true,
        ],
        'selected_plan_key' => null,
    ]);
    $employeeRole = Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee role'],
    );
    $employee = User::factory()->create([
        'role_id' => $employeeRole->id,
        'password' => 'password',
    ]);
    $membership = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'is_active' => true,
    ]);
    $staleClockIn = CarbonImmutable::parse('2026-09-02 23:30:00', 'America/Toronto');
    $staleAttendance = TeamMemberAttendance::query()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'team_member_id' => $membership->id,
        'clock_in_at' => $staleClockIn->utc(),
        'clock_out_at' => null,
        'method' => 'auto',
        'current_status' => TeamMemberAttendance::STATUS_BREAK,
    ]);

    $this->post('/login', [
        'email' => $employee->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $staleAttendance->refresh();
    $currentAttendance = TeamMemberAttendance::query()
        ->where('account_id', $owner->id)
        ->where('user_id', $employee->id)
        ->whereNull('clock_out_at')
        ->sole();

    expect($staleAttendance->clock_out_at?->toIso8601String())
        ->toBe($staleClockIn->endOfDay()->utc()->toIso8601String())
        ->and($staleAttendance->clock_out_method)->toBe('auto')
        ->and($staleAttendance->current_status)->toBe(TeamMemberAttendance::STATUS_OFFLINE)
        ->and($currentAttendance->id)->not->toBe($staleAttendance->id)
        ->and($currentAttendance->team_member_id)->toBe($membership->id)
        ->and($currentAttendance->method)->toBe('auto')
        ->and($currentAttendance->current_status)->toBe(TeamMemberAttendance::STATUS_AVAILABLE)
        ->and($currentAttendance->clock_in_at?->toIso8601String())->toBe($now->utc()->toIso8601String());
});

test('login preserves a break attendance opened on the same company local day', function () {
    $now = CarbonImmutable::parse('2026-09-03 08:00:00', 'America/Toronto');
    $this->travelTo($now);

    $owner = User::factory()->create([
        'company_timezone' => 'America/Toronto',
        'company_features' => ['presence' => true],
        'company_time_settings' => ['auto_clock_in' => true],
        'selected_plan_key' => null,
    ]);
    $employeeRole = Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee role'],
    );
    $employee = User::factory()->create([
        'role_id' => $employeeRole->id,
        'password' => 'password',
    ]);
    $membership = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'is_active' => true,
    ]);
    $attendance = TeamMemberAttendance::query()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'team_member_id' => $membership->id,
        'clock_in_at' => $now->subHour()->utc(),
        'clock_out_at' => null,
        'method' => 'manual',
        'current_status' => TeamMemberAttendance::STATUS_BREAK,
    ]);

    $this->post('/login', [
        'email' => $employee->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    expect($attendance->fresh()->clock_out_at)->toBeNull()
        ->and($attendance->fresh()->current_status)->toBe(TeamMemberAttendance::STATUS_BREAK)
        ->and(TeamMemberAttendance::query()
            ->where('account_id', $owner->id)
            ->where('user_id', $employee->id)
            ->count())->toBe(1);
});

test('clients with disabled portal access can not authenticate using the login screen', function () {
    $clientRole = Role::query()->firstOrCreate(
        ['name' => 'client'],
        ['description' => 'Client role'],
    );
    $owner = User::factory()->create();
    $client = User::factory()->create([
        'role_id' => $clientRole->id,
        'password' => 'password',
    ]);
    Customer::factory()->create([
        'user_id' => $owner->id,
        'portal_user_id' => $client->id,
        'portal_access' => false,
        'email' => $client->email,
    ]);

    $response = $this->post('/login', [
        'email' => $client->email,
        'password' => 'password',
    ]);

    $response
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors([
            'email' => __('ui.auth.portal_access_disabled'),
        ]);
    $this->assertGuest();
});

test('users can authenticate from onboarding and keep the selected plan context', function () {
    $user = User::factory()->create([
        'onboarding_completed_at' => null,
    ]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
        'source' => 'onboarding',
        'plan' => 'growth',
        'billing_period' => 'yearly',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('onboarding.index', [
        'plan' => 'growth',
        'billing_period' => 'yearly',
    ]));
});

test('users without a saved locale inherit the selected public locale on login', function () {
    $user = User::factory()->create([
        'locale' => null,
        'onboarding_completed_at' => null,
    ]);

    $response = $this->withSession(['locale' => 'es'])->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('onboarding.index', absolute: false))
        ->assertSessionHas('locale', 'es');

    expect($user->fresh()->locale)->toBe('es');
});

test('login keeps the saved user locale when it already exists', function () {
    $user = User::factory()->create([
        'locale' => 'en',
    ]);

    $response = $this->withSession(['locale' => 'es'])->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false))
        ->assertSessionHas('locale', 'en');

    expect($user->fresh()->locale)->toBe('en');
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('login errors are localized with the selected public locale', function () {
    $user = User::factory()->create();

    $this->withSession(['locale' => 'es'])
        ->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])
        ->assertSessionHasErrors([
            'email' => __('auth.failed', [], 'es'),
        ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});
