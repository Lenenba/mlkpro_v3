<?php

use App\Models\Reservation;
use App\Models\ReservationCheckIn;
use App\Models\ReservationQueueItem;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Reservation\ExpiredReservationAutoCloser;
use App\Services\ReservationQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

function autoCloseAccount(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'company_timezone' => 'America/Toronto',
        'company_features' => [
            'reservations' => true,
        ],
    ], $overrides));
}

function autoCloseTeamMember(User $account, array $overrides = []): TeamMember
{
    return TeamMember::factory()->create(array_merge([
        'account_id' => $account->id,
    ], $overrides));
}

function autoCloseReservation(User $account, TeamMember $teamMember, Carbon $startsAt, array $overrides = []): Reservation
{
    return Reservation::query()->create(array_merge([
        'account_id' => $account->id,
        'team_member_id' => $teamMember->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'source' => Reservation::SOURCE_CLIENT,
        'timezone' => $account->company_timezone ?: 'UTC',
        'starts_at' => $startsAt->copy()->utc(),
        'ends_at' => $startsAt->copy()->utc()->addHour(),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
        'metadata' => [],
    ], $overrides));
}

function autoCloseWalkInTicket(User $account, TeamMember $teamMember, Carbon $createdAt, array $overrides = []): ReservationQueueItem
{
    $ticket = ReservationQueueItem::query()->create(array_merge([
        'account_id' => $account->id,
        'team_member_id' => $teamMember->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'kiosk',
        'queue_number' => 'W-001',
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 45,
        'checked_in_at' => $createdAt->copy()->utc(),
        'metadata' => [],
    ], $overrides));

    $ticket->forceFill([
        'created_at' => $createdAt->copy()->utc(),
        'updated_at' => $createdAt->copy()->utc(),
    ])->save();

    return $ticket->fresh();
}

it('auto-closes a past active reservation as no-show the next local day', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-05-15 08:00:00', 'UTC'));

    $account = autoCloseAccount();
    $teamMember = autoCloseTeamMember($account);
    $reservation = autoCloseReservation(
        $account,
        $teamMember,
        Carbon::parse('2026-05-14 14:00:00', 'America/Toronto')
    );

    $this->artisan('reservations:auto-close-expired')
        ->expectsOutputToContain('Auto-closed 1 expired reservation(s).')
        ->assertExitCode(0);

    $reservation->refresh();

    expect($reservation->status)->toBe(Reservation::STATUS_NO_SHOW)
        ->and($reservation->auto_closed_at)->not->toBeNull()
        ->and($reservation->auto_closed_reason)->toBe(ExpiredReservationAutoCloser::EXPIRED_REASON)
        ->and(data_get($reservation->metadata, 'auto_close.reason'))->toBe(ExpiredReservationAutoCloser::EXPIRED_REASON)
        ->and(Reservation::query()->active()->whereKey($reservation->id)->exists())->toBeFalse();
});

it('does not change completed or cancelled past reservations', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-05-15 08:00:00', 'UTC'));

    $account = autoCloseAccount();
    $teamMember = autoCloseTeamMember($account);
    $startsAt = Carbon::parse('2026-05-14 09:00:00', 'America/Toronto');
    $completed = autoCloseReservation($account, $teamMember, $startsAt, [
        'status' => Reservation::STATUS_COMPLETED,
    ]);
    $cancelled = autoCloseReservation($account, $teamMember, $startsAt, [
        'status' => Reservation::STATUS_CANCELLED,
        'cancelled_at' => Carbon::parse('2026-05-14 08:00:00', 'America/Toronto')->utc(),
    ]);

    $this->artisan('reservations:auto-close-expired')
        ->expectsOutputToContain('Auto-closed 0 expired reservation(s).')
        ->assertExitCode(0);

    expect($completed->refresh()->status)->toBe(Reservation::STATUS_COMPLETED)
        ->and($completed->auto_closed_at)->toBeNull()
        ->and($cancelled->refresh()->status)->toBe(Reservation::STATUS_CANCELLED)
        ->and($cancelled->auto_closed_at)->toBeNull();
});

