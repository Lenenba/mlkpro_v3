<?php

use App\Jobs\ReconcilePastReservationsForAccountJob;
use App\Models\Reservation;
use App\Models\ReservationCheckIn;
use App\Models\ReservationQueueItem;
use App\Models\ReservationSetting;
use App\Models\ReservationStatusTransition;
use App\Models\TeamMember;
use App\Models\User;
use App\Queries\Reservations\BuildStaffReservationDetailData;
use App\Queries\Reservations\BuildStaffReservationIndexData;
use App\Services\Reservation\PastReservationOutcomeDecision;
use App\Services\Reservation\PastReservationOutcomeReconciler;
use App\Services\Reservation\ReservationStatusTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

function pastOutcomeAccount(bool $enabled = true): User
{
    $account = User::factory()->create([
        'company_timezone' => 'America/Toronto',
        'company_features' => ['reservations' => true],
    ]);

    ReservationSetting::factory()->create([
        'account_id' => $account->id,
        'team_member_id' => null,
        'past_reservation_reconciliation_enabled' => $enabled,
        'past_reservation_reconciliation_mode' => ReservationSetting::PAST_RECONCILIATION_MODE_SIGNAL_ONLY,
        'past_reservation_grace_minutes' => 120,
        'past_reservation_max_catchup_days' => 7,
    ]);

    return $account;
}

function pastOutcomeTeamMember(User $account): TeamMember
{
    return TeamMember::factory()->create([
        'account_id' => $account->id,
    ]);
}

function pastOutcomeReservation(User $account, TeamMember $teamMember, array $overrides = []): Reservation
{
    $startsAt = Carbon::parse('2026-08-27 14:00:00', 'UTC');
    $reservation = Reservation::query()->create(array_merge([
        'account_id' => $account->id,
        'team_member_id' => $teamMember->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'status_version' => 0,
        'schedule_version' => 0,
        'status_change_source' => Reservation::STATUS_CHANGE_SOURCE_LEGACY_UNKNOWN,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'America/Toronto',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
        'metadata' => [
            'payment_state' => [
                'deposit_status' => 'required',
                'no_show_fee_status' => 'not_applied',
            ],
        ],
    ], $overrides));

    $reservation->forceFill([
        'created_at' => $reservation->starts_at->copy()->subDay(),
        'updated_at' => $reservation->starts_at->copy()->subDay(),
    ])->saveQuietly();

    return $reservation->fresh();
}

function pastOutcomeSettings(array $overrides = []): array
{
    return array_merge([
        'past_reservation_reconciliation_enabled' => true,
        'past_reservation_reconciliation_mode' => ReservationSetting::PAST_RECONCILIATION_MODE_SIGNAL_ONLY,
        'past_reservation_grace_minutes' => 120,
        'past_reservation_max_catchup_days' => 7,
    ], $overrides);
}

it('signals only reservations from tenants that explicitly opted in', function (): void {
    $now = Carbon::parse('2026-08-28 12:00:00', 'UTC');
    $enabledAccount = pastOutcomeAccount();
    $disabledAccount = pastOutcomeAccount(false);
    $enabledReservation = pastOutcomeReservation($enabledAccount, pastOutcomeTeamMember($enabledAccount));
    $disabledReservation = pastOutcomeReservation($disabledAccount, pastOutcomeTeamMember($disabledAccount));

    $enabledSummary = app(PastReservationOutcomeReconciler::class)->reconcile((int) $enabledAccount->id, false, $now);
    $disabledSummary = app(PastReservationOutcomeReconciler::class)->reconcile((int) $disabledAccount->id, false, $now);

    expect($enabledSummary)->toMatchArray(['enabled' => true, 'eligible' => 1, 'signaled' => 1])
        ->and($disabledSummary)->toMatchArray(['enabled' => false, 'eligible' => 0, 'signaled' => 0])
        ->and($enabledReservation->refresh()->status)->toBe(Reservation::STATUS_CONFIRMED)
        ->and($enabledReservation->outcome_review_required_at)->not->toBeNull()
        ->and($disabledReservation->refresh()->status)->toBe(Reservation::STATUS_CONFIRMED)
        ->and($disabledReservation->outcome_review_required_at)->toBeNull()
        ->and(ReservationStatusTransition::query()->where('account_id', $disabledAccount->id)->exists())->toBeFalse();
});

