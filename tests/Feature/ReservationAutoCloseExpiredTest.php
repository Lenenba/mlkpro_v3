<?php

use App\Models\Reservation;
use App\Models\ReservationQueueItem;
use App\Models\ReservationStatusTransition;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Reservation\ExpiredWalkInAutoCloser;
use App\Services\Reservation\ReservationQueueGraceNoShowMarker;
use App\Services\ReservationNotificationService;
use App\Services\ReservationQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    $settings = [
        'business_preset' => 'salon',
        'queue_mode_enabled' => true,
        'queue_no_show_on_grace_expiry' => true,
        'queue_dispatch_mode' => ReservationQueueService::DISPATCH_MODE_FIFO_WITH_APPOINTMENT_PRIORITY,
        'queue_assignment_mode' => ReservationQueueService::ASSIGNMENT_MODE_GLOBAL_PULL,
        'buffer_minutes' => 0,
    ];

    app(ReservationQueueService::class)->refreshMetrics($account->id, $settings);
    app(ReservationQueueService::class)->refreshMetrics($account->id, $settings);

    $audit = ReservationStatusTransition::query()->sole();

    expect($queueItem->refresh()->status)->toBe(ReservationQueueItem::STATUS_NO_SHOW)
        ->and($reservation->refresh()->status)->toBe(Reservation::STATUS_NO_SHOW)
        ->and($reservation->auto_closed_reason)->toBe(ReservationQueueGraceNoShowMarker::REASON)
        ->and($audit->actor_type)->toBe(ReservationStatusTransition::ACTOR_SYSTEM)
        ->and($audit->source)->toBe(Reservation::STATUS_CHANGE_SOURCE_QUEUE_GRACE)
        ->and($audit->reason_code)->toBe('queue_grace_expired')
        ->and(ReservationStatusTransition::query()->count())->toBe(1);
});

it('does not announce a missed turn when queue grace aligns with a human completion', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-05-15 08:00:00', 'UTC'));

    $account = autoCloseAccount();
    $teamMember = autoCloseTeamMember($account);
    $reservation = autoCloseReservation(
        $account,
        $teamMember,
        Carbon::parse('2026-05-15 08:30:00', 'America/Toronto'),
        ['status' => Reservation::STATUS_COMPLETED]
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
    $notifications = \Mockery::mock(ReservationNotificationService::class);
    $notifications->shouldReceive('handleQueueEvent')
        ->once()
        ->withArgs(fn (ReservationQueueItem $item, string $event): bool => (int) $item->id === (int) $queueItem->id
            && $event === 'queue_status_changed')
        ->andReturn(true);
    app()->instance(ReservationNotificationService::class, $notifications);

    app(ReservationQueueService::class)->refreshMetrics($account->id, [
        'business_preset' => 'salon',
        'queue_mode_enabled' => true,
        'queue_no_show_on_grace_expiry' => true,
        'queue_dispatch_mode' => ReservationQueueService::DISPATCH_MODE_FIFO_WITH_APPOINTMENT_PRIORITY,
        'queue_assignment_mode' => ReservationQueueService::ASSIGNMENT_MODE_GLOBAL_PULL,
        'buffer_minutes' => 0,
    ]);

    expect($queueItem->refresh()->status)->toBe(ReservationQueueItem::STATUS_DONE)
        ->and($reservation->refresh()->status)->toBe(Reservation::STATUS_COMPLETED);
});

it('defers multi-item refresh and notifications until the outer transaction commits', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-05-15 08:00:00', 'UTC'));

    $account = autoCloseAccount();
    $transitioned = ReservationQueueItem::query()->create([
        'account_id' => $account->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'checked_in_at' => now('UTC')->subMinutes(10),
    ]);
    $expired = ReservationQueueItem::query()->create([
        'account_id' => $account->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'status' => ReservationQueueItem::STATUS_CALLED,
        'called_at' => now('UTC')->subMinutes(7),
        'call_expires_at' => now('UTC')->subMinute(),
    ]);
    $notifications = \Mockery::mock(ReservationNotificationService::class);
    $notifications->shouldNotReceive('handleQueueEvent');
    app()->instance(ReservationNotificationService::class, $notifications);
    $queueService = app(ReservationQueueService::class);
    $settings = [
        'business_preset' => 'salon',
        'queue_mode_enabled' => true,
        'queue_no_show_on_grace_expiry' => false,
        'queue_dispatch_mode' => ReservationQueueService::DISPATCH_MODE_FIFO_WITH_APPOINTMENT_PRIORITY,
        'queue_assignment_mode' => ReservationQueueService::ASSIGNMENT_MODE_GLOBAL_PULL,
        'buffer_minutes' => 0,
    ];

    expect(fn () => DB::transaction(function () use ($queueService, $transitioned, $expired, $account, $settings): void {
        $queueService->transition($transitioned, 'skip', $account, $settings);

        expect($expired->refresh()->status)->toBe(ReservationQueueItem::STATUS_CALLED);

        throw new RuntimeException('rollback queue transition');
    }))->toThrow(RuntimeException::class, 'rollback queue transition');

    expect($transitioned->refresh()->status)->toBe(ReservationQueueItem::STATUS_CHECKED_IN)
        ->and($expired->refresh()->status)->toBe(ReservationQueueItem::STATUS_CALLED);
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

    $this->artisan('reservations:auto-close-expired-walk-ins')
        ->expectsOutputToContain('Auto-closed 1 expired walk-in ticket(s).')
        ->assertExitCode(0);

    $ticket->refresh();

    expect($ticket->status)->toBe(ReservationQueueItem::STATUS_NO_SHOW)
        ->and($ticket->finished_at)->not->toBeNull()
        ->and(data_get($ticket->metadata, 'auto_close.reason'))->toBe(ExpiredWalkInAutoCloser::EXPIRED_REASON)
        ->and(data_get($ticket->metadata, 'auto_close.previous_status'))->toBe(ReservationQueueItem::STATUS_SKIPPED)
        ->and(ReservationQueueItem::query()->active()->whereKey($ticket->id)->exists())->toBeFalse();

    $this->artisan('reservations:auto-close-expired-walk-ins')
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

    $this->artisan('reservations:auto-close-expired-walk-ins')
        ->expectsOutputToContain('Auto-closed 0 expired walk-in ticket(s).')
        ->assertExitCode(0);

    expect($ticket->refresh()->status)->toBe(ReservationQueueItem::STATUS_CHECKED_IN);

    Carbon::setTestNow(Carbon::parse('2026-05-15 04:30:00', 'UTC'));

    $this->artisan('reservations:auto-close-expired-walk-ins')
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

    $this->artisan('reservations:auto-close-expired-walk-ins')
        ->expectsOutputToContain('Auto-closed 0 expired walk-in ticket(s).')
        ->assertExitCode(0);

    expect($ticket->refresh()->status)->toBe(ReservationQueueItem::STATUS_IN_SERVICE)
        ->and($ticket->finished_at)->toBeNull();
});