it('does not close today or future reservations', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-05-15 16:00:00', 'UTC'));

    $account = autoCloseAccount();
    $teamMember = autoCloseTeamMember($account);
    $today = autoCloseReservation(
        $account,
        $teamMember,
        Carbon::parse('2026-05-15 09:00:00', 'America/Toronto')
    );
    $future = autoCloseReservation(
        $account,
        $teamMember,
        Carbon::parse('2026-05-16 09:00:00', 'America/Toronto')
    );

    $this->artisan('reservations:auto-close-expired')
        ->expectsOutputToContain('Auto-closed 0 expired reservation(s).')
        ->assertExitCode(0);

    expect($today->refresh()->status)->toBe(Reservation::STATUS_CONFIRMED)
        ->and($future->refresh()->status)->toBe(Reservation::STATUS_CONFIRMED);
});

it('is idempotent once a stale reservation is auto-closed', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-05-15 08:00:00', 'UTC'));

    $account = autoCloseAccount();
    $teamMember = autoCloseTeamMember($account);
    $reservation = autoCloseReservation(
        $account,
        $teamMember,
        Carbon::parse('2026-05-14 10:00:00', 'America/Toronto')
    );

    $this->artisan('reservations:auto-close-expired')
        ->expectsOutputToContain('Auto-closed 1 expired reservation(s).')
        ->assertExitCode(0);
    $firstClosedAt = $reservation->refresh()->auto_closed_at?->toIso8601String();

    Carbon::setTestNow(Carbon::parse('2026-05-15 09:00:00', 'UTC'));

    $this->artisan('reservations:auto-close-expired')
        ->expectsOutputToContain('Auto-closed 0 expired reservation(s).')
        ->assertExitCode(0);

    expect($reservation->refresh()->status)->toBe(Reservation::STATUS_NO_SHOW)
        ->and($reservation->auto_closed_at?->toIso8601String())->toBe($firstClosedAt);
});

it('respects the reservation timezone before closing', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-05-15 03:30:00', 'UTC'));

    $account = autoCloseAccount(['company_timezone' => 'America/Toronto']);
    $teamMember = autoCloseTeamMember($account);
    $reservation = autoCloseReservation(
        $account,
        $teamMember,
        Carbon::parse('2026-05-14 09:00:00', 'America/Toronto')
    );

    $this->artisan('reservations:auto-close-expired')
        ->expectsOutputToContain('Auto-closed 0 expired reservation(s).')
        ->assertExitCode(0);

    expect($reservation->refresh()->status)->toBe(Reservation::STATUS_CONFIRMED);

    Carbon::setTestNow(Carbon::parse('2026-05-15 04:30:00', 'UTC'));

    $this->artisan('reservations:auto-close-expired')
        ->expectsOutputToContain('Auto-closed 1 expired reservation(s).')
        ->assertExitCode(0);

    expect($reservation->refresh()->status)->toBe(Reservation::STATUS_NO_SHOW);
});

