<?php

use App\Models\Reservation;
use App\Services\Reservation\PastReservationOutcomeDecision;
use Illuminate\Support\Carbon;

uses(Tests\TestCase::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function pastOutcomeDecisionReservation(array $overrides = []): Reservation
{
    $reservation = new Reservation;
    $reservation->forceFill(array_merge([
        'status' => Reservation::STATUS_CONFIRMED,
        'starts_at' => Carbon::parse('2026-08-28 12:00:00', 'UTC'),
        'ends_at' => Carbon::parse('2026-08-28 13:00:00', 'UTC'),
        'created_at' => Carbon::parse('2026-08-20 12:00:00', 'UTC'),
        'status_changed_at' => Carbon::parse('2026-08-28 12:30:00', 'UTC'),
        'status_change_source' => Reservation::STATUS_CHANGE_SOURCE_API,
        'outcome_review_required_at' => null,
    ], $overrides));

    return $reservation;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pastOutcomeDecisionSettings(array $overrides = []): array
{
    return array_merge([
        'past_reservation_reconciliation_enabled' => true,
        'past_reservation_reconciliation_mode' => 'signal_only',
        'past_reservation_grace_minutes' => 120,
        'past_reservation_max_catchup_days' => 7,
    ], $overrides);
}

test('reconciliation stays disabled unless the tenant explicitly enables it', function (array $settings): void {
    $result = (new PastReservationOutcomeDecision)->decide(
        pastOutcomeDecisionReservation(),
        Carbon::parse('2026-08-28 16:00:00', 'UTC'),
        $settings,
    );

    expect($result)->toBe([
        'action' => PastReservationOutcomeDecision::ACTION_LEAVE,
        'reason' => PastReservationOutcomeDecision::REASON_FEATURE_DISABLED,
    ]);
})->with([
    'setting absent' => [[]],
    'setting explicitly disabled' => [[
        'past_reservation_reconciliation_enabled' => false,
        'past_reservation_reconciliation_mode' => 'signal_only',
    ]],
]);

test('the grace threshold is inclusive only once the reservation end plus grace is reached', function (
    string $now,
    string $expectedAction,
    string $expectedReason,
): void {
    $result = (new PastReservationOutcomeDecision)->decide(
        pastOutcomeDecisionReservation(),
        Carbon::parse($now, 'UTC'),
        pastOutcomeDecisionSettings(),
    );

    expect($result)->toBe([
        'action' => $expectedAction,
        'reason' => $expectedReason,
    ]);
})->with([
    'one second before the threshold' => [
        '2026-08-28 14:59:59',
        PastReservationOutcomeDecision::ACTION_LEAVE,
        PastReservationOutcomeDecision::REASON_BEFORE_GRACE,
    ],
    'exactly at the threshold' => [
        '2026-08-28 15:00:00',
        PastReservationOutcomeDecision::ACTION_SIGNAL,
        PastReservationOutcomeDecision::REASON_OUTCOME_MISSING,
    ],
    'one second after the threshold' => [
        '2026-08-28 15:00:01',
        PastReservationOutcomeDecision::ACTION_SIGNAL,
        PastReservationOutcomeDecision::REASON_OUTCOME_MISSING,
    ],
]);

test('every terminal reservation status remains untouched', function (string $status): void {
    $result = (new PastReservationOutcomeDecision)->decide(
        pastOutcomeDecisionReservation(['status' => $status]),
        Carbon::parse('2026-08-28 16:00:00', 'UTC'),
        pastOutcomeDecisionSettings(),
    );

    expect($result)->toBe([
        'action' => PastReservationOutcomeDecision::ACTION_LEAVE,
        'reason' => PastReservationOutcomeDecision::REASON_TERMINAL_STATUS,
    ]);
})->with([
    'cancelled' => [Reservation::STATUS_CANCELLED],
    'completed' => [Reservation::STATUS_COMPLETED],
    'no-show' => [Reservation::STATUS_NO_SHOW],
    'expired' => [Reservation::STATUS_EXPIRED],
]);

test('every active reservation status is signaled without inferring a terminal outcome', function (string $status): void {
    $result = (new PastReservationOutcomeDecision)->decide(
        pastOutcomeDecisionReservation(['status' => $status]),
        Carbon::parse('2026-08-28 16:00:00', 'UTC'),
        pastOutcomeDecisionSettings(),
    );

    expect($result)->toBe([
        'action' => PastReservationOutcomeDecision::ACTION_SIGNAL,
        'reason' => PastReservationOutcomeDecision::REASON_OUTCOME_MISSING,
    ]);
})->with([
    'pending' => [Reservation::STATUS_PENDING],
    'confirmed' => [Reservation::STATUS_CONFIRMED],
    'rescheduled' => [Reservation::STATUS_RESCHEDULED],
]);

test('a human reaffirmation at or after the reservation end is never second-guessed', function (
    string $source,
    string $changedAt,
): void {
    $result = (new PastReservationOutcomeDecision)->decide(
        pastOutcomeDecisionReservation([
            'status_change_source' => $source,
            'status_changed_at' => Carbon::parse($changedAt, 'UTC'),
        ]),
        Carbon::parse('2026-08-28 16:00:00', 'UTC'),
        pastOutcomeDecisionSettings(),
    );

    expect($result)->toBe([
        'action' => PastReservationOutcomeDecision::ACTION_LEAVE,
        'reason' => PastReservationOutcomeDecision::REASON_MANUALLY_REAFFIRMED,
    ]);
})->with([
    'staff exactly at reservation end' => [
        Reservation::STATUS_CHANGE_SOURCE_STAFF_UI,
        '2026-08-28 13:00:00',
    ],
    'client after reservation end' => [
        Reservation::STATUS_CHANGE_SOURCE_CLIENT_PORTAL,
        '2026-08-28 13:00:01',
    ],
    'queue staff after reservation end' => [
        Reservation::STATUS_CHANGE_SOURCE_QUEUE_STAFF,
        '2026-08-28 14:00:00',
    ],
]);

test('pre-end human changes and post-end machine changes still require an outcome', function (
    string $source,
    string $changedAt,
): void {
    $result = (new PastReservationOutcomeDecision)->decide(
        pastOutcomeDecisionReservation([
            'status_change_source' => $source,
            'status_changed_at' => Carbon::parse($changedAt, 'UTC'),
        ]),
        Carbon::parse('2026-08-28 16:00:00', 'UTC'),
        pastOutcomeDecisionSettings(),
    );

    expect($result)->toBe([
        'action' => PastReservationOutcomeDecision::ACTION_SIGNAL,
        'reason' => PastReservationOutcomeDecision::REASON_OUTCOME_MISSING,
    ]);
})->with([
    'staff changed status before reservation end' => [
        Reservation::STATUS_CHANGE_SOURCE_STAFF_UI,
        '2026-08-28 12:59:59',
    ],
    'api changed status after reservation end' => [
        Reservation::STATUS_CHANGE_SOURCE_API,
        '2026-08-28 13:00:01',
    ],
]);

test('backdated reservations are signaled explicitly even when their provenance is legacy or human', function (
    string $source,
): void {
    $result = (new PastReservationOutcomeDecision)->decide(
        pastOutcomeDecisionReservation([
            'created_at' => Carbon::parse('2026-08-28 13:00:00', 'UTC'),
            'status_change_source' => $source,
            'status_changed_at' => Carbon::parse('2026-08-28 14:00:00', 'UTC'),
        ]),
        Carbon::parse('2026-08-28 16:00:00', 'UTC'),
        pastOutcomeDecisionSettings(),
    );

    expect($result)->toBe([
        'action' => PastReservationOutcomeDecision::ACTION_SIGNAL,
        'reason' => PastReservationOutcomeDecision::REASON_BACKDATED,
    ]);
})->with([
    'legacy provenance' => [Reservation::STATUS_CHANGE_SOURCE_LEGACY_UNKNOWN],
    'human provenance' => [Reservation::STATUS_CHANGE_SOURCE_STAFF_UI],
]);

test('legacy reservations are signaled without pretending their status history is known', function (): void {
    $result = (new PastReservationOutcomeDecision)->decide(
        pastOutcomeDecisionReservation([
            'status_change_source' => Reservation::STATUS_CHANGE_SOURCE_LEGACY_UNKNOWN,
            'status_changed_at' => null,
        ]),
        Carbon::parse('2026-08-28 16:00:00', 'UTC'),
        pastOutcomeDecisionSettings(),
    );

    expect($result)->toBe([
        'action' => PastReservationOutcomeDecision::ACTION_SIGNAL,
        'reason' => PastReservationOutcomeDecision::REASON_LEGACY_UNKNOWN,
    ]);
});

test('only reservations older than the tenant catch-up boundary are classified as stale backlog', function (
    string $endsAt,
    string $expectedReason,
): void {
    $now = Carbon::parse('2026-08-28 16:00:00', 'UTC');
    $end = Carbon::parse($endsAt, 'UTC');
    $result = (new PastReservationOutcomeDecision)->decide(
        pastOutcomeDecisionReservation([
            'starts_at' => $end->copy()->subHour(),
            'ends_at' => $end,
            'created_at' => $end->copy()->subDay(),
            'status_changed_at' => $end->copy()->subMinute(),
            'status_change_source' => Reservation::STATUS_CHANGE_SOURCE_API,
        ]),
        $now,
        pastOutcomeDecisionSettings(['past_reservation_max_catchup_days' => 7]),
    );

    expect($result)->toBe([
        'action' => PastReservationOutcomeDecision::ACTION_SIGNAL,
        'reason' => $expectedReason,
    ]);
})->with([
    'exactly at the seven-day catch-up boundary' => [
        '2026-08-21 16:00:00',
        PastReservationOutcomeDecision::REASON_OUTCOME_MISSING,
    ],
    'one second older than the catch-up boundary' => [
        '2026-08-21 15:59:59',
        PastReservationOutcomeDecision::REASON_STALE_BACKLOG,
    ],
]);
