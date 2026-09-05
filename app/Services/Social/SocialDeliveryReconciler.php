<?php

namespace App\Services\Social;

use App\Data\Social\SocialDeliveryReconciliationClaimData;
use App\Data\Social\SocialDeliveryStatusResultData;
use App\Models\SocialAccountConnection;
use App\Models\SocialDeliveryOutbox;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Services\Social\Contracts\SocialDeliveryStatusGatewayInterface;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

final class SocialDeliveryReconciler
{
    private const TARGET_DELIVERY_SENDING = 'sending';

    public function __construct(
        private readonly SocialDeliveryStatusGatewayInterface $statusGateway,
        private readonly SocialReconciliationCadence $cadence,
        private readonly SocialOperationalMessageSanitizer $messageSanitizer,
        private readonly SocialDeliveryAggregateService $aggregateService,
        private readonly SocialConnectionDeliveryMutex $connectionDeliveryMutex,
    ) {}

    public function claim(
        int $tenantId,
        int $targetId,
        string $claimedBy,
        bool $force = false,
        int $leaseSeconds = 120,
    ): ?SocialDeliveryReconciliationClaimData {
        $this->assertClaimInput($tenantId, $targetId, $claimedBy, $leaseSeconds);

        if ($this->markUnavailableConnectionForReview($tenantId, $targetId)) {
            return null;
        }

        if ($this->markMissingRemoteIdentifierForReview($tenantId, $targetId)) {
            return null;
        }

        if ($this->markMissingDeliveryOutboxForReview($tenantId, $targetId)) {
            return null;
        }

        $now = $this->now();
        $claimToken = (string) Str::uuid();
        $claimExpiresAt = $now->addSeconds($leaseSeconds);
        $query = $this->eligibleClaimQuery($tenantId)
            ->whereKey($targetId)
            ->where(function (Builder $query) use ($now): void {
                $query
                    ->whereNull('reconcile_claim_expires_at')
                    ->orWhere('reconcile_claim_expires_at', '<=', $now);
            });

        if (! $force) {
            $query
                ->whereNotNull('next_reconcile_at')
                ->where('next_reconcile_at', '<=', $now);
        }

        $claimed = $query->update([
            'reconcile_claimed_at' => $now,
            'reconcile_claim_expires_at' => $claimExpiresAt,
            'reconcile_claimed_by' => trim($claimedBy),
            'reconcile_claim_token' => $claimToken,
            'reconcile_claim_version' => DB::raw('reconcile_claim_version + 1'),
            'updated_at' => $now,
        ]);

        if ($claimed !== 1) {
            return null;
        }

        $target = $this->eligibleClaimQuery($tenantId)
            ->whereKey($targetId)
            ->where('reconcile_claim_token', $claimToken)
            ->first();

        if (! $target) {
            return null;
        }

        return $this->claimData($tenantId, $target);
    }