it('does not auto-close reservations with check-in or active queue arrival state', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-05-15 08:00:00', 'UTC'));

    $account = autoCloseAccount();
    $teamMember = autoCloseTeamMember($account);
    $startsAt = Carbon::parse('2026-05-14 09:00:00', 'America/Toronto');
    $checkedInReservation = autoCloseReservation($account, $teamMember, $startsAt);
    $queuedReservation = autoCloseReservation($account, $teamMember, $startsAt);

    $checkedInQueueItem = ReservationQueueItem::query()->create([
        'account_id' => $account->id,
        'reservation_id' => $checkedInReservation->id,
        'team_member_id' => $teamMember->id,
        'item_type' => ReservationQueueItem::TYPE_APPOINTMENT,
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'checked_in_at' => Carbon::parse('2026-05-14 08:55:00', 'America/Toronto')->utc(),
    ]);
    ReservationCheckIn::query()->create([
        'account_id' => $account->id,
        'reservation_queue_item_id' => $checkedInQueueItem->id,
        'reservation_id' => $checkedInReservation->id,
        'checked_in_at' => Carbon::parse('2026-05-14 08:55:00', 'America/Toronto')->utc(),
        'channel' => 'kiosk',
    ]);
    ReservationQueueItem::query()->create([
        'account_id' => $account->id,
        'reservation_id' => $queuedReservation->id,
        'team_member_id' => $teamMember->id,
        'item_type' => ReservationQueueItem::TYPE_APPOINTMENT,
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'checked_in_at' => Carbon::parse('2026-05-14 08:55:00', 'America/Toronto')->utc(),
    ]);

    $this->artisan('reservations:auto-close-expired')
        ->expectsOutputToContain('Auto-closed 0 expired reservation(s).')
        ->assertExitCode(0);

    expect($checkedInReservation->refresh()->status)->toBe(Reservation::STATUS_CONFIRMED)
        ->and($queuedReservation->refresh()->status)->toBe(Reservation::STATUS_CONFIRMED);
});

it('syncs not-arrived queue appointments when a reservation is auto-closed', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-05-15 08:00:00', 'UTC'));

    $account = autoCloseAccount();
    $teamMember = autoCloseTeamMember($account);
    $reservation = autoCloseReservation(
        $account,
        $teamMember,
        Carbon::parse('2026-05-14 09:00:00', 'America/Toronto')
    );
    $queueItem = ReservationQueueItem::query()->create([
        'account_id' => $account->id,
        'reservation_id' => $reservation->id,
        'team_member_id' => $teamMember->id,
        'item_type' => ReservationQueueItem::TYPE_APPOINTMENT,
        'status' => ReservationQueueItem::STATUS_NOT_ARRIVED,
    ]);

    $this->artisan('reservations:auto-close-expired')
        ->expectsOutputToContain('Auto-closed 1 expired reservation(s).')
        ->assertExitCode(0);

    expect($reservation->refresh()->status)->toBe(Reservation::STATUS_NO_SHOW)
        ->and($queueItem->refresh()->status)->toBe(ReservationQueueItem::STATUS_NO_SHOW)
        ->and($queueItem->finished_at)->not->toBeNull();
});

it('syncs the reservation when queue grace expiry marks an appointment no-show', function (): void {
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-05-15 08:00:00', 'UTC'));

    $account = autoCloseAccount();
    $teamMember = autoCloseTeamMember($account);
    $reservation = autoCloseReservation(
        $account,
        $teamMember,
        Carbon::parse('2026-05-15 08:30:00', 'America/Toronto')
    );
    $queueItem = ReservationQueueItem::query()->create([
        'account_id' => $account->id,
        'reservation_id' => $reservation->id,
        'team_member_id' => $teamMember->id,
        'item_type' => ReservationQueueItem::TYPE_APPOINTMENT,
        'status' => ReservationQueueItem::STATUS_CALLED,
        'called_at' => Carbon::parse('2026-05-15 07:50:00', 'UTC'),
        'call_expires_at' => Carbon::parse('2026-05-15 07:55:00', 'UTC'),
    ]);

    app(ReservationQueueService::class)->refreshMetrics($account->id, [
        'business_preset' => 'salon',
        'queue_mode_enabled' => true,
        'queue_no_show_on_grace_expiry' => true,
        'queue_dispatch_mode' => ReservationQueueService::DISPATCH_MODE_FIFO_WITH_APPOINTMENT_PRIORITY,
        'queue_assignment_mode' => ReservationQueueService::ASSIGNMENT_MODE_GLOBAL_PULL,
        'buffer_minutes' => 0,
    ]);

    expect($queueItem->refresh()->status)->toBe(ReservationQueueItem::STATUS_NO_SHOW)
        ->and($reservation->refresh()->status)->toBe(Reservation::STATUS_NO_SHOW)
        ->and($reservation->auto_closed_reason)->toBe(ExpiredReservationAutoCloser::QUEUE_GRACE_REASON);
});

