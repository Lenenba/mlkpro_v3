<?php

namespace App\Services\Social;

use App\Models\SocialAccountConnection;
use App\Models\SocialDeliveryOutbox;
use App\Models\SocialTransportCutover;
use App\Models\SocialTransportCutoverEvent;
use App\Models\SocialTransportCutoverMapping;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;

class SocialTransportCutoverService
{
    public function __construct(
        private readonly SocialConnectionDeliveryMutex $deliveryMutex,
        private readonly SocialDeliveryOutboxService $deliveryOutboxes,
    ) {}

    public function purgeForTenantDeletion(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('The Pulse cutover purge tenant ID must be positive.');
        }

        if (DB::transactionLevel() < 1) {
            throw new LogicException('Pulse cutover audit data must be purged inside account deletion.');
        }

        if (! Schema::hasTable('social_transport_cutovers')) {
            return;
        }

        $cutoverIds = SocialTransportCutover::query()
            ->where('user_id', $tenantId)
            ->lockForUpdate()
            ->pluck('id');

        if ($cutoverIds->isEmpty()) {
            return;
        }

        $hasTenantMismatch = DB::table('social_transport_cutover_events')
            ->whereIn('social_transport_cutover_id', $cutoverIds)
            ->where('user_id', '!=', $tenantId)
            ->exists()
            || DB::table('social_transport_cutover_mappings')
                ->whereIn('social_transport_cutover_id', $cutoverIds)
                ->where('user_id', '!=', $tenantId)
                ->exists();

        if ($hasTenantMismatch) {
            throw new LogicException('Cross-tenant Pulse cutover audit data cannot be purged.');
        }