it('is signal-only and leaves queue payment and previous auto-close data intact', function (): void {
    $now = Carbon::parse('2026-08-28 12:00:00', 'UTC');
    $account = pastOutcomeAccount();
    $teamMember = pastOutcomeTeamMember($account);
    $reservation = pastOutcomeReservation($account, $teamMember, [
        'auto_closed_at' => Carbon::parse('2026-08-20 12:00:00', 'UTC'),
        'auto_closed_reason' => 'legacy-marker',
    ]);
    $queueItem = ReservationQueueItem::query()->create([
        'account_id' => $account->id,
        'reservation_id' => $reservation->id,
        'team_member_id' => $teamMember->id,
        'item_type' => ReservationQueueItem::TYPE_APPOINTMENT,
        'status' => ReservationQueueItem::STATUS_NOT_ARRIVED,
    ]);
    $metadataBefore = $reservation->metadata;

    app(PastReservationOutcomeReconciler::class)->reconcile((int) $account->id, false, $now);

    $reservation->refresh();
    expect($reservation->status)->toBe(Reservation::STATUS_CONFIRMED)
        ->and($reservation->auto_closed_reason)->toBe('legacy-marker')
        ->and($reservation->auto_closed_at?->toIso8601String())->toBe('2026-08-20T12:00:00+00:00')
        ->and($reservation->metadata)->toBe($metadataBefore)
        ->and(data_get($reservation->metadata, 'payment_state.deposit_status'))->toBe('required')
        ->and(data_get($reservation->metadata, 'payment_state.no_show_fee_status'))->toBe('not_applied')
        ->and($queueItem->refresh()->status)->toBe(ReservationQueueItem::STATUS_NOT_ARRIVED)
        ->and($queueItem->finished_at)->toBeNull();
});

it('records one stable system audit event when reconciliation is replayed', function (): void {
    $now = Carbon::parse('2026-08-28 12:00:00', 'UTC');
    $account = pastOutcomeAccount();
    $reservation = pastOutcomeReservation($account, pastOutcomeTeamMember($account));
    $reconciler = app(PastReservationOutcomeReconciler::class);

    $first = $reconciler->reconcile((int) $account->id, false, $now);
    $firstFlaggedAt = $reservation->refresh()->outcome_review_required_at?->toIso8601String();
    $second = $reconciler->reconcile((int) $account->id, false, $now->copy()->addMinute());
    $transition = ReservationStatusTransition::query()->sole();

    expect($first)->toMatchArray(['eligible' => 1, 'signaled' => 1])
        ->and($second)->toMatchArray(['eligible' => 0, 'signaled' => 0])
        ->and($reservation->refresh()->outcome_review_required_at?->toIso8601String())->toBe($firstFlaggedAt)
        ->and(ReservationStatusTransition::query()->count())->toBe(1)
        ->and($transition->account_id)->toBe($account->id)
        ->and($transition->reservation_id)->toBe($reservation->id)
        ->and($transition->event_type)->toBe(ReservationStatusTransition::EVENT_OUTCOME_REVIEW_REQUESTED)
        ->and($transition->actor_type)->toBe(ReservationStatusTransition::ACTOR_SYSTEM)
        ->and($transition->actor_user_id)->toBeNull()
        ->and($transition->source)->toBe(PastReservationOutcomeReconciler::SOURCE)
        ->and($transition->reason_code)->toBe(PastReservationOutcomeDecision::REASON_LEGACY_UNKNOWN)
        ->and($transition->from_status)->toBe(Reservation::STATUS_CONFIRMED)
        ->and($transition->to_status)->toBe(Reservation::STATUS_CONFIRMED)
        ->and($transition->idempotency_key)->toHaveLength(64);
});

