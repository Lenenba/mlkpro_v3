<?php

use App\Models\Reservation;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Reservation\ReservationStatusTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('rejects a stale notes-only edit without labeling it as a reschedule', function (): void {
    $account = User::factory()->create();
    $teamMember = TeamMember::factory()->create(['account_id' => $account->id]);
    $reservation = Reservation::query()->create([
        'account_id' => $account->id,
        'team_member_id' => $teamMember->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => Carbon::parse('2026-09-10 14:00:00', 'UTC'),
        'ends_at' => Carbon::parse('2026-09-10 15:00:00', 'UTC'),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);
    $firstSnapshot = Reservation::query()->findOrFail($reservation->id);
    $staleSnapshot = Reservation::query()->findOrFail($reservation->id);
    $transitions = app(ReservationStatusTransitionService::class);

    $first = $transitions->reschedule(
        $firstSnapshot,
        ['internal_notes' => 'First concurrent edit wins.'],
        $account,
        Reservation::STATUS_CHANGE_SOURCE_STAFF_UI,
        expectedStatusVersion: (int) $firstSnapshot->status_version,
        expectedScheduleVersion: (int) $firstSnapshot->schedule_version,
        expectedMutationVersion: (int) $firstSnapshot->mutation_version
    );
    $stale = $transitions->reschedule(
        $staleSnapshot,
        ['internal_notes' => 'Stale edit must not overwrite.'],
        $account,
        Reservation::STATUS_CHANGE_SOURCE_STAFF_UI,
        expectedStatusVersion: (int) $staleSnapshot->status_version,
        expectedScheduleVersion: (int) $staleSnapshot->schedule_version,
        expectedMutationVersion: (int) $staleSnapshot->mutation_version
    );

    expect($first->performed)->toBeTrue()
        ->and($first->attributesChanged)->toBeTrue()
        ->and($first->scheduleChanged)->toBeFalse()
        ->and($stale->performed)->toBeFalse()
        ->and($reservation->refresh()->internal_notes)->toBe('First concurrent edit wins.')
        ->and($reservation->mutation_version)->toBe(1)
        ->and($reservation->schedule_version)->toBe(0);
});

it('accepts an identical retry without reporting a schedule change or advancing its version', function (): void {
    $account = User::factory()->create();
    $teamMember = TeamMember::factory()->create(['account_id' => $account->id]);
    $reservation = Reservation::query()->create([
        'account_id' => $account->id,
        'team_member_id' => $teamMember->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => Carbon::parse('2026-09-10 14:00:00', 'UTC'),
        'ends_at' => Carbon::parse('2026-09-10 15:00:00', 'UTC'),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
        'internal_notes' => 'Already saved.',
        'mutation_version' => 4,
    ]);

    $result = app(ReservationStatusTransitionService::class)->reschedule(
        $reservation,
        ['internal_notes' => 'Already saved.'],
        $account,
        Reservation::STATUS_CHANGE_SOURCE_STAFF_UI,
        expectedStatusVersion: (int) $reservation->status_version,
        expectedScheduleVersion: (int) $reservation->schedule_version,
        expectedMutationVersion: (int) $reservation->mutation_version
    );

    expect($result->performed)->toBeTrue()
        ->and($result->attributesChanged)->toBeFalse()
        ->and($result->scheduleChanged)->toBeFalse()
        ->and($result->reservation->mutation_version)->toBe(4);
});
