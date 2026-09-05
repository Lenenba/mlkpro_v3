<?php

use App\Jobs\CloseExpiredWalkInsForAccountJob;
use App\Models\Reservation;
use App\Models\ReservationQueueItem;
use App\Models\ReservationSetting;
use App\Models\ReservationStatusTransition;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('eager loads both sides of the reservation audit relationship', function (): void {
    $account = User::factory()->create();
    $reservation = Reservation::factory()->create(['account_id' => $account->id]);
    $transition = ReservationStatusTransition::factory()->create([
        'account_id' => $account->id,
        'reservation_id' => $reservation->id,
    ]);

    $reservationWithAudit = Reservation::query()->with('statusTransitions')->findOrFail($reservation->id);
    $transitionWithReservation = ReservationStatusTransition::query()->with('reservation')->findOrFail($transition->id);

    expect($reservationWithAudit->statusTransitions)->toHaveCount(1)
        ->and($reservationWithAudit->statusTransitions->sole()->id)->toBe($transition->id)
        ->and($transitionWithReservation->reservation?->id)->toBe($reservation->id);
});

it('rejects an audit record whose reservation belongs to another tenant', function (): void {
    $account = User::factory()->create();
    $otherAccount = User::factory()->create();
    $reservation = Reservation::factory()->create(['account_id' => $account->id]);

    expect(fn () => ReservationStatusTransition::factory()->create([
        'account_id' => $otherAccount->id,
        'reservation_id' => $reservation->id,
    ]))->toThrow(\LogicException::class, 'tenant does not match');

    expect(ReservationStatusTransition::query()->exists())->toBeFalse();
});

it('selects one canonical account setting while retaining legacy duplicates', function (): void {
    $account = User::factory()->create();
    $canonical = ReservationSetting::factory()->create([
        'account_id' => $account->id,
        'past_reservation_reconciliation_enabled' => true,
    ]);
    $legacy = ReservationSetting::factory()->make([
        'account_id' => $account->id,
        'past_reservation_reconciliation_enabled' => false,
    ])->getAttributes();
    DB::table((new ReservationSetting)->getTable())->insert([
        ...$legacy,
        'account_default_marker' => null,
        'created_at' => now(),
        'updated_at' => now()->addSecond(),
    ]);

    $resolved = ReservationSetting::query()
        ->forAccount((int) $account->id)
        ->accountDefault()
        ->sole();

    expect($resolved->id)->toBe($canonical->id)
        ->and($resolved->past_reservation_reconciliation_enabled)->toBeTrue()
        ->and(ReservationSetting::query()->forAccount((int) $account->id)->count())->toBe(2)
        ->and(Schema::hasIndex('reservation_settings', 'rs_account_default_unique'))->toBeTrue();

    expect(fn () => DB::table((new ReservationSetting)->getTable())->insert([
        ...$legacy,
        'account_default_marker' => ReservationSetting::ACCOUNT_DEFAULT_MARKER,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('dispatches walk-in cleanup once per candidate tenant and ignores in-service tickets', function (): void {
    $this->travelTo(Carbon::parse('2026-05-15 12:00:00', 'UTC'));
    Bus::fake([CloseExpiredWalkInsForAccountJob::class]);
    $candidateAccountIds = [];

    foreach ([ReservationQueueItem::STATUS_CHECKED_IN, ReservationQueueItem::STATUS_SKIPPED] as $status) {
        $account = User::factory()->create(['company_timezone' => 'UTC']);
        $member = TeamMember::factory()->create(['account_id' => $account->id]);
        $ticket = ReservationQueueItem::query()->create([
            'account_id' => $account->id,
            'team_member_id' => $member->id,
            'item_type' => ReservationQueueItem::TYPE_TICKET,
            'status' => $status,
        ]);
        $ticket->forceFill([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ])->saveQuietly();
        $candidateAccountIds[] = (int) $account->id;
    }

    $inServiceAccount = User::factory()->create(['company_timezone' => 'UTC']);
    $inServiceMember = TeamMember::factory()->create(['account_id' => $inServiceAccount->id]);
    $inServiceTicket = ReservationQueueItem::query()->create([
        'account_id' => $inServiceAccount->id,
        'team_member_id' => $inServiceMember->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'status' => ReservationQueueItem::STATUS_IN_SERVICE,
    ]);
    $inServiceTicket->forceFill([
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ])->saveQuietly();

    $this->artisan('reservations:auto-close-expired-walk-ins --dispatch')
        ->expectsOutputToContain('Dispatched expired walk-in cleanup for 2 account(s).')
        ->assertExitCode(0);

    Bus::assertDispatchedTimes(CloseExpiredWalkInsForAccountJob::class, 2);
    foreach ($candidateAccountIds as $candidateAccountId) {
        Bus::assertDispatched(
            CloseExpiredWalkInsForAccountJob::class,
            fn (CloseExpiredWalkInsForAccountJob $job): bool => $job->accountId === $candidateAccountId
        );
    }
    Bus::assertNotDispatched(
        CloseExpiredWalkInsForAccountJob::class,
        fn (CloseExpiredWalkInsForAccountJob $job): bool => $job->accountId === (int) $inServiceAccount->id
    );

    expect(Schema::hasIndex('reservation_queue_items', 'rqi_walk_in_dispatch_idx'))->toBeTrue();
});