it('cannot reconcile a reservation through another tenant', function (): void {
    $account = pastOutcomeAccount();
    $otherAccount = pastOutcomeAccount();
    $otherReservation = pastOutcomeReservation($otherAccount, pastOutcomeTeamMember($otherAccount));

    $result = app(PastReservationOutcomeReconciler::class)->reconcileCandidate(
        (int) $account->id,
        (int) $otherReservation->id,
        (int) $otherReservation->status_version,
        (int) $otherReservation->schedule_version,
        (int) $otherReservation->mutation_version,
        pastOutcomeSettings(),
        false,
        Carbon::parse('2026-08-28 12:00:00', 'UTC')
    );

    expect($result)->toBe([
        'eligible' => false,
        'signaled' => false,
        'reason' => 'tenant_or_reservation_mismatch',
    ])->and($otherReservation->refresh()->outcome_review_required_at)->toBeNull()
        ->and(ReservationStatusTransition::query()->exists())->toBeFalse();
});

it('rejects human status actors that do not belong to the reservation tenant', function (): void {
    $account = pastOutcomeAccount();
    $otherAccount = pastOutcomeAccount();
    $reservation = pastOutcomeReservation($account, pastOutcomeTeamMember($account));

    expect(fn () => app(ReservationStatusTransitionService::class)->transition(
        $reservation,
        Reservation::STATUS_COMPLETED,
        ReservationStatusTransition::ACTOR_USER,
        $otherAccount,
        Reservation::STATUS_CHANGE_SOURCE_STAFF_UI,
        'manual_completion'
    ))->toThrow(InvalidArgumentException::class, 'does not belong to the tenant');

    expect($reservation->refresh()->status)->toBe(Reservation::STATUS_CONFIRMED)
        ->and(ReservationStatusTransition::query()->exists())->toBeFalse();
});

it('rejects semantic idempotency collisions instead of silently skipping another reservation', function (): void {
    $account = pastOutcomeAccount();
    $teamMember = pastOutcomeTeamMember($account);
    $firstReservation = pastOutcomeReservation($account, $teamMember);
    $secondReservation = pastOutcomeReservation($account, $teamMember);
    $idempotencyKey = hash('sha256', 'shared-test-key');
    $transitions = app(ReservationStatusTransitionService::class);

    $transitions->transition(
        $firstReservation,
        Reservation::STATUS_COMPLETED,
        ReservationStatusTransition::ACTOR_USER,
        $account,
        Reservation::STATUS_CHANGE_SOURCE_STAFF_UI,
        'manual_completion',
        idempotencyKey: $idempotencyKey
    );

    expect(fn () => $transitions->transition(
        $secondReservation,
        Reservation::STATUS_COMPLETED,
        ReservationStatusTransition::ACTOR_USER,
        $account,
        Reservation::STATUS_CHANGE_SOURCE_STAFF_UI,
        'manual_completion',
        idempotencyKey: $idempotencyKey
    ))->toThrow(LogicException::class, 'idempotency key collision');

    expect($secondReservation->refresh()->status)->toBe(Reservation::STATUS_CONFIRMED)
        ->and(ReservationStatusTransition::query()->count())->toBe(1);
});

it('keeps reservation audit records immutable', function (): void {
    $account = pastOutcomeAccount();
    pastOutcomeReservation($account, pastOutcomeTeamMember($account));
    app(PastReservationOutcomeReconciler::class)->reconcile(
        (int) $account->id,
        false,
        Carbon::parse('2026-08-28 12:00:00', 'UTC')
    );
    $transition = ReservationStatusTransition::query()->sole();

    expect(fn () => $transition->update(['reason' => 'tampered']))
        ->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $transition->delete())
        ->toThrow(LogicException::class, 'cannot be deleted')
        ->and($transition->fresh()->reason)->not->toBe('tampered');
});

it('retains the audit trail when the reservation is physically deleted', function (): void {
    $account = pastOutcomeAccount();
    $reservation = pastOutcomeReservation($account, pastOutcomeTeamMember($account));
    app(PastReservationOutcomeReconciler::class)->reconcile(
        (int) $account->id,
        false,
        Carbon::parse('2026-08-28 12:00:00', 'UTC')
    );
    $transitionId = ReservationStatusTransition::query()->sole()->id;

    $reservation->delete();

    expect(Reservation::query()->whereKey($reservation->id)->exists())->toBeFalse()
        ->and(ReservationStatusTransition::query()->whereKey($transitionId)->exists())->toBeTrue()
        ->and(ReservationStatusTransition::query()->findOrFail($transitionId)->reservation)->toBeNull();
});