it('auto-closes a previous-day active walk-in ticket as no-show', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-05-15 08:00:00', 'UTC'));

    $account = autoCloseAccount();
    $teamMember = autoCloseTeamMember($account);
    $ticket = autoCloseWalkInTicket(
        $account,
        $teamMember,
        Carbon::parse('2026-05-14 10:00:00', 'America/Toronto'),
        [
            'status' => ReservationQueueItem::STATUS_SKIPPED,
            'called_at' => Carbon::parse('2026-05-14 10:20:00', 'America/Toronto')->utc(),
            'skipped_at' => Carbon::parse('2026-05-14 10:30:00', 'America/Toronto')->utc(),
        ]
    );

    $this->artisan('reservations:auto-close-expired')
        ->expectsOutputToContain('Auto-closed 1 expired walk-in ticket(s).')
        ->assertExitCode(0);

    $ticket->refresh();

    expect($ticket->status)->toBe(ReservationQueueItem::STATUS_NO_SHOW)
        ->and($ticket->finished_at)->not->toBeNull()
        ->and(data_get($ticket->metadata, 'auto_close.reason'))->toBe(ExpiredReservationAutoCloser::WALK_IN_EXPIRED_REASON)
        ->and(data_get($ticket->metadata, 'auto_close.previous_status'))->toBe(ReservationQueueItem::STATUS_SKIPPED)
        ->and(ReservationQueueItem::query()->active()->whereKey($ticket->id)->exists())->toBeFalse();

    $this->artisan('reservations:auto-close-expired')
        ->expectsOutputToContain('Auto-closed 0 expired walk-in ticket(s).')
        ->assertExitCode(0);
});

it('respects account timezone before auto-closing walk-in tickets', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-05-15 03:30:00', 'UTC'));

    $account = autoCloseAccount(['company_timezone' => 'America/Toronto']);
    $teamMember = autoCloseTeamMember($account);
    $ticket = autoCloseWalkInTicket(
        $account,
        $teamMember,
        Carbon::parse('2026-05-14 09:00:00', 'America/Toronto')
    );

    $this->artisan('reservations:auto-close-expired')
        ->expectsOutputToContain('Auto-closed 0 expired walk-in ticket(s).')
        ->assertExitCode(0);

    expect($ticket->refresh()->status)->toBe(ReservationQueueItem::STATUS_CHECKED_IN);

    Carbon::setTestNow(Carbon::parse('2026-05-15 04:30:00', 'UTC'));

    $this->artisan('reservations:auto-close-expired')
        ->expectsOutputToContain('Auto-closed 1 expired walk-in ticket(s).')
        ->assertExitCode(0);

    expect($ticket->refresh()->status)->toBe(ReservationQueueItem::STATUS_NO_SHOW);
});

it('does not mark an in-service stale walk-in as no-show', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-05-15 08:00:00', 'UTC'));

    $account = autoCloseAccount();
    $teamMember = autoCloseTeamMember($account);
    $ticket = autoCloseWalkInTicket(
        $account,
        $teamMember,
        Carbon::parse('2026-05-14 09:00:00', 'America/Toronto'),
        [
            'status' => ReservationQueueItem::STATUS_IN_SERVICE,
            'started_at' => Carbon::parse('2026-05-14 09:15:00', 'America/Toronto')->utc(),
        ]
    );

    $this->artisan('reservations:auto-close-expired')
        ->expectsOutputToContain('Auto-closed 0 expired walk-in ticket(s).')
        ->assertExitCode(0);

    expect($ticket->refresh()->status)->toBe(ReservationQueueItem::STATUS_IN_SERVICE)
        ->and($ticket->finished_at)->toBeNull();
});
