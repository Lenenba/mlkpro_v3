<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class SocialTransportCutover extends Model
{
    use HasFactory;

    public const STATE_LEGACY_ONLY = 'legacy_only';

    public const STATE_CANARY_ARMED = 'canary_armed';

    public const STATE_CANARY_ACTIVE = 'canary_active';

    public const STATE_DRAINING_LEGACY = 'draining_legacy';

    public const STATE_AWAITING_H3 = 'awaiting_h3';

    public const STATE_CUTOVER_COMPLETE = 'cutover_complete';

    public const STATE_ROLLBACK_HOLD = 'rollback_hold';

    public const PILOT_NOT_STARTED = 'not_started';

    public const PILOT_ARMED = 'armed';

    public const PILOT_ACTIVE = 'active';

    public const PILOT_PASSED = 'passed';

    public const DRAIN_PENDING = 'pending';

    public const DRAIN_ACTIVE = 'active';

    public const DRAIN_COMPLETE = 'complete';

    public const ROLLBACK_UNAVAILABLE = 'unavailable';

    public const ROLLBACK_AVAILABLE = 'available';

    public const ROLLBACK_REQUESTED = 'requested';

    public const ROLLBACK_FORBIDDEN = 'forbidden';

    public const CANARY_MINIMUM_DELIVERIES = 10;

    public const CANARY_MINIMUM_HOURS = 168;

    public const CANARY_MAXIMUM_UNKNOWN = 0;

    public const ROLLBACK_MAXIMUM_RTO_SECONDS = 300;

    public const APPROVAL_AUTHORITY_SUPERADMIN = 'superadmin_at_approval';

    protected $fillable = [
        'user_id',
        'state',
        'active_transport_generation',
        'pilot_status',
        'legacy_drain_status',
        'rollback_status',
        'last_transition_by_user_id',
        'last_evidence_hash',
        'lock_version',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $cutover): void {
            if (! in_array((string) $cutover->state, self::allowedStates(), true)
                || ! in_array((string) $cutover->pilot_status, self::allowedPilotStatuses(), true)
                || ! in_array((string) $cutover->legacy_drain_status, self::allowedDrainStatuses(), true)
                || ! in_array((string) $cutover->rollback_status, self::allowedRollbackStatuses(), true)
                || trim((string) $cutover->active_transport_generation) === ''
                || mb_strlen((string) $cutover->active_transport_generation) > 32
                || preg_match('/\A[0-9a-f]{64}\z/', (string) $cutover->last_evidence_hash) !== 1
                || (int) $cutover->user_id <= 0
                || ($cutover->last_transition_by_user_id !== null
                    && (int) $cutover->last_transition_by_user_id <= 0)
                || (int) $cutover->lock_version < 0) {
                throw new LogicException('The Pulse transport cutover state is invalid.');
            }

            foreach ([
                $cutover->h2_evidence_hash,
                $cutover->canary_contract_hash,
                $cutover->mapping_manifest_hash,
                $cutover->canary_evidence_hash,
                $cutover->legacy_drain_evidence_hash,
                $cutover->h3_evidence_hash,
            ] as $evidenceHash) {
                if ($evidenceHash !== null
                    && preg_match('/\A[0-9a-f]{64}\z/', (string) $evidenceHash) !== 1) {
                    throw new LogicException('The Pulse transport cutover evidence hash is invalid.');
                }
            }

            if (! $cutover->hasCoherentState()) {
                throw new LogicException('The Pulse transport cutover state is internally inconsistent.');
            }
        });

        static::updating(function (): never {
            throw new LogicException(
                'Pulse transport cutover transitions must use the audited control-plane service.',
            );
        });
    }

    /** @return list<string> */
    public static function allowedStates(): array
    {
        return [
            self::STATE_LEGACY_ONLY,
            self::STATE_CANARY_ARMED,
            self::STATE_CANARY_ACTIVE,
            self::STATE_DRAINING_LEGACY,
            self::STATE_AWAITING_H3,
            self::STATE_CUTOVER_COMPLETE,
            self::STATE_ROLLBACK_HOLD,
        ];
    }

    /** @return list<string> */
    public static function allowedPilotStatuses(): array
    {
        return [
            self::PILOT_NOT_STARTED,
            self::PILOT_ARMED,
            self::PILOT_ACTIVE,
            self::PILOT_PASSED,
        ];
    }

    /** @return list<string> */
    public static function allowedDrainStatuses(): array
    {
        return [self::DRAIN_PENDING, self::DRAIN_ACTIVE, self::DRAIN_COMPLETE];
    }

    /** @return list<string> */
    public static function allowedRollbackStatuses(): array
    {
        return [
            self::ROLLBACK_UNAVAILABLE,
            self::ROLLBACK_AVAILABLE,
            self::ROLLBACK_REQUESTED,
            self::ROLLBACK_FORBIDDEN,
        ];
    }

    public function hasCompleteH2Contract(): bool
    {
        return (int) $this->h2_approved_by_user_id > 0
            && $this->h2_approval_authority === self::APPROVAL_AUTHORITY_SUPERADMIN
            && $this->h2_approved_at !== null
            && $this->timestampIsNotFuture($this->h2_approved_at)
            && $this->hasValidEvidenceHash($this->h2_evidence_hash)
            && $this->hasValidEvidenceHash($this->canary_contract_hash)
            && $this->hasValidEvidenceHash($this->mapping_manifest_hash)
            && (int) $this->canary_minimum_deliveries >= self::CANARY_MINIMUM_DELIVERIES
            && (int) $this->canary_minimum_hours >= self::CANARY_MINIMUM_HOURS
            && $this->canary_maximum_unknown !== null
            && (int) $this->canary_maximum_unknown === self::CANARY_MAXIMUM_UNKNOWN
            && (int) $this->rollback_rto_seconds > 0
            && (int) $this->rollback_rto_seconds <= self::ROLLBACK_MAXIMUM_RTO_SECONDS;
    }

    public function hasCompleteH3Decision(): bool
    {
        return (int) $this->h3_approved_by_user_id > 0
            && $this->h3_approval_authority === self::APPROVAL_AUTHORITY_SUPERADMIN
            && $this->hasValidEvidenceHash($this->h3_evidence_hash)
            && $this->h3_go_general_at !== null
            && $this->h3_direct_removal_authorized_at !== null
            && $this->timestampIsNotFuture($this->h3_go_general_at)
            && $this->timestampIsNotFuture($this->h3_direct_removal_authorized_at);
    }

    public function hasCoherentState(): bool
    {
        if ($this->state === self::STATE_ROLLBACK_HOLD) {
            $resumeState = (string) $this->rollback_resume_state;

            return in_array($resumeState, self::holdableStates(), true)
                && $this->rollback_status === self::ROLLBACK_REQUESTED
                && $this->snapshotMatchesState($resumeState, true);
        }

        return $this->rollback_resume_state === null
            && $this->snapshotMatchesState((string) $this->state, false);
    }

    public function hasStartedH2Contract(): bool
    {
        return collect([
            $this->h2_approved_by_user_id,
            $this->h2_approval_authority,
            $this->h2_approved_at,
            $this->h2_evidence_hash,
            $this->canary_contract_hash,
            $this->mapping_manifest_hash,
            $this->canary_minimum_deliveries,
            $this->canary_minimum_hours,
            $this->canary_maximum_unknown,
            $this->rollback_rto_seconds,
        ])->contains(fn (mixed $value): bool => $value !== null);
    }

    public function hasCompleteCanaryEvidence(): bool
    {
        return $this->hasValidEvidenceHash($this->canary_evidence_hash)
            && $this->timestampsAreOrdered(
                $this->h2_approved_at,
                $this->cutover_at,
                $this->canary_started_at,
                $this->canary_completed_at,
            )
            && (int) $this->canary_observed_deliveries >= (int) $this->canary_minimum_deliveries
            && $this->canary_observed_unknown !== null
            && (int) $this->canary_observed_unknown >= 0
            && (int) $this->canary_observed_unknown <= (int) $this->canary_maximum_unknown
            && (int) $this->canary_observed_rollback_rto_seconds > 0
            && (int) $this->canary_observed_rollback_rto_seconds <= (int) $this->rollback_rto_seconds
            && $this->timestampIsNotFuture($this->canary_completed_at)
            && $this->canaryMeetsMinimumDuration();
    }

    public function hasCompleteLegacyDrainEvidence(): bool
    {
        return $this->hasValidEvidenceHash($this->legacy_drain_evidence_hash)
            && $this->timestampsAreOrdered(
                $this->canary_completed_at,
                $this->direct_writer_barrier_at,
                $this->legacy_drain_observation_started_at,
                $this->legacy_drain_completed_at,
            )
            && $this->timestampIsNotFuture($this->legacy_drain_completed_at);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function lastTransitionBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_transition_by_user_id');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(SocialTransportCutoverMapping::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SocialTransportCutoverEvent::class);
    }

    protected function casts(): array
    {
        return [
            'h2_approved_at' => 'datetime',
            'cutover_at' => 'datetime',
            'canary_started_at' => 'datetime',
            'canary_completed_at' => 'datetime',
            'direct_writer_barrier_at' => 'datetime',
            'legacy_drain_observation_started_at' => 'datetime',
            'legacy_drain_completed_at' => 'datetime',
            'rollback_window_ends_at' => 'datetime',
            'h3_go_general_at' => 'datetime',
            'h3_direct_removal_authorized_at' => 'datetime',
            'direct_retired_at' => 'datetime',
            'canary_minimum_deliveries' => 'integer',
            'canary_minimum_hours' => 'integer',
            'canary_maximum_unknown' => 'integer',
            'rollback_rto_seconds' => 'integer',
            'canary_observed_deliveries' => 'integer',
            'canary_observed_unknown' => 'integer',
            'canary_observed_rollback_rto_seconds' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    /** @return list<string> */
    private static function holdableStates(): array
    {
        return [
            self::STATE_LEGACY_ONLY,
            self::STATE_CANARY_ARMED,
            self::STATE_CANARY_ACTIVE,
            self::STATE_DRAINING_LEGACY,
            self::STATE_AWAITING_H3,
        ];
    }

    private function snapshotMatchesState(string $state, bool $held): bool
    {
        $activeTransport = (string) $this->active_transport_generation;

        return match ($state) {
            self::STATE_LEGACY_ONLY => $activeTransport
                === SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1
                && $this->pilot_status === self::PILOT_NOT_STARTED
                && $this->legacy_drain_status === self::DRAIN_PENDING
                && $this->rollbackMatches(self::ROLLBACK_UNAVAILABLE, $held)
                && ! $this->hasAnyOperationalProof(),
            self::STATE_CANARY_ARMED => $activeTransport
                === SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1
                && $this->pilot_status === self::PILOT_ARMED
                && $this->legacy_drain_status === self::DRAIN_PENDING
                && $this->rollbackMatches(self::ROLLBACK_AVAILABLE, $held)
                && $this->hasCompleteH2Contract()
                && ! $this->hasStartedCanaryRuntime(),
            self::STATE_CANARY_ACTIVE => $activeTransport
                === SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1
                && $this->pilot_status === self::PILOT_ACTIVE
                && $this->legacy_drain_status === self::DRAIN_PENDING
                && $this->rollbackMatches(self::ROLLBACK_AVAILABLE, $held)
                && $this->hasCompleteH2Contract()
                && $this->timestampsAreOrdered(
                    $this->h2_approved_at,
                    $this->cutover_at,
                    $this->canary_started_at,
                )
                && $this->timestampIsNotFuture($this->canary_started_at)
                && ! $this->hasStartedCanaryCompletion(),
            self::STATE_DRAINING_LEGACY => $activeTransport
                === SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1
                && $this->pilot_status === self::PILOT_PASSED
                && $this->legacy_drain_status === self::DRAIN_ACTIVE
                && $this->rollbackMatches(self::ROLLBACK_AVAILABLE, $held)
                && $this->hasCompleteH2Contract()
                && $this->hasCompleteCanaryEvidence()
                && $this->legacy_drain_completed_at === null
                && $this->legacy_drain_evidence_hash === null
                && $this->rollback_window_ends_at === null
                && ! $this->hasStartedH3Decision()
                && $this->direct_retired_at === null,
            self::STATE_AWAITING_H3 => $activeTransport
                === SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1
                && $this->pilot_status === self::PILOT_PASSED
                && $this->legacy_drain_status === self::DRAIN_COMPLETE
                && $this->rollbackMatches(self::ROLLBACK_AVAILABLE, $held)
                && $this->hasCompleteH2Contract()
                && $this->hasCompleteCanaryEvidence()
                && $this->hasCompleteLegacyDrainEvidence()
                && ! $this->hasStartedH3Decision()
                && $this->rollback_window_ends_at === null
                && $this->direct_retired_at === null,
            self::STATE_CUTOVER_COMPLETE => ! $held
                && $activeTransport === SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1
                && $this->pilot_status === self::PILOT_PASSED
                && $this->legacy_drain_status === self::DRAIN_COMPLETE
                && $this->rollback_status === self::ROLLBACK_FORBIDDEN
                && $this->hasCompleteH2Contract()
                && $this->hasCompleteCanaryEvidence()
                && $this->hasCompleteLegacyDrainEvidence()
                && $this->hasCompleteH3Decision()
                && $this->timestampsAreOrdered(
                    $this->legacy_drain_completed_at,
                    $this->h3_go_general_at,
                    $this->h3_direct_removal_authorized_at,
                    $this->rollback_window_ends_at,
                    $this->direct_retired_at,
                )
                && $this->timestampIsNotFuture($this->direct_retired_at),
            default => false,
        };
    }

    private function rollbackMatches(string $expectedStatus, bool $held): bool
    {
        return $held
            ? $this->rollback_status === self::ROLLBACK_REQUESTED
            : $this->rollback_status === $expectedStatus;
    }

    private function hasAnyOperationalProof(): bool
    {
        return $this->hasStartedH2Contract()
            || collect([
                $this->cutover_at,
                $this->canary_started_at,
                $this->canary_completed_at,
                $this->canary_evidence_hash,
                $this->canary_observed_deliveries,
                $this->canary_observed_unknown,
                $this->canary_observed_rollback_rto_seconds,
                $this->direct_writer_barrier_at,
                $this->legacy_drain_observation_started_at,
                $this->legacy_drain_completed_at,
                $this->legacy_drain_evidence_hash,
                $this->rollback_window_ends_at,
                $this->h3_approved_by_user_id,
                $this->h3_approval_authority,
                $this->h3_evidence_hash,
                $this->h3_go_general_at,
                $this->h3_direct_removal_authorized_at,
                $this->direct_retired_at,
            ])->contains(fn (mixed $value): bool => $value !== null);
    }

    private function hasStartedH3Decision(): bool
    {
        return collect([
            $this->h3_approved_by_user_id,
            $this->h3_approval_authority,
            $this->h3_evidence_hash,
            $this->h3_go_general_at,
            $this->h3_direct_removal_authorized_at,
        ])->contains(fn (mixed $value): bool => $value !== null);
    }

    private function hasStartedCanaryRuntime(): bool
    {
        return collect([
            $this->cutover_at,
            $this->canary_started_at,
            $this->canary_completed_at,
            $this->canary_evidence_hash,
            $this->canary_observed_deliveries,
            $this->canary_observed_unknown,
            $this->canary_observed_rollback_rto_seconds,
            $this->direct_writer_barrier_at,
            $this->legacy_drain_observation_started_at,
            $this->legacy_drain_completed_at,
            $this->legacy_drain_evidence_hash,
            $this->rollback_window_ends_at,
            $this->direct_retired_at,
        ])->contains(fn (mixed $value): bool => $value !== null)
            || $this->hasStartedH3Decision();
    }

    private function hasStartedCanaryCompletion(): bool
    {
        return collect([
            $this->canary_completed_at,
            $this->canary_evidence_hash,
            $this->canary_observed_deliveries,
            $this->canary_observed_unknown,
            $this->canary_observed_rollback_rto_seconds,
            $this->direct_writer_barrier_at,
            $this->legacy_drain_observation_started_at,
            $this->legacy_drain_completed_at,
            $this->legacy_drain_evidence_hash,
            $this->rollback_window_ends_at,
            $this->direct_retired_at,
        ])->contains(fn (mixed $value): bool => $value !== null)
            || $this->hasStartedH3Decision();
    }

    private function hasValidEvidenceHash(mixed $evidenceHash): bool
    {
        return preg_match('/\A[0-9a-f]{64}\z/', (string) $evidenceHash) === 1;
    }

    private function timestampsAreOrdered(mixed ...$timestamps): bool
    {
        $previous = null;

        foreach ($timestamps as $timestamp) {
            if (! $timestamp instanceof CarbonInterface) {
                return false;
            }

            if ($previous instanceof CarbonInterface && $timestamp->lessThan($previous)) {
                return false;
            }

            $previous = $timestamp;
        }

        return true;
    }

    private function canaryMeetsMinimumDuration(): bool
    {
        if (! $this->canary_started_at instanceof CarbonInterface
            || ! $this->canary_completed_at instanceof CarbonInterface
            || (int) $this->canary_minimum_hours <= 0) {
            return false;
        }

        return $this->canary_completed_at->greaterThanOrEqualTo(
            $this->canary_started_at->copy()->addHours((int) $this->canary_minimum_hours),
        );
    }

    private function timestampIsNotFuture(mixed $timestamp): bool
    {
        return $timestamp instanceof CarbonInterface && ! $timestamp->isFuture();
    }
}