it('treats an explicit same-status staff action as a deliberate human reaffirmation', function (): void {
    $account = pastOutcomeAccount();
    $reservation = pastOutcomeReservation($account, pastOutcomeTeamMember($account));
    app(PastReservationOutcomeReconciler::class)->reconcile(
        (int) $account->id,
        false,
        Carbon::parse('2026-08-28 12:00:00', 'UTC')
    );
    $reservation->refresh();

    $result = app(ReservationStatusTransitionService::class)->transition(
        $reservation,
        Reservation::STATUS_CONFIRMED,
        ReservationStatusTransition::ACTOR_USER,
        $account,
        Reservation::STATUS_CHANGE_SOURCE_STAFF_UI,
        'manual_status_update',
        recordSameStatus: true,
        expectedStatusVersion: (int) $reservation->status_version,
        expectedScheduleVersion: (int) $reservation->schedule_version,
        occurredAt: Carbon::parse('2026-08-28 12:05:00', 'UTC')
    );
    $followUp = app(PastReservationOutcomeReconciler::class)->reconcile(
        (int) $account->id,
        false,
        Carbon::parse('2026-08-28 12:10:00', 'UTC')
    );

    expect($result->performed)->toBeTrue()
        ->and($result->previousStatus)->toBe(Reservation::STATUS_CONFIRMED)
        ->and($result->reservation->status)->toBe(Reservation::STATUS_CONFIRMED)
        ->and($result->reservation->outcome_review_required_at)->toBeNull()
        ->and($result->reservation->status_change_source)->toBe(Reservation::STATUS_CHANGE_SOURCE_STAFF_UI)
        ->and(ReservationStatusTransition::query()
            ->where('event_type', ReservationStatusTransition::EVENT_STATUS_REAFFIRMED)
            ->count())->toBe(1)
        ->and($followUp['checked'])->toBe(0);
});

it('does not mistake a notes-only edit for a human outcome decision', function (): void {
    $account = pastOutcomeAccount();
    $reservation = pastOutcomeReservation($account, pastOutcomeTeamMember($account));
    app(PastReservationOutcomeReconciler::class)->reconcile(
        (int) $account->id,
        false,
        Carbon::parse('2026-08-28 12:00:00', 'UTC')
    );
    $reservation->refresh();
    $reviewRequiredAt = $reservation->outcome_review_required_at?->toIso8601String();
    $scheduleVersion = (int) $reservation->schedule_version;

    $result = app(ReservationStatusTransitionService::class)->reschedule(
        $reservation,
        ['internal_notes' => 'Staff added context without deciding the outcome.'],
        $account,
        Reservation::STATUS_CHANGE_SOURCE_STAFF_UI,
        expectedStatusVersion: (int) $reservation->status_version,
        expectedScheduleVersion: $scheduleVersion
    );

    expect($result->performed)->toBeTrue()
        ->and($result->reservation->internal_notes)->toBe('Staff added context without deciding the outcome.')
        ->and($result->reservation->outcome_review_required_at?->toIso8601String())->toBe($reviewRequiredAt)
        ->and($result->reservation->schedule_version)->toBe($scheduleVersion)
        ->and(ReservationStatusTransition::query()->count())->toBe(1);
});

it('never overwrites a human status decision made after candidate discovery', function (): void {
    $now = Carbon::parse('2026-08-28 12:00:00', 'UTC');
    $account = pastOutcomeAccount();
    $reservation = pastOutcomeReservation($account, pastOutcomeTeamMember($account));
    $expectedStatusVersion = (int) $reservation->status_version;
    $expectedScheduleVersion = (int) $reservation->schedule_version;
    $expectedMutationVersion = (int) $reservation->mutation_version;

    app(ReservationStatusTransitionService::class)->transition(
        $reservation,
        Reservation::STATUS_COMPLETED,
        ReservationStatusTransition::ACTOR_USER,
        $account,
        Reservation::STATUS_CHANGE_SOURCE_STAFF_UI,
        'manual_completion',
        'Service completed by staff.',
        occurredAt: $now->copy()->subMinute()
    );

    $result = app(PastReservationOutcomeReconciler::class)->reconcileCandidate(
        (int) $account->id,
        (int) $reservation->id,
        $expectedStatusVersion,
        $expectedScheduleVersion,
        $expectedMutationVersion,
        pastOutcomeSettings(),
        false,
        $now
    );

    expect($result['reason'])->toBe('version_changed')
        ->and($reservation->refresh()->status)->toBe(Reservation::STATUS_COMPLETED)
        ->and($reservation->outcome_review_required_at)->toBeNull()
        ->and(ReservationStatusTransition::query()
            ->where('event_type', ReservationStatusTransition::EVENT_OUTCOME_REVIEW_REQUESTED)
            ->exists())->toBeFalse();
});