    /**
     * @return array<int, SocialDeliveryReconciliationClaimData>
     */
    public function claimDueForTenant(
        int $tenantId,
        string $claimedBy,
        int $limit = 100,
        int $leaseSeconds = 120,
    ): array {
        $this->assertClaimInput($tenantId, 1, $claimedBy, $leaseSeconds);

        if ($limit <= 0 || $limit > 500) {
            throw new InvalidArgumentException(
                'The social delivery reconciliation batch size must be between 1 and 500.',
            );
        }

        $this->markDueUnavailableConnectionsForReview($tenantId, $limit);
        $this->markDueMissingRemoteIdentifiersForReview($tenantId, $limit);
        $this->markDueMissingDeliveryOutboxesForReview($tenantId, $limit);

        $now = $this->now();
        $targetIds = $this->eligibleClaimQuery($tenantId)
            ->whereNotNull('next_reconcile_at')
            ->where('next_reconcile_at', '<=', $now)
            ->where(function (Builder $query) use ($now): void {
                $query
                    ->whereNull('reconcile_claim_expires_at')
                    ->orWhere('reconcile_claim_expires_at', '<=', $now);
            })
            ->orderBy('next_reconcile_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        return $targetIds
            ->map(fn (mixed $targetId): ?SocialDeliveryReconciliationClaimData => $this->claim(
                $tenantId,
                (int) $targetId,
                $claimedBy,
                false,
                $leaseSeconds,
            ))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{claimed:int,reconciled:int,not_applied:int}
     */
    public function reconcileDueForTenant(
        int $tenantId,
        string $claimedBy,
        int $limit = 100,
        int $leaseSeconds = 120,
    ): array {
        $claims = $this->claimDueForTenant($tenantId, $claimedBy, $limit, $leaseSeconds);
        $reconciled = 0;

        foreach ($claims as $claim) {
            if ($this->reconcile($claim)) {
                $reconciled++;
            }
        }

        return [
            'claimed' => count($claims),
            'reconciled' => $reconciled,
            'not_applied' => count($claims) - $reconciled,
        ];
    }

    /**
     * @return array{selected:int,claimed:int,reconciled:int,not_applied:int}
     */
    public function reconcileDueBufferDeliveries(
        string $claimedBy,
        int $limit = 100,
        int $leaseSeconds = 120,
    ): array {
        $this->assertClaimInput(1, 1, $claimedBy, $leaseSeconds);

        if ($limit <= 0 || $limit > 500) {
            throw new InvalidArgumentException(
                'The social delivery reconciliation batch size must be between 1 and 500.',
            );
        }

        $dueTargets = SocialPostTarget::query()
            ->join('social_posts', 'social_posts.id', '=', 'social_post_targets.social_post_id')
            ->where(
                'social_post_targets.delivery_provider',
                SocialAccountConnection::DELIVERY_PROVIDER_BUFFER,
            )
            ->where(
                'social_post_targets.transport_generation',
                SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
            )
            ->whereNotNull('social_post_targets.next_reconcile_at')
            ->where('social_post_targets.next_reconcile_at', '<=', $this->now())
            ->orderBy('social_post_targets.next_reconcile_at')
            ->orderBy('social_post_targets.id')
            ->limit($limit)
            ->get([
                'social_post_targets.id',
                'social_posts.user_id as tenant_id',
            ]);
        $claimed = 0;
        $reconciled = 0;
        $notApplied = 0;

        foreach ($dueTargets as $target) {
            $claim = $this->claim(
                (int) $target->getAttribute('tenant_id'),
                (int) $target->getKey(),
                $claimedBy,
                false,
                $leaseSeconds,
            );

            if (! $claim instanceof SocialDeliveryReconciliationClaimData) {
                $notApplied++;

                continue;
            }

            $claimed++;

            if ($this->reconcile($claim)) {
                $reconciled++;
            } else {
                $notApplied++;
            }
        }

        return [
            'selected' => $dueTargets->count(),
            'claimed' => $claimed,
            'reconciled' => $reconciled,
            'not_applied' => $notApplied,
        ];
    }

    public function synchronizeManually(
        int $tenantId,
        int $targetId,
        string $claimedBy,
        int $leaseSeconds = 120,
    ): bool {
        $claim = $this->claim($tenantId, $targetId, $claimedBy, true, $leaseSeconds);

        return $claim ? $this->reconcile($claim) : false;
    }

    public function reconcile(SocialDeliveryReconciliationClaimData $claim): bool
    {
        if (! $this->claimIsActive($claim)) {
            return false;
        }

        $connectionLock = $this->connectionDeliveryMutex->acquire($claim->connectionId);

        if ($connectionLock === null) {
            $this->releaseAfterMutexContention($claim);

            return false;
        }

        try {
            if (! $this->claimIsActive($claim)) {
                return false;
            }

            if (! $this->connectionSnapshotMatchesClaim($claim)) {
                $this->releaseForOperatorReview(
                    $claim,
                    'connection_identity_mismatch',
                    'The social delivery connection no longer matches its tenant-scoped snapshot.',
                );

                return false;
            }

            if (! $this->reserveStatusReadAttempt($claim)) {
                return false;
            }

            try {
                $result = $this->statusGateway->readStatus($claim->statusRequest());
            } catch (Throwable $exception) {
                $this->releaseAfterReadFailure($claim, $exception);

                return false;
            }

            return $this->applyResult($claim, $result);
        } finally {
            $connectionLock->release();
        }
    }

    private function reserveStatusReadAttempt(
        SocialDeliveryReconciliationClaimData $claim,
    ): bool {
        $outcome = $this->mutateTargetDurably(
            $claim->tenantId,
            $claim->postId,
            $claim->targetId,
            function (
                SocialPostTarget $target,
                ?SocialDeliveryOutbox $outbox,
            ) use ($claim): array {
                if (! $outbox || ! $this->outboxMatchesClaim($outbox, $claim)) {
                    return [
                        'target' => [
                            'sync_status' => SocialPost::SYNC_STATUS_ERROR,
                            'next_reconcile_at' => null,
                            'provider_error_code' => 'missing_delivery_outbox',
                            'provider_error_message' => $this->messageSanitizer->sanitize(
                                'The exact current delivery outbox is unavailable; operator review is required.',
                            ),
                            ...$this->releasedClaimAttributes(),
                        ],
                        'outcome' => false,
                    ];
                }

                $currentStatus = $this->normalizedStatusForTarget($target)
                    ?? SocialDeliveryStatusResultData::STATUS_UNKNOWN;
                $completedAttempts = (int) $target->reconcile_attempts;

                if (! $this->cadence->canReserve($currentStatus, $completedAttempts)) {
                    return [
                        'target' => [
                            'next_reconcile_at' => null,
                            ...$this->releasedClaimAttributes(),
                        ],
                        'aggregate_relevant' => false,
                        'outcome' => false,
                    ];
                }

                $reservedAttempt = $completedAttempts + 1;

                return [
                    'target' => [
                        'reconcile_attempts' => $reservedAttempt,
                        'next_reconcile_at' => $this->cadence->nextAt(
                            $currentStatus,
                            $target->remote_scheduled_for === null
                                ? null
                                : CarbonImmutable::instance($target->remote_scheduled_for)->utc(),
                            $reservedAttempt,
                            $this->now(),
                        ),
                    ],
                    'aggregate_relevant' => false,
                    'outcome' => true,
                ];
            },
            $claim,
        );

        return $outcome ?? false;
    }

    private function applyResult(
        SocialDeliveryReconciliationClaimData $claim,
        SocialDeliveryStatusResultData $result,
    ): bool {
        $outcome = $this->mutateTargetDurably(
            $claim->tenantId,
            $claim->postId,
            $claim->targetId,
            function (
                SocialPostTarget $target,
                ?SocialDeliveryOutbox $outbox,
            ) use ($claim, $result): array {
                if (! $outbox || ! $this->outboxMatchesClaim($outbox, $claim)) {
                    return [
                        'target' => [
                            'sync_status' => SocialPost::SYNC_STATUS_ERROR,
                            'next_reconcile_at' => null,
                            'provider_error_code' => 'missing_delivery_outbox',
                            'provider_error_message' => $this->messageSanitizer->sanitize(
                                'The exact current delivery outbox disappeared before the observation could be applied; operator review is required.',
                            ),
                            ...$this->releasedClaimAttributes(),
                        ],
                        'outcome' => false,
                    ];
                }

                $nextReconcileAt = $target->next_reconcile_at === null
                    ? null
                    : CarbonImmutable::instance($target->next_reconcile_at)->utc();

                if ($target->last_synced_at !== null
                    && $result->observedAt->lessThanOrEqualTo(
                        CarbonImmutable::instance($target->last_synced_at)->utc(),
                    )) {
                    return [
                        'target' => [
                            'sync_status' => $nextReconcileAt === null
                                ? SocialPost::SYNC_STATUS_ERROR
                                : $target->sync_status,
                            'provider_error_code' => $nextReconcileAt === null
                                ? 'stale_status_observation'
                                : $target->provider_error_code,
                            'provider_error_message' => $nextReconcileAt === null
                                ? $this->messageSanitizer->sanitize(
                                    'Repeated stale status observations require operator review.',
                                )
                                : $target->provider_error_message,
                            ...$this->releasedClaimAttributes(),
                        ],
                        'outcome' => false,
                    ];
                }

                $currentNormalizedStatus = $this->normalizedStatusForTarget($target);

                if (! $this->transitionIsMonotone($currentNormalizedStatus, $result->status)) {
                    return [
                        'target' => [
                            'sync_status' => SocialPost::SYNC_STATUS_ERROR,
                            'next_reconcile_at' => null,
                            'provider_error_code' => 'non_monotone_remote_status',
                            'provider_error_message' => $this->messageSanitizer->sanitize(
                                'The remote delivery status regressed and requires operator review.',
                            ),
                            ...$this->releasedClaimAttributes(),
                        ],
                        'outcome' => false,
                    ];
                }

                $providerStatus = $result->providerStatus ?? $result->status;
                $attempts = $currentNormalizedStatus === $result->status
                    ? (int) $target->reconcile_attempts
                    : 1;
                $now = $this->now();

                return [
                    'target' => [
                        ...$this->targetAttributes($result, $providerStatus, $now),
                        'provider_status' => $providerStatus,
                        'remote_scheduled_for' => $result->remoteScheduledFor,
                        'last_synced_at' => $result->observedAt,
                        'next_reconcile_at' => $this->cadence->nextAt(
                            $result->status,
                            $result->remoteScheduledFor,
                            $attempts,
                            $now,
                        ),
                        'reconcile_attempts' => $attempts,
                        ...$this->releasedClaimAttributes(),
                    ],
                    'outbox' => $this->outboxObservationAttributes($outbox, $result, $now),
                    'outcome' => true,
                ];
            },
            $claim,
        );

        return $outcome ?? false;
    }

    /**
     * @param  Closure(SocialPostTarget, ?SocialDeliveryOutbox): ?array{
     *     target: array<string, mixed>,
     *     outcome: bool,
     *     aggregate_relevant?: bool,
     *     outbox?: array<string, mixed>
     * }  $mutation
     */
    private function mutateTargetDurably(
        int $tenantId,
        int $postId,
        int $targetId,
        Closure $mutation,
        ?SocialDeliveryReconciliationClaimData $claim = null,
    ): ?bool {
        $result = DB::transaction(function () use (
            $tenantId,
            $postId,
            $targetId,
            $mutation,
            $claim,
        ): ?array {
            $post = SocialPost::query()
                ->whereKey($postId)
                ->where('user_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if (! $post) {
                return null;
            }

            $targetQuery = SocialPostTarget::query()
                ->whereKey($targetId)
                ->where('social_post_id', $postId);

            if ($claim) {
                $targetQuery
                    ->where('social_account_connection_id', $claim->connectionId)
                    ->where('reconcile_claim_token', $claim->claimToken)
                    ->where('reconcile_claim_version', $claim->claimVersion)
                    ->where('reconcile_claim_expires_at', '>', $this->now());
            }

            $target = $targetQuery->lockForUpdate()->first();

            if (! $target || ($claim && ! $this->claimSnapshotMatches($target, $claim))) {
                return null;
            }

            $outbox = $this->lockAggregateRepairOutboxForTarget($tenantId, $target);
            $decision = $mutation($target, $outbox);

            if ($decision === null) {
                return null;
            }

            if (! $target->forceFill($decision['target'])->save()) {
                throw new LogicException(
                    'The social delivery reconciliation target mutation could not be persisted.',
                );
            }

            $aggregateRelevant = $decision['aggregate_relevant'] ?? true;
            $refreshAfterCommit = false;

            if ($aggregateRelevant && $outbox) {
                $this->persistOutboxAggregateMutation(
                    $outbox,
                    $decision['outbox'] ?? [],
                );
                $refreshAfterCommit = true;
            } elseif ($aggregateRelevant
                && ! $this->aggregateService->refreshForTenant($tenantId, $postId)) {
                throw new LogicException(
                    'The outboxless social delivery target and its aggregate could not be mutated atomically.',
                );
            }

            return [
                'outcome' => (bool) $decision['outcome'],
                'refresh_after_commit' => $refreshAfterCommit,
            ];
        }, 3);

        if ($result === null) {
            return null;
        }

        if ($result['refresh_after_commit']) {
            $this->aggregateService->refreshForTenant($tenantId, $postId);
        }

        return $result['outcome'];
    }

    private function eligibleClaimQuery(int $tenantId): Builder
    {
        return SocialPostTarget::query()
            ->whereHas('socialPost', function (Builder $query) use ($tenantId): void {
                $query->where('user_id', $tenantId);
            })
            ->whereExists(function (QueryBuilder $query) use ($tenantId): void {
                $query
                    ->selectRaw('1')
                    ->from('social_account_connections')
                    ->whereColumn(
                        'social_account_connections.id',
                        'social_post_targets.social_account_connection_id',
                    )
                    ->where('social_account_connections.user_id', $tenantId)
                    ->where('social_account_connections.status', SocialAccountConnection::STATUS_CONNECTED)
                    ->where('social_account_connections.is_active', true)
                    ->whereColumn(
                        'social_account_connections.delivery_provider',
                        'social_post_targets.delivery_provider',
                    )
                    ->whereColumn(
                        'social_account_connections.transport_generation',
                        'social_post_targets.transport_generation',
                    )
                    ->whereColumn(
                        'social_account_connections.logical_destination_key',
                        'social_post_targets.logical_destination_key',
                    );
            })
            ->whereNotNull('provider_post_id')
            ->where('provider_post_id', '!=', '')
            ->whereNotNull('delivery_provider')
            ->whereNotNull('transport_generation')
            ->whereNotNull('logical_destination_key')
            ->whereNotNull('current_revision_id')
            ->whereNotNull('last_submitted_revision_id')
            ->whereNotNull('payload_hash')
            ->where('status', '!=', SocialPostTarget::STATUS_CANCELED)
            ->whereIn('delivery_status', $this->reconcilableDeliveryStatuses());
    }

    private function claimData(
        int $tenantId,
        SocialPostTarget $target,
    ): SocialDeliveryReconciliationClaimData {
        return new SocialDeliveryReconciliationClaimData(
            tenantId: $tenantId,
            postId: (int) $target->social_post_id,
            targetId: (int) $target->id,
            connectionId: (int) $target->social_account_connection_id,
            providerPostId: (string) $target->provider_post_id,
            deliveryProvider: (string) $target->delivery_provider,
            transportGeneration: (string) $target->transport_generation,
            logicalDestinationKey: (string) $target->logical_destination_key,
            claimToken: (string) $target->reconcile_claim_token,
            claimVersion: (int) $target->reconcile_claim_version,
            claimExpiresAt: CarbonImmutable::instance($target->reconcile_claim_expires_at)->utc(),
            attempts: (int) $target->reconcile_attempts,
            originalNextReconcileAt: $target->next_reconcile_at === null
                ? null
                : CarbonImmutable::instance($target->next_reconcile_at)->utc(),
            expectedLegacyStatus: (string) $target->status,
            expectedDeliveryStatus: (string) $target->delivery_status,
            expectedSyncStatus: (string) $target->sync_status,
            expectedCurrentRevisionId: (int) $target->current_revision_id,
            expectedLastSubmittedRevisionId: (int) $target->last_submitted_revision_id,
            expectedPayloadHash: (string) $target->payload_hash,
        );
    }

    private function claimIsActive(SocialDeliveryReconciliationClaimData $claim): bool
    {
        $target = $this->claimedTargetQuery($claim)->first();

        return $target !== null && $this->claimSnapshotMatches($target, $claim);
    }

    private function claimedTargetQuery(SocialDeliveryReconciliationClaimData $claim): Builder
    {
        return SocialPostTarget::query()
            ->whereKey($claim->targetId)
            ->whereHas('socialPost', function (Builder $query) use ($claim): void {
                $query
                    ->whereKey($claim->postId)
                    ->where('user_id', $claim->tenantId);
            })
            ->where('social_post_id', $claim->postId)
            ->where('social_account_connection_id', $claim->connectionId)
            ->where('reconcile_claim_token', $claim->claimToken)
            ->where('reconcile_claim_version', $claim->claimVersion)
            ->where('reconcile_claim_expires_at', '>', $this->now());
    }

    private function claimSnapshotMatches(
        SocialPostTarget $target,
        SocialDeliveryReconciliationClaimData $claim,
    ): bool {
        return hash_equals((string) $target->provider_post_id, $claim->providerPostId)
            && hash_equals((string) $target->delivery_provider, $claim->deliveryProvider)
            && hash_equals((string) $target->transport_generation, $claim->transportGeneration)
            && hash_equals((string) $target->logical_destination_key, $claim->logicalDestinationKey)
            && hash_equals((string) $target->payload_hash, $claim->expectedPayloadHash)
            && (string) $target->status === $claim->expectedLegacyStatus
            && (string) $target->delivery_status === $claim->expectedDeliveryStatus
            && (string) $target->sync_status === $claim->expectedSyncStatus
            && (int) $target->current_revision_id === $claim->expectedCurrentRevisionId
            && (int) $target->last_submitted_revision_id === $claim->expectedLastSubmittedRevisionId;
    }

    private function connectionSnapshotMatchesClaim(
        SocialDeliveryReconciliationClaimData $claim,
    ): bool {
        return SocialAccountConnection::query()
            ->whereKey($claim->connectionId)
            ->where('user_id', $claim->tenantId)
            ->where('delivery_provider', $claim->deliveryProvider)
            ->where('transport_generation', $claim->transportGeneration)
            ->where('logical_destination_key', $claim->logicalDestinationKey)
            ->where('status', SocialAccountConnection::STATUS_CONNECTED)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function outboxObservationAttributes(
        SocialDeliveryOutbox $outbox,
        SocialDeliveryStatusResultData $result,
        CarbonImmutable $observedAt,
    ): array {
        if ((string) $outbox->status === SocialDeliveryOutbox::STATUS_UNKNOWN
            && $outbox->reconciliation_resolved_at === null
            && in_array($result->status, [
                SocialDeliveryStatusResultData::STATUS_SENT,
                SocialDeliveryStatusResultData::STATUS_ERROR,
            ], true)) {
            return [
                'reconciliation_resolved_at' => $observedAt,
                'reconciliation_observed_at' => $result->observedAt,
                'reconciliation_resolution' => $result->status,
                'reconciliation_resolution_source' => SocialDeliveryOutbox::RECONCILIATION_SOURCE_STATUS_READ,
            ];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persistOutboxAggregateMutation(
        SocialDeliveryOutbox $outbox,
        array $attributes,
    ): void {
        if (! $outbox->forceFill([
            'aggregate_repaired_at' => null,
            ...$attributes,
        ])->save()) {
            throw new LogicException(
                'The current delivery outbox could not be updated with its observation.',
            );
        }
    }

    private function lockAggregateRepairOutboxForTarget(
        int $tenantId,
        SocialPostTarget $target,
    ): ?SocialDeliveryOutbox {
        if ((int) $target->last_submitted_revision_id <= 0) {
            return null;
        }

        $outbox = $this->currentOutboxQuery(
            $tenantId,
            (int) $target->getKey(),
            (int) $target->last_submitted_revision_id,
        )->lockForUpdate()->first();

        return $outbox && $this->outboxCanAnchorAggregateRepair($outbox, $target)
            ? $outbox
            : null;
    }

    private function outboxCanAnchorAggregateRepair(
        SocialDeliveryOutbox $outbox,
        SocialPostTarget $target,
    ): bool {
        return (int) $outbox->social_provider_connection_id
                === (int) $target->social_account_connection_id
            && (string) $outbox->delivery_provider === (string) $target->delivery_provider
            && (string) $outbox->transport_generation === (string) $target->transport_generation
            && hash_equals(
                (string) $outbox->logical_destination_key,
                (string) $target->logical_destination_key,
            )
            && in_array((string) $outbox->status, [
                SocialDeliveryOutbox::STATUS_UNKNOWN,
                SocialDeliveryOutbox::STATUS_COMPLETED,
                SocialDeliveryOutbox::STATUS_DEAD,
            ], true)
            && hash_equals(
                (string) $outbox->provider_post_id,
                (string) $target->provider_post_id,
            );
    }

    private function currentOutboxIsReadableForTarget(
        SocialDeliveryOutbox $outbox,
        SocialPostTarget $target,
    ): bool {
        return $this->outboxCanAnchorAggregateRepair($outbox, $target)
            && in_array((string) $outbox->status, [
                SocialDeliveryOutbox::STATUS_UNKNOWN,
                SocialDeliveryOutbox::STATUS_COMPLETED,
            ], true)
            && trim((string) $outbox->provider_post_id) !== '';
    }

    private function outboxMatchesClaim(
        SocialDeliveryOutbox $outbox,
        SocialDeliveryReconciliationClaimData $claim,
    ): bool {
        return (int) $outbox->social_provider_connection_id === $claim->connectionId
            && (string) $outbox->delivery_provider === $claim->deliveryProvider
            && (string) $outbox->transport_generation === $claim->transportGeneration
            && hash_equals(
                (string) $outbox->logical_destination_key,
                $claim->logicalDestinationKey,
            )
            && in_array((string) $outbox->status, [
                SocialDeliveryOutbox::STATUS_UNKNOWN,
                SocialDeliveryOutbox::STATUS_COMPLETED,
            ], true)
            && trim((string) $outbox->provider_post_id) !== ''
            && hash_equals((string) $outbox->provider_post_id, $claim->providerPostId);
    }

    private function currentOutboxMatchesTarget(
        int $tenantId,
        SocialPostTarget $target,
    ): bool {
        $outbox = $this->currentOutboxQuery(
            $tenantId,
            (int) $target->getKey(),
            (int) $target->last_submitted_revision_id,
        )->first();

        return $outbox !== null
            && $this->currentOutboxIsReadableForTarget($outbox, $target);
    }

    private function currentOutboxQuery(
        int $tenantId,
        int $targetId,
        int $revisionId,
    ): Builder {
        return SocialDeliveryOutbox::query()
            ->where('user_id', $tenantId)
            ->where('social_post_target_id', $targetId)
            ->where('social_post_revision_id', $revisionId)
            ->where('operation', SocialDeliveryOutbox::OPERATION_CREATE)
            ->orderByDesc('recovery_generation')
            ->orderByDesc('id');
    }

    private function connectionIsReadyForTarget(
        int $tenantId,
        SocialPostTarget $target,
    ): bool {
        return SocialAccountConnection::query()
            ->whereKey($target->social_account_connection_id)
            ->where('user_id', $tenantId)
            ->where('status', SocialAccountConnection::STATUS_CONNECTED)
            ->where('is_active', true)
            ->where('delivery_provider', $target->delivery_provider)
            ->where('transport_generation', $target->transport_generation)
            ->where('logical_destination_key', $target->logical_destination_key)
            ->exists();
    }

    private function normalizedStatusForTarget(SocialPostTarget $target): ?string
    {
        return match ((string) $target->delivery_status) {
            SocialPost::DELIVERY_STATUS_PUBLISHED => SocialDeliveryStatusResultData::STATUS_SENT,
            SocialPost::DELIVERY_STATUS_FAILED => SocialDeliveryStatusResultData::STATUS_ERROR,
            SocialPost::DELIVERY_STATUS_SCHEDULED => SocialDeliveryStatusResultData::STATUS_SCHEDULED,
            SocialPost::DELIVERY_STATUS_PUBLISHING,
            self::TARGET_DELIVERY_SENDING => SocialDeliveryStatusResultData::STATUS_SENDING,
            SocialPost::DELIVERY_STATUS_REMOTE_APPROVAL_REQUIRED => SocialDeliveryStatusResultData::STATUS_APPROVAL_REQUIRED,
            SocialPost::DELIVERY_STATUS_UNKNOWN => SocialDeliveryStatusResultData::STATUS_UNKNOWN,
            default => null,
        };
    }

    private function transitionIsMonotone(?string $currentStatus, string $observedStatus): bool
    {
        return match ($currentStatus) {
            SocialDeliveryStatusResultData::STATUS_SENT => $observedStatus
                === SocialDeliveryStatusResultData::STATUS_SENT,
            SocialDeliveryStatusResultData::STATUS_ERROR => $observedStatus
                === SocialDeliveryStatusResultData::STATUS_ERROR,
            SocialDeliveryStatusResultData::STATUS_SENDING => in_array($observedStatus, [
                SocialDeliveryStatusResultData::STATUS_SENDING,
                SocialDeliveryStatusResultData::STATUS_SENT,
                SocialDeliveryStatusResultData::STATUS_ERROR,
            ], true),
            default => true,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function targetAttributes(
        SocialDeliveryStatusResultData $result,
        string $providerStatus,
        CarbonImmutable $now,
    ): array {
        $errorMessage = $this->messageSanitizer->sanitize($result->errorMessage);
        $scheduledIsOverdue = $result->status === SocialDeliveryStatusResultData::STATUS_SCHEDULED
            && $result->remoteScheduledFor !== null
            && $result->remoteScheduledFor->addMinutes(30)->lessThan($now);

        return match ($result->status) {
            SocialDeliveryStatusResultData::STATUS_SENT => [
                'status' => SocialPostTarget::STATUS_PUBLISHED,
                'delivery_status' => SocialPost::DELIVERY_STATUS_PUBLISHED,
                'sync_status' => SocialPost::SYNC_STATUS_SYNCED,
                'published_at' => $result->observedAt,
                'failed_at' => null,
                'failure_reason' => null,
                'provider_error_code' => null,
                'provider_error_message' => null,
            ],
            SocialDeliveryStatusResultData::STATUS_ERROR => [
                'status' => SocialPostTarget::STATUS_FAILED,
                'delivery_status' => SocialPost::DELIVERY_STATUS_FAILED,
                'sync_status' => SocialPost::SYNC_STATUS_SYNCED,
                'published_at' => null,
                'failed_at' => $result->observedAt,
                'failure_reason' => $errorMessage ?? 'The remote social delivery failed.',
                'provider_error_code' => $result->errorCode ?? 'remote_delivery_failed',
                'provider_error_message' => $errorMessage ?? 'The remote social delivery failed.',
            ],
            SocialDeliveryStatusResultData::STATUS_SCHEDULED => [
                'status' => SocialPostTarget::STATUS_SCHEDULED,
                'delivery_status' => SocialPost::DELIVERY_STATUS_SCHEDULED,
                'sync_status' => $result->remoteScheduledFor && ! $scheduledIsOverdue
                    ? SocialPost::SYNC_STATUS_SYNCED
                    : SocialPost::SYNC_STATUS_ERROR,
                'published_at' => null,
                'failed_at' => null,
                'failure_reason' => null,
                'provider_error_code' => match (true) {
                    $result->remoteScheduledFor === null => 'remote_schedule_missing',
                    $scheduledIsOverdue => 'scheduled_delivery_overdue',
                    default => null,
                },
                'provider_error_message' => match (true) {
                    $result->remoteScheduledFor === null => 'The remote schedule did not include a delivery time.',
                    $scheduledIsOverdue => 'The scheduled delivery exceeded its reconciliation window and requires operator review.',
                    default => null,
                },
            ],
            SocialDeliveryStatusResultData::STATUS_SENDING => [
                'status' => SocialPostTarget::STATUS_PUBLISHING,
                'delivery_status' => self::TARGET_DELIVERY_SENDING,
                'sync_status' => SocialPost::SYNC_STATUS_SYNCED,
                'published_at' => null,
                'failed_at' => null,
                'failure_reason' => null,
                'provider_error_code' => null,
                'provider_error_message' => null,
            ],
            SocialDeliveryStatusResultData::STATUS_APPROVAL_REQUIRED,
            SocialDeliveryStatusResultData::STATUS_DRAFT => [
                'status' => SocialPostTarget::STATUS_PUBLISHING,
                'delivery_status' => SocialPost::DELIVERY_STATUS_REMOTE_APPROVAL_REQUIRED,
                'sync_status' => SocialPost::SYNC_STATUS_ERROR,
                'published_at' => null,
                'failed_at' => null,
                'failure_reason' => null,
                'provider_error_code' => 'remote_editorial_divergence',
                'provider_error_message' => $this->messageSanitizer->sanitize(
                    sprintf(
                        'Remote status [%s] requires operator review; Malikia remains the editorial authority.',
                        $providerStatus,
                    ),
                ),
            ],
            default => [
                'status' => SocialPostTarget::STATUS_FAILED,
                'delivery_status' => SocialPost::DELIVERY_STATUS_UNKNOWN,
                'sync_status' => SocialPost::SYNC_STATUS_ERROR,
                'published_at' => null,
                'failed_at' => $now,
                'failure_reason' => $errorMessage ?? 'The remote social delivery status is unknown.',
                'provider_error_code' => $result->errorCode ?? 'remote_status_unknown',
                'provider_error_message' => $errorMessage ?? 'The remote social delivery status is unknown.',
            ],
        };
    }

    private function releaseAfterReadFailure(
        SocialDeliveryReconciliationClaimData $claim,
        Throwable $exception,
    ): bool {
        return $this->mutateTargetDurably(
            $claim->tenantId,
            $claim->postId,
            $claim->targetId,
            fn (SocialPostTarget $_target, ?SocialDeliveryOutbox $_outbox): array => [
                'target' => [
                    'sync_status' => SocialPost::SYNC_STATUS_ERROR,
                    'provider_error_code' => 'status_read_failed',
                    'provider_error_message' => $this->messageSanitizer->sanitize(
                        $exception->getMessage(),
                        'The remote social delivery status could not be read.',
                    ),
                    ...$this->releasedClaimAttributes(),
                ],
                'outcome' => true,
            ],
            $claim,
        ) ?? false;
    }

    private function releaseAfterMutexContention(
        SocialDeliveryReconciliationClaimData $claim,
    ): bool {
        return $this->mutateTargetDurably(
            $claim->tenantId,
            $claim->postId,
            $claim->targetId,
            fn (SocialPostTarget $_target, ?SocialDeliveryOutbox $_outbox): array => [
                'target' => [
                    'next_reconcile_at' => $this->mutexContentionNextAt($claim),
                    ...$this->releasedClaimAttributes(),
                ],
                'aggregate_relevant' => false,
                'outcome' => true,
            ],
            $claim,
        ) ?? false;
    }

    private function mutexContentionNextAt(
        SocialDeliveryReconciliationClaimData $claim,
    ): ?CarbonImmutable {
        if ($claim->originalNextReconcileAt === null) {
            return null;
        }

        $backoff = $this->now()->addMinute();

        return $claim->originalNextReconcileAt->greaterThan($backoff)
            ? $claim->originalNextReconcileAt
            : $backoff;
    }

    private function releaseForOperatorReview(
        SocialDeliveryReconciliationClaimData $claim,
        string $errorCode,
        string $message,
    ): bool {
        return $this->mutateTargetDurably(
            $claim->tenantId,
            $claim->postId,
            $claim->targetId,
            fn (SocialPostTarget $_target, ?SocialDeliveryOutbox $_outbox): array => [
                'target' => [
                    'sync_status' => SocialPost::SYNC_STATUS_ERROR,
                    'next_reconcile_at' => null,
                    'provider_error_code' => $errorCode,
                    'provider_error_message' => $this->messageSanitizer->sanitize($message),
                    ...$this->releasedClaimAttributes(),
                ],
                'outcome' => true,
            ],
            $claim,
        ) ?? false;
    }

    private function markMissingRemoteIdentifierForReview(int $tenantId, int $targetId): bool
    {
        $target = SocialPostTarget::query()
            ->whereKey($targetId)
            ->whereHas('socialPost', function (Builder $query) use ($tenantId): void {
                $query->where('user_id', $tenantId);
            })
            ->where('status', '!=', SocialPostTarget::STATUS_CANCELED)
            ->where('delivery_status', SocialPost::DELIVERY_STATUS_UNKNOWN)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('provider_post_id')
                    ->orWhere('provider_post_id', '');
            })
            ->first(['id', 'social_post_id']);

        if (! $target) {
            return false;
        }

        return $this->mutateTargetDurably(
            $tenantId,
            (int) $target->social_post_id,
            $targetId,
            function (
                SocialPostTarget $lockedTarget,
                ?SocialDeliveryOutbox $_outbox,
            ): ?array {
                if ((string) $lockedTarget->status === SocialPostTarget::STATUS_CANCELED
                    || (string) $lockedTarget->delivery_status
                        !== SocialPost::DELIVERY_STATUS_UNKNOWN
                    || trim((string) $lockedTarget->provider_post_id) !== '') {
                    return null;
                }

                return [
                    'target' => [
                        'sync_status' => SocialPost::SYNC_STATUS_ERROR,
                        'next_reconcile_at' => null,
                        'provider_error_code' => 'remote_identifier_missing',
                        'provider_error_message' => $this->messageSanitizer->sanitize(
                            'The remote delivery identifier is unavailable; operator review is required.',
                        ),
                    ],
                    'outcome' => true,
                ];
            },
        ) ?? false;
    }

    private function markMissingDeliveryOutboxForReview(int $tenantId, int $targetId): bool
    {
        $target = SocialPostTarget::query()
            ->whereKey($targetId)
            ->whereHas('socialPost', function (Builder $query) use ($tenantId): void {
                $query->where('user_id', $tenantId);
            })
            ->where('status', '!=', SocialPostTarget::STATUS_CANCELED)
            ->whereIn('delivery_status', $this->reconcilableDeliveryStatuses())
            ->whereNotNull('provider_post_id')
            ->where('provider_post_id', '!=', '')
            ->whereNotNull('last_submitted_revision_id')
            ->first([
                'id',
                'social_post_id',
                'social_account_connection_id',
                'delivery_provider',
                'transport_generation',
                'logical_destination_key',
                'last_submitted_revision_id',
                'provider_post_id',
            ]);

        if (! $target || $this->currentOutboxMatchesTarget($tenantId, $target)) {
            return false;
        }

        return $this->mutateTargetDurably(
            $tenantId,
            (int) $target->social_post_id,
            $targetId,
            function (
                SocialPostTarget $lockedTarget,
                ?SocialDeliveryOutbox $outbox,
            ): ?array {
                if ((string) $lockedTarget->status === SocialPostTarget::STATUS_CANCELED
                    || ! in_array(
                        (string) $lockedTarget->delivery_status,
                        $this->reconcilableDeliveryStatuses(),
                        true,
                    )
                    || trim((string) $lockedTarget->provider_post_id) === ''
                    || (int) $lockedTarget->last_submitted_revision_id <= 0
                    || ($outbox && $this->currentOutboxIsReadableForTarget(
                        $outbox,
                        $lockedTarget,
                    ))) {
                    return null;
                }

                return [
                    'target' => [
                        'sync_status' => SocialPost::SYNC_STATUS_ERROR,
                        'next_reconcile_at' => null,
                        'provider_error_code' => 'missing_delivery_outbox',
                        'provider_error_message' => $this->messageSanitizer->sanitize(
                            'The exact current delivery outbox is unavailable; operator review is required.',
                        ),
                    ],
                    'outcome' => true,
                ];
            },
        ) ?? false;
    }

    private function markUnavailableConnectionForReview(int $tenantId, int $targetId): bool
    {
        $target = SocialPostTarget::query()
            ->whereKey($targetId)
            ->whereHas('socialPost', function (Builder $query) use ($tenantId): void {
                $query->where('user_id', $tenantId);
            })
            ->where('status', '!=', SocialPostTarget::STATUS_CANCELED)
            ->whereIn('delivery_status', $this->reconcilableDeliveryStatuses())
            ->first([
                'id',
                'social_post_id',
                'social_account_connection_id',
                'delivery_provider',
                'transport_generation',
                'logical_destination_key',
            ]);

        if (! $target) {
            return false;
        }

        if ($this->connectionIsReadyForTarget($tenantId, $target)) {
            return false;
        }

        return $this->mutateTargetDurably(
            $tenantId,
            (int) $target->social_post_id,
            $targetId,
            function (
                SocialPostTarget $lockedTarget,
                ?SocialDeliveryOutbox $_outbox,
            ) use ($tenantId): ?array {
                if ((string) $lockedTarget->status === SocialPostTarget::STATUS_CANCELED
                    || ! in_array(
                        (string) $lockedTarget->delivery_status,
                        $this->reconcilableDeliveryStatuses(),
                        true,
                    )
                    || $this->connectionIsReadyForTarget($tenantId, $lockedTarget)) {
                    return null;
                }

                return [
                    'target' => [
                        'sync_status' => SocialPost::SYNC_STATUS_ERROR,
                        'next_reconcile_at' => null,
                        'provider_error_code' => 'connection_unavailable_for_reconciliation',
                        'provider_error_message' => $this->messageSanitizer->sanitize(
                            'The social delivery connection is unavailable or its tenant snapshot changed; operator review is required.',
                        ),
                    ],
                    'outcome' => true,
                ];
            },
        ) ?? false;
    }

    private function markDueUnavailableConnectionsForReview(int $tenantId, int $limit): int
    {
        $now = $this->now();
        $targetIds = SocialPostTarget::query()
            ->whereHas('socialPost', function (Builder $query) use ($tenantId): void {
                $query->where('user_id', $tenantId);
            })
            ->where('status', '!=', SocialPostTarget::STATUS_CANCELED)
            ->whereIn('delivery_status', $this->reconcilableDeliveryStatuses())
            ->whereNotNull('next_reconcile_at')
            ->where('next_reconcile_at', '<=', $now)
            ->whereNotExists(function (QueryBuilder $query) use ($tenantId): void {
                $query
                    ->selectRaw('1')
                    ->from('social_account_connections')
                    ->whereColumn(
                        'social_account_connections.id',
                        'social_post_targets.social_account_connection_id',
                    )
                    ->where('social_account_connections.user_id', $tenantId)
                    ->where('social_account_connections.status', SocialAccountConnection::STATUS_CONNECTED)
                    ->where('social_account_connections.is_active', true)
                    ->whereColumn(
                        'social_account_connections.delivery_provider',
                        'social_post_targets.delivery_provider',
                    )
                    ->whereColumn(
                        'social_account_connections.transport_generation',
                        'social_post_targets.transport_generation',
                    )
                    ->whereColumn(
                        'social_account_connections.logical_destination_key',
                        'social_post_targets.logical_destination_key',
                    );
            })
            ->orderBy('next_reconcile_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $marked = 0;

        foreach ($targetIds as $targetId) {
            if ($this->markUnavailableConnectionForReview($tenantId, (int) $targetId)) {
                $marked++;
            }
        }

        return $marked;
    }

    private function markDueMissingRemoteIdentifiersForReview(int $tenantId, int $limit): int
    {
        $now = $this->now();
        $targetIds = SocialPostTarget::query()
            ->whereHas('socialPost', function (Builder $query) use ($tenantId): void {
                $query->where('user_id', $tenantId);
            })
            ->where('status', '!=', SocialPostTarget::STATUS_CANCELED)
            ->where('delivery_status', SocialPost::DELIVERY_STATUS_UNKNOWN)
            ->whereNotNull('next_reconcile_at')
            ->where('next_reconcile_at', '<=', $now)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('provider_post_id')
                    ->orWhere('provider_post_id', '');
            })
            ->orderBy('next_reconcile_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $marked = 0;

        foreach ($targetIds as $targetId) {
            if ($this->markMissingRemoteIdentifierForReview($tenantId, (int) $targetId)) {
                $marked++;
            }
        }

        return $marked;
    }

    private function markDueMissingDeliveryOutboxesForReview(int $tenantId, int $limit): int
    {
        $targetIds = SocialPostTarget::query()
            ->whereHas('socialPost', function (Builder $query) use ($tenantId): void {
                $query->where('user_id', $tenantId);
            })
            ->where('status', '!=', SocialPostTarget::STATUS_CANCELED)
            ->whereIn('delivery_status', $this->reconcilableDeliveryStatuses())
            ->whereNotNull('provider_post_id')
            ->where('provider_post_id', '!=', '')
            ->whereNotNull('last_submitted_revision_id')
            ->whereNotNull('next_reconcile_at')
            ->where('next_reconcile_at', '<=', $this->now())
            ->orderBy('next_reconcile_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $marked = 0;

        foreach ($targetIds as $targetId) {
            if ($this->markMissingDeliveryOutboxForReview($tenantId, (int) $targetId)) {
                $marked++;
            }
        }

        return $marked;
    }

    /**
     * @return array<string, null>
     */
    private function releasedClaimAttributes(): array
    {
        return [
            'reconcile_claimed_at' => null,
            'reconcile_claim_expires_at' => null,
            'reconcile_claimed_by' => null,
            'reconcile_claim_token' => null,
        ];
    }

    private function assertClaimInput(
        int $tenantId,
        int $targetId,
        string $claimedBy,
        int $leaseSeconds,
    ): void {
        if ($tenantId <= 0 || $targetId <= 0) {
            throw new InvalidArgumentException(
                'Social delivery reconciliation identifiers must be positive.',
            );
        }

        if (trim($claimedBy) === '' || mb_strlen(trim($claimedBy)) > 191) {
            throw new InvalidArgumentException(
                'The social delivery reconciliation claimant must be non-blank and bounded.',
            );
        }

        if ($leaseSeconds <= 0 || $leaseSeconds > 3600) {
            throw new InvalidArgumentException(
                'The social delivery reconciliation lease must be between 1 and 3600 seconds.',
            );
        }
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    /**
     * @return array<int, string>
     */
    private function reconcilableDeliveryStatuses(): array
    {
        return [
            SocialPost::DELIVERY_STATUS_QUEUED,
            SocialPost::DELIVERY_STATUS_SUBMITTED,
            SocialPost::DELIVERY_STATUS_SCHEDULED,
            SocialPost::DELIVERY_STATUS_REMOTE_APPROVAL_REQUIRED,
            SocialPost::DELIVERY_STATUS_PUBLISHING,
            self::TARGET_DELIVERY_SENDING,
            SocialPost::DELIVERY_STATUS_UNKNOWN,
        ];
    }
}
