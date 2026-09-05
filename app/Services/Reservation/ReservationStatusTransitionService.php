<?php

namespace App\Services\Reservation;

use App\Models\Customer;
use App\Models\Reservation;
use App\Models\ReservationStatusTransition;
use App\Models\TeamMember;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class ReservationStatusTransitionService
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordCreation(
        Reservation $reservation,
        string $actorType,
        ?User $actor,
        string $source,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null
    ): Reservation {
        $this->assertActorType($actorType);
        $this->assertActorForAccount($actorType, $actor, (int) $reservation->account_id);
        $occurredAtUtc = $this->asUtc($occurredAt);

        $reservation->forceFill([
            'status_version' => max(1, (int) $reservation->status_version),
            'schedule_version' => max(1, (int) $reservation->schedule_version),
            'mutation_version' => max(1, (int) $reservation->mutation_version),
            'status_changed_at' => $occurredAtUtc,
            'status_changed_by_user_id' => $actorType === ReservationStatusTransition::ACTOR_USER ? $actor?->id : null,
            'status_change_source' => $source,
        ])->save();

        ReservationStatusTransition::query()->firstOrCreate(
            [
                'account_id' => (int) $reservation->account_id,
                'idempotency_key' => hash('sha256', implode(':', [
                    'reservation-created',
                    (int) $reservation->account_id,
                    (int) $reservation->id,
                ])),
            ],
            [
                'reservation_id' => (int) $reservation->id,
                'event_type' => ReservationStatusTransition::EVENT_CREATED,
                'from_status' => null,
                'to_status' => (string) $reservation->status,
                'actor_type' => $actorType,
                'actor_user_id' => $actorType === ReservationStatusTransition::ACTOR_USER ? $actor?->id : null,
                'source' => $source,
                'reason_code' => 'reservation_created',
                'reason' => 'Reservation created.',
                'status_version' => (int) $reservation->status_version,
                'schedule_version' => (int) $reservation->schedule_version,
                'metadata' => $metadata ?: null,
                'occurred_at' => $occurredAtUtc,
            ]
        );

        return $reservation->fresh() ?? $reservation;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $metadata
     * @param  array<int, string>  $allowedFromStatuses
     */
    public function transition(
        Reservation $reservation,
        string $nextStatus,
        string $actorType,
        ?User $actor,
        string $source,
        string $reasonCode,
        ?string $reason = null,
        array $attributes = [],
        array $metadata = [],
        bool $recordSameStatus = false,
        array $allowedFromStatuses = [],
        ?string $idempotencyKey = null,
        ?int $expectedStatusVersion = null,
        ?int $expectedScheduleVersion = null,
        ?int $expectedMutationVersion = null,
        ?CarbonInterface $occurredAt = null
    ): ReservationStatusTransitionResult {
        if (! in_array($nextStatus, Reservation::STATUSES, true)) {
            throw new InvalidArgumentException("Unsupported reservation status [{$nextStatus}].");
        }

        $this->assertActorType($actorType);
        $accountId = (int) $reservation->account_id;
        $reservationId = (int) $reservation->id;
        $this->assertActorForAccount($actorType, $actor, $accountId);
        $occurredAtUtc = $this->asUtc($occurredAt);
        $normalizedIdempotencyKey = $this->idempotencyKey($idempotencyKey);

        return DB::transaction(function () use (
            $accountId,
            $reservationId,
            $nextStatus,
            $actorType,
            $actor,
            $source,
            $reasonCode,
            $reason,
            $attributes,
            $metadata,
            $recordSameStatus,
            $allowedFromStatuses,
            $normalizedIdempotencyKey,
            $expectedStatusVersion,
            $expectedScheduleVersion,
            $expectedMutationVersion,
            $occurredAtUtc
        ): ReservationStatusTransitionResult {
            $locked = Reservation::query()
                ->forAccount($accountId)
                ->whereKey($reservationId)
                ->lockForUpdate()
                ->firstOrFail();
            $previousStatus = (string) $locked->status;

            $existingTransition = ReservationStatusTransition::query()
                ->where('account_id', $accountId)
                ->where('idempotency_key', $normalizedIdempotencyKey)
                ->first();
            if ($existingTransition) {
                if (
                    (int) $existingTransition->reservation_id !== $reservationId
                    || (string) $existingTransition->to_status !== $nextStatus
                    || (string) $existingTransition->source !== $source
                    || (string) $existingTransition->reason_code !== $reasonCode
                ) {
                    throw new LogicException('Reservation transition idempotency key collision.');
                }

                return new ReservationStatusTransitionResult($locked, false, $previousStatus);
            }

            if (
                ($expectedStatusVersion !== null && (int) $locked->status_version !== $expectedStatusVersion)
                || ($expectedScheduleVersion !== null && (int) $locked->schedule_version !== $expectedScheduleVersion)
                || ($expectedMutationVersion !== null && (int) $locked->mutation_version !== $expectedMutationVersion)
            ) {
                return new ReservationStatusTransitionResult($locked, false, $previousStatus);
            }

            if ($allowedFromStatuses !== [] && ! in_array($previousStatus, $allowedFromStatuses, true)) {
                return new ReservationStatusTransitionResult($locked, false, $previousStatus);
            }

            if ($previousStatus === $nextStatus && ! $recordSameStatus) {
                return new ReservationStatusTransitionResult($locked, false, $previousStatus);
            }

            $safeAttributes = collect($attributes)->except([
                'id',
                'account_id',
                'status',
                'status_version',
                'schedule_version',
                'mutation_version',
                'status_changed_at',
                'status_changed_by_user_id',
                'status_change_source',
                'outcome_review_required_at',
                'outcome_review_reason_code',
            ])->all();
            $nextStatusVersion = (int) $locked->status_version + 1;
            $nextMutationVersion = (int) $locked->mutation_version + 1;

            $locked->forceFill([
                ...$safeAttributes,
                'status' => $nextStatus,
                'status_version' => $nextStatusVersion,
                'mutation_version' => $nextMutationVersion,
                'status_changed_at' => $occurredAtUtc,
                'status_changed_by_user_id' => $actorType === ReservationStatusTransition::ACTOR_USER ? $actor?->id : null,
                'status_change_source' => $source,
                'outcome_review_required_at' => null,
                'outcome_review_reason_code' => null,
            ])->save();

            ReservationStatusTransition::query()->create([
                'account_id' => $accountId,
                'reservation_id' => $reservationId,
                'event_type' => $previousStatus === $nextStatus
                    ? ReservationStatusTransition::EVENT_STATUS_REAFFIRMED
                    : ReservationStatusTransition::EVENT_STATUS_CHANGED,
                'from_status' => $previousStatus,
                'to_status' => $nextStatus,
                'actor_type' => $actorType,
                'actor_user_id' => $actorType === ReservationStatusTransition::ACTOR_USER ? $actor?->id : null,
                'source' => $source,
                'reason_code' => $reasonCode,
                'reason' => $reason,
                'status_version' => $nextStatusVersion,
                'schedule_version' => (int) $locked->schedule_version,
                'idempotency_key' => $normalizedIdempotencyKey,
                'metadata' => $metadata ?: null,
                'occurred_at' => $occurredAtUtc,
            ]);

            return new ReservationStatusTransitionResult(
                $locked->fresh() ?? $locked,
                true,
                $previousStatus,
                $previousStatus !== $nextStatus,
                false,
                true
            );
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $metadata
     * @param  array<int, string>  $allowedFromStatuses
     */
    public function reschedule(
        Reservation $reservation,
        array $attributes,
        User $actor,
        string $source,
        array $metadata = [],
        array $allowedFromStatuses = Reservation::ACTIVE_STATUSES,
        ?int $expectedStatusVersion = null,
        ?int $expectedScheduleVersion = null,
        ?int $expectedMutationVersion = null,
        ?CarbonInterface $occurredAt = null
    ): ReservationStatusTransitionResult {
        $accountId = (int) $reservation->account_id;
        $reservationId = (int) $reservation->id;
        $this->assertActorForAccount(ReservationStatusTransition::ACTOR_USER, $actor, $accountId);
        $occurredAtUtc = $this->asUtc($occurredAt);

        return DB::transaction(function () use (
            $accountId,
            $reservationId,
            $attributes,
            $actor,
            $source,
            $metadata,
            $allowedFromStatuses,
            $expectedStatusVersion,
            $expectedScheduleVersion,
            $expectedMutationVersion,
            $occurredAtUtc
        ): ReservationStatusTransitionResult {
            $locked = Reservation::query()
                ->forAccount($accountId)
                ->whereKey($reservationId)
                ->lockForUpdate()
                ->firstOrFail();
            $previousStatus = (string) $locked->status;
            if (
                ($expectedStatusVersion !== null && (int) $locked->status_version !== $expectedStatusVersion)
                || ($expectedScheduleVersion !== null && (int) $locked->schedule_version !== $expectedScheduleVersion)
                || ($expectedMutationVersion !== null && (int) $locked->mutation_version !== $expectedMutationVersion)
                || ($allowedFromStatuses !== [] && ! in_array($previousStatus, $allowedFromStatuses, true))
            ) {
                return new ReservationStatusTransitionResult($locked, false, $previousStatus);
            }

            $nextStatus = (string) ($attributes['status'] ?? $previousStatus);
            if (! in_array($nextStatus, Reservation::STATUSES, true)) {
                throw new InvalidArgumentException("Unsupported reservation status [{$nextStatus}].");
            }

            $previousStartsAt = $locked->starts_at?->toIso8601String();
            $previousEndsAt = $locked->ends_at?->toIso8601String();
            $statusChanged = $previousStatus !== $nextStatus;
            $safeAttributes = collect($attributes)->except([
                'id',
                'account_id',
                'status_version',
                'schedule_version',
                'mutation_version',
                'status_changed_at',
                'status_changed_by_user_id',
                'status_change_source',
                'outcome_review_required_at',
                'outcome_review_reason_code',
            ])->all();
            $locked->fill($safeAttributes);
            $attributesChanged = $locked->isDirty();
            $scheduleChanged = $locked->isDirty([
                'team_member_id',
                'service_id',
                'starts_at',
                'ends_at',
                'duration_minutes',
                'buffer_minutes',
                'timezone',
            ]);
            $nextStatusVersion = (int) $locked->status_version + ($statusChanged ? 1 : 0);
            $nextScheduleVersion = (int) $locked->schedule_version + ($scheduleChanged ? 1 : 0);
            $nextMutationVersion = (int) $locked->mutation_version + ($attributesChanged ? 1 : 0);
            $projection = [
                ...$safeAttributes,
                'status' => $nextStatus,
                'status_version' => $nextStatusVersion,
                'schedule_version' => $nextScheduleVersion,
                'mutation_version' => $nextMutationVersion,
            ];

            if ($statusChanged) {
                $projection['status_changed_at'] = $occurredAtUtc;
                $projection['status_changed_by_user_id'] = $actor->id;
                $projection['status_change_source'] = $source;
            }
            if ($statusChanged || $scheduleChanged) {
                $projection['outcome_review_required_at'] = null;
                $projection['outcome_review_reason_code'] = null;
            }

            if ($attributesChanged) {
                $locked->forceFill($projection)->save();
            }

            if ($statusChanged || $scheduleChanged) {
                ReservationStatusTransition::query()->create([
                    'account_id' => $accountId,
                    'reservation_id' => $reservationId,
                    'event_type' => $scheduleChanged
                        ? ReservationStatusTransition::EVENT_SCHEDULE_CHANGED
                        : ReservationStatusTransition::EVENT_STATUS_CHANGED,
                    'from_status' => $previousStatus,
                    'to_status' => $nextStatus,
                    'actor_type' => ReservationStatusTransition::ACTOR_USER,
                    'actor_user_id' => $actor->id,
                    'source' => $source,
                    'reason_code' => $scheduleChanged ? 'reservation_rescheduled' : 'reservation_status_changed',
                    'reason' => $scheduleChanged ? 'Reservation schedule changed.' : 'Reservation status changed.',
                    'status_version' => $nextStatusVersion,
                    'schedule_version' => $nextScheduleVersion,
                    'idempotency_key' => $this->idempotencyKey(),
                    'metadata' => array_replace_recursive([
                        'previous_starts_at' => $previousStartsAt,
                        'previous_ends_at' => $previousEndsAt,
                        'starts_at' => $locked->starts_at?->toIso8601String(),
                        'ends_at' => $locked->ends_at?->toIso8601String(),
                    ], $metadata) ?: null,
                    'occurred_at' => $occurredAtUtc,
                ]);
            }

            return new ReservationStatusTransitionResult(
                $locked->fresh() ?? $locked,
                true,
                $previousStatus,
                $statusChanged,
                $scheduleChanged,
                $attributesChanged
            );
        }, 3);
    }

    private function assertActorType(string $actorType): void
    {
        if (! in_array($actorType, [
            ReservationStatusTransition::ACTOR_USER,
            ReservationStatusTransition::ACTOR_SYSTEM,
            ReservationStatusTransition::ACTOR_INTEGRATION,
        ], true)) {
            throw new InvalidArgumentException("Unsupported reservation transition actor [{$actorType}].");
        }
    }

    private function assertActorForAccount(string $actorType, ?User $actor, int $accountId): void
    {
        if ($actorType !== ReservationStatusTransition::ACTOR_USER) {
            if ($actor !== null) {
                throw new InvalidArgumentException('System and integration reservation transitions cannot have a user actor.');
            }

            return;
        }

        if ($actor === null) {
            throw new InvalidArgumentException('A user reservation transition requires an actor.');
        }

        $belongsToAccount = (int) $actor->id === $accountId
            || TeamMember::query()
                ->forAccount($accountId)
                ->where('user_id', $actor->id)
                ->exists()
            || Customer::query()
                ->byUser($accountId)
                ->where('portal_user_id', $actor->id)
                ->exists();

        if (! $belongsToAccount) {
            throw new InvalidArgumentException('The reservation transition actor does not belong to the tenant.');
        }
    }

    private function idempotencyKey(?string $key = null): string
    {
        $key = strtolower(trim((string) $key));
        if (preg_match('/^[a-f0-9]{64}$/', $key) === 1) {
            return $key;
        }

        return hash('sha256', $key !== '' ? $key : (string) Str::uuid());
    }

    private function asUtc(?CarbonInterface $occurredAt): Carbon
    {
        if ($occurredAt !== null) {
            return Carbon::instance($occurredAt->toDateTime())->utc();
        }

        return now('UTC');
    }
}