it('rejects a stale automatic status transition after a human reaffirmation', function (): void {
    $account = pastOutcomeAccount();
    $reservation = pastOutcomeReservation($account, pastOutcomeTeamMember($account));
    $expectedStatusVersion = (int) $reservation->status_version;
    $expectedScheduleVersion = (int) $reservation->schedule_version;
    $expectedMutationVersion = (int) $reservation->mutation_version;
    $transitions = app(ReservationStatusTransitionService::class);

    $transitions->transition(
        $reservation,
        Reservation::STATUS_CONFIRMED,
        ReservationStatusTransition::ACTOR_USER,
        $account,
        Reservation::STATUS_CHANGE_SOURCE_STAFF_UI,
        'manual_status_update',
        recordSameStatus: true
    );
    $automatic = $transitions->transition(
        $reservation,
        Reservation::STATUS_NO_SHOW,
        ReservationStatusTransition::ACTOR_SYSTEM,
        null,
        Reservation::STATUS_CHANGE_SOURCE_QUEUE_GRACE,
        'queue_grace_expired',
        allowedFromStatuses: Reservation::ACTIVE_STATUSES,
        expectedStatusVersion: $expectedStatusVersion,
        expectedScheduleVersion: $expectedScheduleVersion
    );

    expect($automatic->performed)->toBeFalse()
        ->and($automatic->reservation->status)->toBe(Reservation::STATUS_CONFIRMED)
        ->and($automatic->reservation->status_change_source)->toBe(Reservation::STATUS_CHANGE_SOURCE_STAFF_UI)
        ->and(ReservationStatusTransition::query()->count())->toBe(1);
});

it('never flags a reservation rescheduled after candidate discovery', function (): void {
    $now = Carbon::parse('2026-08-28 12:00:00', 'UTC');
    Carbon::setTestNow($now);
    $account = pastOutcomeAccount();
    $reservation = pastOutcomeReservation($account, pastOutcomeTeamMember($account));
    $expectedStatusVersion = (int) $reservation->status_version;
    $expectedScheduleVersion = (int) $reservation->schedule_version;
    $expectedMutationVersion = (int) $reservation->mutation_version;

    app(ReservationStatusTransitionService::class)->reschedule(
        $reservation,
        [
            'starts_at' => $now->copy()->addDay(),
            'ends_at' => $now->copy()->addDay()->addHour(),
        ],
        $account,
        Reservation::STATUS_CHANGE_SOURCE_STAFF_UI,
        occurredAt: $now->copy()->subMinute()
    );

    $result = app(PastReservationOutcomeReconciler::class)->reconcileCandidate(
        (int) $account->id,
        (int) $reservation->id,
        $expectedStatusVersion,
        $expectedScheduleVersion,
        $expectedMutationVersion,
        pastOutcomeSettings(),
        false,
        $now
    );

    expect($result['reason'])->toBe('version_changed')
        ->and($reservation->refresh()->ends_at->isFuture())->toBeTrue()
        ->and($reservation->outcome_review_required_at)->toBeNull()
        ->and(ReservationStatusTransition::query()
            ->where('event_type', ReservationStatusTransition::EVENT_OUTCOME_REVIEW_REQUESTED)
            ->exists())->toBeFalse();
});