        DB::table('social_transport_cutover_events')
            ->whereIn('social_transport_cutover_id', $cutoverIds)
            ->delete();
        DB::table('social_transport_cutover_mappings')
            ->whereIn('social_transport_cutover_id', $cutoverIds)
            ->delete();
        DB::table('social_transport_cutovers')
            ->where('user_id', $tenantId)
            ->delete();
    }

    public function initialize(User $tenant, User $actor, string $evidenceHash): SocialTransportCutover
    {
        $this->assertTenantAndActor($tenant, $actor);
        $evidenceHash = $this->normalizeEvidenceHash($evidenceHash);

        return $this->withTransitionLocks(
            (int) $tenant->getKey(),
            fn (): SocialTransportCutover => $this->initializeLocked(
                $tenant,
                $actor,
                $evidenceHash,
                true,
            ),
        );
    }

    public function placeOnRollbackHold(
        User $tenant,
        User $actor,
        string $evidenceHash,
    ): SocialTransportCutover {
        $this->assertTenantAndActor($tenant, $actor);
        $evidenceHash = $this->normalizeEvidenceHash($evidenceHash);

        return $this->withTransitionLocks(
            (int) $tenant->getKey(),
            function () use ($tenant, $actor, $evidenceHash): SocialTransportCutover {
                $this->initializeLocked($tenant, $actor, $evidenceHash, false);

                return DB::transaction(function () use ($tenant, $actor, $evidenceHash): SocialTransportCutover {
                    $cutover = SocialTransportCutover::query()
                        ->where('user_id', $tenant->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($cutover->state === SocialTransportCutover::STATE_ROLLBACK_HOLD) {
                        $lastHold = SocialTransportCutoverEvent::query()
                            ->where('social_transport_cutover_id', $cutover->getKey())
                            ->where('reason', 'rollback_hold_requested')
                            ->latest('sequence')
                            ->first();

                        if (! $lastHold
                            || $lastHold->from_state !== $cutover->rollback_resume_state
                            || ! $cutover->hasCoherentState()
                            || ! hash_equals((string) $lastHold->evidence_hash, $evidenceHash)) {
                            throw new LogicException(
                                'The Pulse rollback hold replay evidence does not match the audited transition.',
                            );
                        }

                        return $cutover;
                    }

                    if (! in_array((string) $cutover->state, [
                        SocialTransportCutover::STATE_LEGACY_ONLY,
                        SocialTransportCutover::STATE_CANARY_ARMED,
                        SocialTransportCutover::STATE_CANARY_ACTIVE,
                        SocialTransportCutover::STATE_DRAINING_LEGACY,
                        SocialTransportCutover::STATE_AWAITING_H3,
                    ], true) || ! $cutover->hasCoherentState()) {
                        throw new LogicException(
                            'Only a coherent reversible Pulse transport state can enter rollback hold.',
                        );
                    }

                    $fromState = (string) $cutover->state;
                    $updated = SocialTransportCutover::query()
                        ->whereKey($cutover->getKey())
                        ->where('lock_version', $cutover->lock_version)
                        ->update([
                            'state' => SocialTransportCutover::STATE_ROLLBACK_HOLD,
                            'rollback_resume_state' => $fromState,
                            'rollback_status' => SocialTransportCutover::ROLLBACK_REQUESTED,
                            'last_transition_by_user_id' => $actor->getKey(),
                            'last_evidence_hash' => $evidenceHash,
                            'lock_version' => DB::raw('lock_version + 1'),
                            'updated_at' => now(),
                        ]);

                    if ($updated !== 1) {
                        throw new LogicException('The Pulse transport state changed concurrently.');
                    }

                    $cutover = $cutover->fresh();
                    if (! $cutover->hasCoherentState()) {
                        throw new LogicException('The Pulse rollback hold snapshot is inconsistent.');
                    }

                    $this->appendEvent(
                        $cutover,
                        $actor,
                        $fromState,
                        SocialTransportCutover::STATE_ROLLBACK_HOLD,
                        'rollback_hold_requested',
                        $evidenceHash,
                    );

                    return $cutover;
                }, 3);
            },
        );
    }

    public function resumeLegacyAfterRollbackHold(
        User $tenant,
        User $actor,
        string $evidenceHash,
    ): SocialTransportCutover {
        return $this->resumeAfterRollbackHold(
            $tenant,
            $actor,
            $evidenceHash,
            SocialTransportCutover::STATE_LEGACY_ONLY,
        );
    }

    public function resumeAfterRollbackHold(
        User $tenant,
        User $actor,
        string $evidenceHash,
        ?string $expectedResumeState = null,
    ): SocialTransportCutover {
        $this->assertTenantAndActor($tenant, $actor);
        $evidenceHash = $this->normalizeEvidenceHash($evidenceHash);

        if ($expectedResumeState !== null
            && ! in_array($expectedResumeState, SocialTransportCutover::allowedStates(), true)) {
            throw new InvalidArgumentException('The expected Pulse rollback resume state is invalid.');
        }

        return $this->withTransitionLocks(
            (int) $tenant->getKey(),
            fn (): SocialTransportCutover => DB::transaction(function () use (
                $tenant,
                $actor,
                $evidenceHash,
                $expectedResumeState,
            ): SocialTransportCutover {
                $cutover = SocialTransportCutover::query()
                    ->where('user_id', $tenant->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $lastRelease = SocialTransportCutoverEvent::query()
                    ->where('social_transport_cutover_id', $cutover->getKey())
                    ->where('reason', 'rollback_hold_released')
                    ->latest('sequence')
                    ->first();

                if ($lastRelease !== null
                    && $lastRelease->to_state === $cutover->state
                    && $cutover->state !== SocialTransportCutover::STATE_ROLLBACK_HOLD) {
                    if (($expectedResumeState !== null
                            && $lastRelease->to_state !== $expectedResumeState)
                        || ! $cutover->hasCoherentState()
                        || ! hash_equals((string) $lastRelease->evidence_hash, $evidenceHash)) {
                        throw new LogicException(
                            'The Pulse rollback release replay evidence does not match the audited transition.',
                        );
                    }

                    $this->assertNoAmbiguousRemoteEffects((int) $tenant->getKey());
                    $this->resumeAllowedSuspendedOutboxes(
                        (int) $tenant->getKey(),
                        (string) $cutover->state,
                    );

                    return $cutover;
                }

                $lastHold = SocialTransportCutoverEvent::query()
                    ->where('social_transport_cutover_id', $cutover->getKey())
                    ->where('reason', 'rollback_hold_requested')
                    ->latest('sequence')
                    ->first();

                $resumeState = (string) $cutover->rollback_resume_state;

                if ($cutover->state !== SocialTransportCutover::STATE_ROLLBACK_HOLD
                    || $lastHold === null
                    || $lastHold->from_state !== $resumeState
                    || ($expectedResumeState !== null && $resumeState !== $expectedResumeState)
                    || ! $cutover->hasCoherentState()) {
                    throw new LogicException(
                        'Only the exact audited Pulse transport snapshot can resume from rollback hold.',
                    );
                }

                $this->assertNoAmbiguousRemoteEffects((int) $tenant->getKey());
                $this->resumeAllowedSuspendedOutboxes(
                    (int) $tenant->getKey(),
                    $resumeState,
                );
                $updated = SocialTransportCutover::query()
                    ->whereKey($cutover->getKey())
                    ->where('state', SocialTransportCutover::STATE_ROLLBACK_HOLD)
                    ->where('lock_version', $cutover->lock_version)
                    ->update([
                        'state' => $resumeState,
                        'rollback_resume_state' => null,
                        'rollback_status' => $resumeState === SocialTransportCutover::STATE_LEGACY_ONLY
                            ? SocialTransportCutover::ROLLBACK_UNAVAILABLE
                            : SocialTransportCutover::ROLLBACK_AVAILABLE,
                        'last_transition_by_user_id' => $actor->getKey(),
                        'last_evidence_hash' => $evidenceHash,
                        'lock_version' => DB::raw('lock_version + 1'),
                        'updated_at' => now(),
                    ]);

                if ($updated !== 1) {
                    throw new LogicException('The Pulse transport state changed concurrently.');
                }

                $cutover = $cutover->fresh();
                if (! $cutover->hasCoherentState()) {
                    throw new LogicException('The resumed Pulse transport snapshot is inconsistent.');
                }

                $this->appendEvent(
                    $cutover,
                    $actor,
                    SocialTransportCutover::STATE_ROLLBACK_HOLD,
                    $resumeState,
                    'rollback_hold_released',
                    $evidenceHash,
                );

                return $cutover;
            }, 3),
        );
    }

    public function recordOwnerValidatedMapping(
        User $tenant,
        User $actor,
        SocialAccountConnection $legacyConnection,
        SocialAccountConnection $replacementConnection,
        string $evidenceHash,
    ): SocialTransportCutoverMapping {
        $this->assertTenantAndActor($tenant, $actor);
        $evidenceHash = $this->normalizeEvidenceHash($evidenceHash);
        $this->assertMapping($tenant, $legacyConnection, $replacementConnection);
        $legacyConnectionId = (int) $legacyConnection->getKey();
        $replacementConnectionId = (int) $replacementConnection->getKey();

        return $this->withTransitionLocks(
            (int) $tenant->getKey(),
            function () use (
                $tenant,
                $actor,
                $legacyConnectionId,
                $replacementConnectionId,
                $evidenceHash,
            ): SocialTransportCutoverMapping {
                $legacyConnection = SocialAccountConnection::query()
                    ->where('user_id', $tenant->getKey())
                    ->findOrFail($legacyConnectionId);
                $replacementConnection = SocialAccountConnection::query()
                    ->where('user_id', $tenant->getKey())
                    ->findOrFail($replacementConnectionId);
                $this->assertMapping($tenant, $legacyConnection, $replacementConnection);
                $this->initializeLocked($tenant, $actor, $evidenceHash, false);

                return DB::transaction(function () use (
                    $tenant,
                    $actor,
                    $legacyConnection,
                    $replacementConnection,
                    $evidenceHash,
                ): SocialTransportCutoverMapping {
                    $cutover = SocialTransportCutover::query()
                        ->where('user_id', $tenant->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($cutover->state !== SocialTransportCutover::STATE_LEGACY_ONLY
                        || $cutover->hasStartedH2Contract()) {
                        throw new LogicException('Pulse mappings are frozen before H2 is signed.');
                    }

                    $attributes = [
                        'social_transport_cutover_id' => $cutover->getKey(),
                        'user_id' => $tenant->getKey(),
                        'legacy_connection_id' => $legacyConnection->getKey(),
                        'replacement_connection_id' => $replacementConnection->getKey(),
                        'logical_destination_key' => $legacyConnection->logical_destination_key,
                        'owner_validated_by_user_id' => $tenant->getKey(),
                        'owner_validated_at' => now(),
                        'owner_evidence_hash' => $evidenceHash,
                    ];
                    $mapping = SocialTransportCutoverMapping::query()->firstOrCreate(
                        [
                            'social_transport_cutover_id' => $cutover->getKey(),
                            'legacy_connection_id' => $legacyConnection->getKey(),
                        ],
                        $attributes,
                    );

                    foreach ($attributes as $attribute => $value) {
                        if ($attribute === 'owner_validated_at') {
                            continue;
                        }

                        if ((string) $mapping->{$attribute} !== (string) $value) {
                            throw new LogicException('The existing Pulse channel mapping is not idempotent.');
                        }
                    }

                    if ($mapping->wasRecentlyCreated) {
                        $this->appendEvent(
                            $cutover,
                            $actor,
                            (string) $cutover->state,
                            (string) $cutover->state,
                            'owner_mapping_validated',
                            $evidenceHash,
                        );
                    }

                    return $mapping->fresh();
                }, 3);
            },
        );
    }

    private function initializeLocked(
        User $tenant,
        User $actor,
        string $evidenceHash,
        bool $verifyReplayEvidence,
    ): SocialTransportCutover {
        return DB::transaction(function () use (
            $tenant,
            $actor,
            $evidenceHash,
            $verifyReplayEvidence,
        ): SocialTransportCutover {
            $cutover = SocialTransportCutover::query()
                ->where('user_id', $tenant->getKey())
                ->lockForUpdate()
                ->first();

            if ($cutover) {
                if ($verifyReplayEvidence) {
                    $initialization = SocialTransportCutoverEvent::query()
                        ->where('social_transport_cutover_id', $cutover->getKey())
                        ->where('reason', 'control_plane_initialized')
                        ->oldest('sequence')
                        ->first();

                    if (! $initialization
                        || ! hash_equals((string) $initialization->evidence_hash, $evidenceHash)) {
                        throw new LogicException(
                            'The Pulse control-plane initialization replay evidence does not match.',
                        );
                    }
                }

                return $cutover;
            }

            $cutover = SocialTransportCutover::query()->create([
                'user_id' => $tenant->getKey(),
                'state' => SocialTransportCutover::STATE_LEGACY_ONLY,
                'active_transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
                'pilot_status' => SocialTransportCutover::PILOT_NOT_STARTED,
                'legacy_drain_status' => SocialTransportCutover::DRAIN_PENDING,
                'rollback_status' => SocialTransportCutover::ROLLBACK_UNAVAILABLE,
                'last_transition_by_user_id' => $actor->getKey(),
                'last_evidence_hash' => $evidenceHash,
                'lock_version' => 0,
            ]);

            $this->appendEvent(
                $cutover,
                $actor,
                SocialTransportCutover::STATE_LEGACY_ONLY,
                SocialTransportCutover::STATE_LEGACY_ONLY,
                'control_plane_initialized',
                $evidenceHash,
            );

            return $cutover->fresh();
        }, 3);
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    private function withTransitionLocks(int $tenantId, callable $callback): mixed
    {
        $tenantLock = $this->deliveryMutex->acquireTenant($tenantId);

        if ($tenantLock === null) {
            throw new LogicException(
                'The Pulse workspace transport is busy. Retry the control-plane transition shortly.',
            );
        }

        $connectionLocks = [];

        try {
            $connectionIds = SocialAccountConnection::query()
                ->where('user_id', $tenantId)
                ->orderBy('id')
                ->pluck('id');

            foreach ($connectionIds as $connectionId) {
                $connectionLock = $this->deliveryMutex->acquire((int) $connectionId);

                if ($connectionLock === null) {
                    throw new LogicException(
                        'A Pulse delivery is active. Retry the control-plane transition shortly.',
                    );
                }

                $connectionLocks[] = $connectionLock;
            }

            return $callback();
        } finally {
            foreach (array_reverse($connectionLocks) as $connectionLock) {
                $connectionLock->release();
            }

            $tenantLock->release();
        }
    }

    private function assertTenantAndActor(User $tenant, User $actor): void
    {
        if ((int) $tenant->getKey() <= 0
            || (int) $tenant->accountOwnerId() !== (int) $tenant->getKey()) {
            throw new LogicException('The Pulse cutover tenant must be a workspace owner.');
        }

        if (! $actor->isSuperadmin()
            && (int) $actor->getKey() !== (int) $tenant->getKey()) {
            throw new LogicException(
                'Pulse transport transitions require the workspace owner or a superadmin.',
            );
        }
    }

    private function assertMapping(
        User $tenant,
        SocialAccountConnection $legacyConnection,
        SocialAccountConnection $replacementConnection,
    ): void {
        $legacyKey = (string) $legacyConnection->logical_destination_key;
        $replacementKey = (string) $replacementConnection->logical_destination_key;

        if ((int) $legacyConnection->user_id !== (int) $tenant->getKey()
            || (int) $replacementConnection->user_id !== (int) $tenant->getKey()
            || (int) $legacyConnection->getKey() === (int) $replacementConnection->getKey()
            || (string) $legacyConnection->platform !== SocialAccountConnection::PLATFORM_FACEBOOK
            || (string) $replacementConnection->platform !== SocialAccountConnection::PLATFORM_FACEBOOK
            || (string) $legacyConnection->delivery_provider
                !== SocialAccountConnection::DELIVERY_PROVIDER_DIRECT
            || (string) $legacyConnection->transport_generation
                !== SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1
            || (string) $replacementConnection->delivery_provider
                !== SocialAccountConnection::DELIVERY_PROVIDER_BUFFER
            || (string) $replacementConnection->transport_generation
                !== SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1
            || (string) $replacementConnection->status
                !== SocialAccountConnection::STATUS_CONNECTED
            || ! (bool) $replacementConnection->is_active
            || preg_match('/\Aldk:v1:[0-9a-f]{64}\z/', $legacyKey) !== 1
            || ! hash_equals($legacyKey, $replacementKey)) {
            throw new LogicException(
                'The Pulse channel mapping must pair one Facebook direct destination with its exact replacement.',
            );
        }
    }

    private function resumeAllowedSuspendedOutboxes(int $tenantId, string $state): void
    {
        foreach ($this->transportIdentitiesForExistingEffects($state) as [
            $deliveryProvider,
            $transportGeneration,
        ]) {
            $this->deliveryOutboxes->resumeSuspendedForTenantTransport(
                $tenantId,
                $deliveryProvider,
                $transportGeneration,
            );
        }
    }

    private function assertNoAmbiguousRemoteEffects(int $tenantId): void
    {
        $ambiguousRemoteEffectExists = SocialDeliveryOutbox::query()
            ->where('user_id', $tenantId)
            ->where(function (Builder $query): void {
                $query
                    ->where('status', SocialDeliveryOutbox::STATUS_SUBMITTING)
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->where('status', SocialDeliveryOutbox::STATUS_UNKNOWN)
                            ->whereNull('reconciliation_resolved_at');
                    });
            })
            ->exists();

        if ($ambiguousRemoteEffectExists) {
            throw new LogicException(
                'Ambiguous Pulse deliveries must be reconciled before releasing rollback hold.',
            );
        }
    }

    /** @return list<array{0:string,1:string}> */
    private function transportIdentitiesForExistingEffects(string $state): array
    {
        return match ($state) {
            SocialTransportCutover::STATE_LEGACY_ONLY,
            SocialTransportCutover::STATE_CANARY_ARMED => [
                [
                    SocialAccountConnection::DELIVERY_PROVIDER_DIRECT,
                    SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
                ],
            ],
            SocialTransportCutover::STATE_CANARY_ACTIVE,
            SocialTransportCutover::STATE_DRAINING_LEGACY => [
                [
                    SocialAccountConnection::DELIVERY_PROVIDER_DIRECT,
                    SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
                ],
                [
                    SocialAccountConnection::DELIVERY_PROVIDER_BUFFER,
                    SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
                ],
            ],
            SocialTransportCutover::STATE_AWAITING_H3 => [
                [
                    SocialAccountConnection::DELIVERY_PROVIDER_BUFFER,
                    SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
                ],
            ],
            default => throw new LogicException(
                'The held Pulse transport state has no resumable transport identity.',
            ),
        };
    }

    private function normalizeEvidenceHash(string $evidenceHash): string
    {
        $evidenceHash = mb_strtolower(trim($evidenceHash));

        if (preg_match('/\A[0-9a-f]{64}\z/', $evidenceHash) !== 1) {
            throw new InvalidArgumentException('Pulse cutover evidence must be a SHA-256 hash.');
        }

        return $evidenceHash;
    }

    private function appendEvent(
        SocialTransportCutover $cutover,
        User $actor,
        string $fromState,
        string $toState,
        string $reason,
        string $evidenceHash,
    ): void {
        $sequence = (int) SocialTransportCutoverEvent::query()
            ->where('social_transport_cutover_id', $cutover->getKey())
            ->max('sequence') + 1;

        SocialTransportCutoverEvent::query()->create([
            'social_transport_cutover_id' => $cutover->getKey(),
            'user_id' => $cutover->user_id,
            'sequence' => $sequence,
            'from_state' => $fromState,
            'to_state' => $toState,
            'actor_user_id' => $actor->getKey(),
            'reason' => $reason,
            'evidence_hash' => $evidenceHash,
            'created_at' => now(),
        ]);
    }
}
