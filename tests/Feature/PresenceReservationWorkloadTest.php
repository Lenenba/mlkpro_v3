<?php

use App\Models\DemoWorkspace;
use App\Models\Reservation;
use App\Models\TeamMember;
use App\Models\User;
use Carbon\CarbonImmutable;

function presenceReservationOwner(array $features = []): User
{
    return User::factory()->create([
        'company_type' => 'services',
        'company_sector' => 'professional',
        'company_timezone' => 'America/Toronto',
        'company_features' => array_replace([
            'presence' => true,
            'reservations' => true,
            'jobs' => false,
            'tasks' => false,
        ], $features),
        'selected_plan_key' => null,
    ]);
}

function presenceReservationAt(
    User $owner,
    TeamMember $member,
    CarbonImmutable $startsAt,
    string $status = Reservation::STATUS_CONFIRMED,
): Reservation {
    return Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $member->id,
        'status' => $status,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'America/Toronto',
        'starts_at' => $startsAt->utc(),
        'ends_at' => $startsAt->addHour()->utc(),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
        'created_by_user_id' => $owner->id,
    ]);
}

it('uses reservation capability for presence workload regardless of business sector', function () {
    $reference = CarbonImmutable::parse('2026-08-20 12:00:00', 'America/Toronto');
    $this->travelTo($reference);

    $owner = presenceReservationOwner();
    $employee = User::factory()->create();
    $member = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'is_active' => true,
    ]);

    presenceReservationAt($owner, $member, $reference->setTime(9, 0));
    presenceReservationAt($owner, $member, $reference->setTime(11, 0), Reservation::STATUS_COMPLETED);
    presenceReservationAt($owner, $member, $reference->setTime(13, 0), Reservation::STATUS_CANCELLED);
    presenceReservationAt($owner, $member, $reference->addDay()->setTime(9, 0));

    $otherOwner = presenceReservationOwner();
    $otherMember = TeamMember::factory()->create(['account_id' => $otherOwner->id]);
    presenceReservationAt($otherOwner, $otherMember, $reference->setTime(10, 0));

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('presence.index'))
        ->assertOk()
        ->assertJsonPath('workload.reservations', true)
        ->assertJsonPath('workload.jobs', false)
        ->assertJsonPath('workload.tasks', false);

    $employeePayload = collect($response->json('people'))->firstWhere('id', $employee->id);

    expect($employeePayload)->toBeArray()
        ->and($employeePayload['reservations_today'])->toBe(2)
        ->and($employeePayload)->not->toHaveKeys(['jobs_today', 'tasks_today']);
});

it('uses reservation capability for presence regardless of the legacy company type', function () {
    $owner = presenceReservationOwner();
    $owner->forceFill([
        'company_type' => 'products',
        'company_features' => [
            'presence' => true,
            'reservations' => true,
            'sales' => false,
            'jobs' => false,
            'tasks' => false,
        ],
    ])->save();

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('presence.index'))
        ->assertOk()
        ->assertJsonPath('workload.reservations', true)
        ->assertJsonPath('workload.jobs', false)
        ->assertJsonPath('workload.tasks', false);
});

it('keeps jobs and tasks independently capability driven', function () {
    $reference = CarbonImmutable::parse('2026-08-20 12:00:00', 'America/Toronto');
    $this->travelTo($reference);

    $owner = presenceReservationOwner([
        'reservations' => false,
        'jobs' => false,
        'tasks' => true,
    ]);
    $employee = User::factory()->create();
    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'is_active' => true,
    ]);

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('presence.index'))
        ->assertOk()
        ->assertJsonPath('workload.reservations', false)
        ->assertJsonPath('workload.jobs', false)
        ->assertJsonPath('workload.tasks', true);

    $employeePayload = collect($response->json('people'))->firstWhere('id', $employee->id);

    expect($employeePayload)->toBeArray()
        ->and($employeePayload)->toHaveKey('tasks_today', 0)
        ->and($employeePayload)->not->toHaveKeys(['reservations_today', 'jobs_today']);
});

it('uses the deterministic workspace reference date for demo presence workload', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-15 12:00:00', 'America/Toronto'));

    $owner = presenceReservationOwner();
    $employee = User::factory()->create();
    $member = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'is_active' => true,
    ]);
    DemoWorkspace::query()->create([
        'owner_user_id' => $owner->id,
        'prospect_name' => 'Reference clock',
        'company_name' => 'Reference clock company',
        'company_type' => 'services',
        'company_sector' => 'professional',
        'seed_profile' => 'light',
        'locale' => 'fr',
        'timezone' => 'America/Toronto',
        'selected_modules' => ['presence', 'reservations'],
        'scenario_key' => 'reference_clock_test',
        'reference_date' => '2026-08-20',
        'data_volume' => 'small',
        'random_seed' => 1,
        'scenario_version' => 1,
    ]);

    presenceReservationAt(
        $owner,
        $member,
        CarbonImmutable::parse('2026-08-20 10:00:00', 'America/Toronto'),
    );
    presenceReservationAt(
        $owner,
        $member,
        CarbonImmutable::parse('2027-01-15 10:00:00', 'America/Toronto'),
    );

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('presence.index'))
        ->assertOk();

    $employeePayload = collect($response->json('people'))->firstWhere('id', $employee->id);

    expect($employeePayload)->toBeArray()
        ->and($employeePayload['reservations_today'])->toBe(1);
});