it('signals presence evidence for human review without mutating the queue or check-in', function (): void {
    $now = Carbon::parse('2026-08-28 12:00:00', 'UTC');
    $account = pastOutcomeAccount();
    $teamMember = pastOutcomeTeamMember($account);
    $reservation = pastOutcomeReservation($account, $teamMember);
    $queueItem = ReservationQueueItem::query()->create([
        'account_id' => $account->id,
        'reservation_id' => $reservation->id,
        'team_member_id' => $teamMember->id,
        'item_type' => ReservationQueueItem::TYPE_APPOINTMENT,
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'checked_in_at' => $reservation->starts_at,
    ]);
    $checkIn = ReservationCheckIn::query()->create([
        'account_id' => $account->id,
        'reservation_queue_item_id' => $queueItem->id,
        'reservation_id' => $reservation->id,
        'checked_in_at' => $reservation->starts_at,
        'channel' => 'kiosk',
    ]);

    app(PastReservationOutcomeReconciler::class)->reconcile((int) $account->id, false, $now);

    expect($reservation->refresh()->status)->toBe(Reservation::STATUS_CONFIRMED)
        ->and($reservation->outcome_review_reason_code)->toBe(PastReservationOutcomeDecision::REASON_PRESENCE_EVIDENCE)
        ->and($queueItem->refresh()->status)->toBe(ReservationQueueItem::STATUS_CHECKED_IN)
        ->and($checkIn->refresh()->checked_in_at)->not->toBeNull();
});

it('reports dry-run eligibility without writing any projection or audit event', function (): void {
    $now = Carbon::parse('2026-08-28 12:00:00', 'UTC');
    $account = pastOutcomeAccount();
    $reservation = pastOutcomeReservation($account, pastOutcomeTeamMember($account));

    $summary = app(PastReservationOutcomeReconciler::class)->reconcile((int) $account->id, true, $now);

    expect($summary)->toMatchArray(['dry_run' => true, 'eligible' => 1, 'signaled' => 0])
        ->and($reservation->refresh()->outcome_review_required_at)->toBeNull()
        ->and(ReservationStatusTransition::query()->exists())->toBeFalse();
});

it('exposes the internal review signal in staff calendar and detail read models', function (): void {
    $now = Carbon::parse('2026-08-28 12:00:00', 'UTC');
    $account = pastOutcomeAccount();
    $reservation = pastOutcomeReservation($account, pastOutcomeTeamMember($account));
    app(PastReservationOutcomeReconciler::class)->reconcile((int) $account->id, false, $now);

    $events = app(BuildStaffReservationIndexData::class)->events(
        (int) $account->id,
        [
            'can_view_all' => true,
            'can_manage' => true,
            'can_update_status' => true,
            'own_team_member_id' => null,
        ],
        Request::create('/reservations/events', 'GET'),
        [
            'start' => '2026-08-27T00:00:00Z',
            'end' => '2026-08-29T00:00:00Z',
        ],
        'America/Toronto'
    );
    $detail = app(BuildStaffReservationDetailData::class)->build(
        $reservation->fresh(),
        $account,
        $account,
        false
    );

    expect(data_get($events, '0.extendedProps.outcome_review_required_at'))->not->toBeNull()
        ->and(data_get($events, '0.extendedProps.outcome_review_reason_code'))
        ->toBe(PastReservationOutcomeDecision::REASON_LEGACY_UNKNOWN)
        ->and($detail['outcome_review_required_at'])->not->toBeNull()
        ->and($detail['outcome_review_reason_code'])->toBe(PastReservationOutcomeDecision::REASON_LEGACY_UNKNOWN);
});

it('dispatches one tenant job only for enabled account settings', function (): void {
    Bus::fake();
    $enabledAccount = pastOutcomeAccount();
    $disabledAccount = pastOutcomeAccount(false);

    $this->artisan('reservations:reconcile-past')
        ->expectsOutput('Dispatched past reservation reconciliation for 1 account(s).')
        ->assertExitCode(0);

    Bus::assertDispatchedTimes(ReconcilePastReservationsForAccountJob::class, 1);
    Bus::assertDispatched(
        ReconcilePastReservationsForAccountJob::class,
        fn (ReconcilePastReservationsForAccountJob $job): bool => $job->accountId === $enabledAccount->id
    );
    Bus::assertNotDispatched(
        ReconcilePastReservationsForAccountJob::class,
        fn (ReconcilePastReservationsForAccountJob $job): bool => $job->accountId === $disabledAccount->id
    );
});

it('rejects invalid tenant command options without dispatching work', function (): void {
    Bus::fake();

    $this->artisan('reservations:reconcile-past', ['--account_id' => 'invalid'])
        ->expectsOutput('The --account_id option must be a positive integer.')
        ->assertExitCode(1);

    Bus::assertNothingDispatched();
});

it('persists and exposes reconciliation settings only for the authenticated tenant', function (): void {
    $account = pastOutcomeAccount(false);
    $otherAccount = pastOutcomeAccount(false);
    ReservationSetting::query()
        ->forAccount((int) $account->id)
        ->accountDefault()
        ->update(['account_default_marker' => null]);

    $this->actingAs($account)
        ->withSession(['two_factor_passed' => true])
        ->putJson(route('settings.reservations.update'), [
            'account_settings' => [
                'past_reservation_reconciliation_enabled' => true,
                'past_reservation_reconciliation_mode' => ReservationSetting::PAST_RECONCILIATION_MODE_SIGNAL_ONLY,
                'past_reservation_grace_minutes' => 180,
                'past_reservation_max_catchup_days' => 21,
            ],
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Reservation settings saved.');

    $stored = ReservationSetting::query()
        ->forAccount((int) $account->id)
        ->accountDefault()
        ->sole();
    $otherStored = ReservationSetting::query()
        ->forAccount((int) $otherAccount->id)
        ->whereNull('team_member_id')
        ->sole();

    expect($stored->past_reservation_reconciliation_enabled)->toBeTrue()
        ->and($stored->past_reservation_reconciliation_mode)
        ->toBe(ReservationSetting::PAST_RECONCILIATION_MODE_SIGNAL_ONLY)
        ->and($stored->past_reservation_grace_minutes)->toBe(180)
        ->and($stored->past_reservation_max_catchup_days)->toBe(21)
        ->and(ReservationSetting::query()->forAccount((int) $account->id)->count())->toBe(2)
        ->and($otherStored->past_reservation_reconciliation_enabled)->toBeFalse()
        ->and($otherStored->past_reservation_grace_minutes)->toBe(120)
        ->and($otherStored->past_reservation_max_catchup_days)->toBe(7);

    $this->actingAs($account)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('settings.reservations.edit'))
        ->assertOk()
        ->assertJsonPath('accountSettings.past_reservation_reconciliation_enabled', true)
        ->assertJsonPath(
            'accountSettings.past_reservation_reconciliation_mode',
            ReservationSetting::PAST_RECONCILIATION_MODE_SIGNAL_ONLY
        )
        ->assertJsonPath('accountSettings.past_reservation_grace_minutes', 180)
        ->assertJsonPath('accountSettings.past_reservation_max_catchup_days', 21);
});

it('rejects unsafe reconciliation settings without changing the tenant configuration', function (): void {
    $account = pastOutcomeAccount();

    $this->actingAs($account)
        ->withSession(['two_factor_passed' => true])
        ->putJson(route('settings.reservations.update'), [
            'account_settings' => [
                'past_reservation_reconciliation_enabled' => 'yes',
                'past_reservation_reconciliation_mode' => 'auto_cancel',
                'past_reservation_grace_minutes' => 10081,
                'past_reservation_max_catchup_days' => 366,
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'account_settings.past_reservation_reconciliation_enabled',
            'account_settings.past_reservation_reconciliation_mode',
            'account_settings.past_reservation_grace_minutes',
            'account_settings.past_reservation_max_catchup_days',
        ]);

    $stored = ReservationSetting::query()
        ->forAccount((int) $account->id)
        ->whereNull('team_member_id')
        ->sole();

    expect($stored->past_reservation_reconciliation_enabled)->toBeTrue()
        ->and($stored->past_reservation_reconciliation_mode)
        ->toBe(ReservationSetting::PAST_RECONCILIATION_MODE_SIGNAL_ONLY)
        ->and($stored->past_reservation_grace_minutes)->toBe(120)
        ->and($stored->past_reservation_max_catchup_days)->toBe(7);
});

it('preserves reconciliation settings when an older partial settings payload omits them', function (): void {
    $account = pastOutcomeAccount();
    ReservationSetting::query()
        ->forAccount((int) $account->id)
        ->accountDefault()
        ->update([
            'past_reservation_grace_minutes' => 240,
            'past_reservation_max_catchup_days' => 30,
        ]);

    $this->actingAs($account)
        ->withSession(['two_factor_passed' => true])
        ->putJson(route('settings.reservations.update'), [
            'account_settings' => [
                'buffer_minutes' => 15,
            ],
        ])
        ->assertOk();

    $stored = ReservationSetting::query()
        ->forAccount((int) $account->id)
        ->accountDefault()
        ->sole();

    expect($stored->past_reservation_reconciliation_enabled)->toBeTrue()
        ->and($stored->past_reservation_grace_minutes)->toBe(240)
        ->and($stored->past_reservation_max_catchup_days)->toBe(30);
});

it('records stale backlog as the review reason for reservations outside the tenant catch-up window', function (): void {
    $now = Carbon::parse('2026-08-28 12:00:00', 'UTC');
    Carbon::setTestNow($now);
    $account = pastOutcomeAccount();
    $endsAt = $now->copy()->subDays(8);
    $reservation = pastOutcomeReservation($account, pastOutcomeTeamMember($account), [
        'starts_at' => $endsAt->copy()->subHour(),
        'ends_at' => $endsAt,
        'status_change_source' => Reservation::STATUS_CHANGE_SOURCE_API,
        'status_changed_at' => $endsAt->copy()->subMinute(),
    ]);

    $summary = app(PastReservationOutcomeReconciler::class)->reconcile((int) $account->id, false, $now);
    $transition = ReservationStatusTransition::query()->sole();

    expect($summary)->toMatchArray([
        'checked' => 1,
        'eligible' => 1,
        'signaled' => 1,
        'reasons' => [PastReservationOutcomeDecision::REASON_STALE_BACKLOG => 1],
    ])->and($reservation->refresh()->outcome_review_reason_code)
        ->toBe(PastReservationOutcomeDecision::REASON_STALE_BACKLOG)
        ->and($transition->reason_code)->toBe(PastReservationOutcomeDecision::REASON_STALE_BACKLOG);
});

it('processes at most five hundred reservations per tenant run', function (): void {
    $now = Carbon::parse('2026-08-28 12:00:00', 'UTC');
    Carbon::setTestNow($now);
    $account = pastOutcomeAccount();
    $teamMember = pastOutcomeTeamMember($account);
    $startsAt = $now->copy()->subDay()->subHour();
    $reservationCount = PastReservationOutcomeReconciler::MAX_RESERVATIONS_PER_RUN + 1;

    $reservations = Reservation::factory()
        ->count($reservationCount)
        ->create([
            'account_id' => $account->id,
            'team_member_id' => $teamMember->id,
            'status' => Reservation::STATUS_CONFIRMED,
            'status_version' => 0,
            'schedule_version' => 0,
            'status_change_source' => Reservation::STATUS_CHANGE_SOURCE_API,
            'status_changed_at' => $startsAt->copy()->subMinute(),
            'source' => Reservation::SOURCE_STAFF,
            'timezone' => 'America/Toronto',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'created_at' => $startsAt->copy()->subDay(),
            'updated_at' => $startsAt->copy()->subDay(),
        ]);

    $summary = app(PastReservationOutcomeReconciler::class)->reconcile((int) $account->id, false, $now);
    $unprocessed = $reservations->sortByDesc('id')->firstOrFail();

    expect($summary)->toMatchArray([
        'checked' => PastReservationOutcomeReconciler::MAX_RESERVATIONS_PER_RUN,
        'eligible' => PastReservationOutcomeReconciler::MAX_RESERVATIONS_PER_RUN,
        'signaled' => PastReservationOutcomeReconciler::MAX_RESERVATIONS_PER_RUN,
    ])->and(Reservation::query()
        ->forAccount((int) $account->id)
        ->whereNotNull('outcome_review_required_at')
        ->count())->toBe(PastReservationOutcomeReconciler::MAX_RESERVATIONS_PER_RUN)
        ->and(ReservationStatusTransition::query()
            ->where('account_id', $account->id)
            ->count())->toBe(PastReservationOutcomeReconciler::MAX_RESERVATIONS_PER_RUN)
        ->and($unprocessed->refresh()->outcome_review_required_at)->toBeNull();
});
