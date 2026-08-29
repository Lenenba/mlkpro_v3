<?php

namespace App\Services\Social;

use App\Models\SocialAccountConnection;
use App\Models\SocialDeliveryOutbox;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\SocialTransportCutover;
use App\Models\SocialTransportCutoverMapping;
use App\Services\Social\Contracts\SocialDeliveryStatusGatewayInterface;
use App\Services\Social\Contracts\SocialDistributionGatewayInterface;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;

class SocialTransportReadinessService
{
    public function __construct(
        private readonly Container $container,
        private readonly SocialConnectionDeliveryMutex $deliveryMutex,
    ) {}

    /**
     * @param  array<string, mixed>|null  $legacyInventory
     * @return array<string, mixed>
     */
    public function reportForDecision(int $tenantId, ?array $legacyInventory = null): array
    {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('The Pulse readiness tenant ID must be positive.');
        }

        $tenantLock = $this->deliveryMutex->acquireTenant($tenantId);
        if ($tenantLock === null) {
            throw new LogicException(
                'The Pulse workspace transport is active. Retry the readiness snapshot shortly.',
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
                        'A Pulse delivery is active. Retry the readiness snapshot shortly.',
                    );
                }

                $connectionLocks[] = $connectionLock;
            }

            return DB::transaction(
                fn (): array => $this->report($tenantId, $legacyInventory),
                3,
            );
        } finally {
            foreach (array_reverse($connectionLocks) as $connectionLock) {
                $connectionLock->release();
            }

            $tenantLock->release();
        }
    }

    /**
     * This report can only block retirement until a deployment-wide writer barrier exists.
     * It never authorizes code or secret removal by itself.
     *
     * @param  array<string, mixed>|null  $legacyInventory
     * @return array<string, mixed>
     */
    public function globalDirectRetirementReport(?array $legacyInventory = null): array
    {
        $cutovers = Schema::hasTable('social_transport_cutovers')
            ? SocialTransportCutover::query()->get()
            : collect();
        $incompleteCutovers = $cutovers->filter(
            fn (SocialTransportCutover $cutover): bool => ! $cutover->hasCoherentState()
                || $cutover->state !== SocialTransportCutover::STATE_CUTOVER_COMPLETE
                || ! $cutover->hasCompleteH3Decision()
                || $cutover->direct_retired_at === null,
        )->count();
        $directConnectionsActive = SocialAccountConnection::query()
            ->where('delivery_provider', SocialAccountConnection::DELIVERY_PROVIDER_DIRECT)
            ->where(
                'transport_generation',
                SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
            )
            ->connected()
            ->count();
        $directTargetsActiveOrFuture = DB::table('social_post_targets as targets')
            ->join('social_posts as posts', 'posts.id', '=', 'targets.social_post_id')
            ->where('targets.delivery_provider', SocialAccountConnection::DELIVERY_PROVIDER_DIRECT)
            ->where(
                'targets.transport_generation',
                SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
            )
            ->where(function (Builder $query): void {
                $query
                    ->where('targets.status', '!=', SocialPostTarget::STATUS_PUBLISHED)
                    ->orWhereIn('targets.delivery_status', [
                        SocialPost::DELIVERY_STATUS_QUEUED,
                        SocialPost::DELIVERY_STATUS_SCHEDULED,
                        SocialPost::DELIVERY_STATUS_SUBMITTED,
                        SocialPost::DELIVERY_STATUS_REMOTE_APPROVAL_REQUIRED,
                        SocialPost::DELIVERY_STATUS_UNKNOWN,
                        'sending',
                    ])
                    ->orWhere('posts.scheduled_for', '>', now());
            })
            ->count();
        $directOutboxUnfinished = 0;
        $ambiguousOutbox = 0;

        if (Schema::hasTable('social_delivery_outbox')) {
            $directOutbox = DB::table('social_delivery_outbox')
                ->where('delivery_provider', SocialAccountConnection::DELIVERY_PROVIDER_DIRECT)
                ->where(
                    'transport_generation',
                    SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
                );
            $directOutboxUnfinished = (clone $directOutbox)
                ->where(function (Builder $query): void {
                    $query
                        ->whereIn('status', [
                            SocialDeliveryOutbox::STATUS_PENDING,
                            SocialDeliveryOutbox::STATUS_CLAIMED,
                            SocialDeliveryOutbox::STATUS_SUBMITTING,
                            SocialDeliveryOutbox::STATUS_RETRYABLE,
                            SocialDeliveryOutbox::STATUS_SUSPENDED,
                        ])
                        ->orWhere(function (Builder $query): void {
                            $query
                                ->where('status', SocialDeliveryOutbox::STATUS_UNKNOWN)
                                ->whereNull('reconciliation_resolved_at');
                        });
                })
                ->count();
            $ambiguousOutbox = DB::table('social_delivery_outbox')
                ->where(function (Builder $query): void {
                    $query
                        ->where('status', SocialDeliveryOutbox::STATUS_SUBMITTING)
                        ->orWhere(function (Builder $query): void {
                            $query
                                ->where('status', SocialDeliveryOutbox::STATUS_UNKNOWN)
                                ->whereNull('reconciliation_resolved_at');
                        });
                })
                ->count();
        }

        $queues = $this->queueSummary($legacyInventory);
        $blockers = ['global_direct_writer_barrier_unavailable'];
        $this->blockUnless($blockers, $incompleteCutovers === 0, 'tenant_cutover_incomplete');
        $this->blockUnless($blockers, $directConnectionsActive === 0, 'direct_connection_still_active');
        $this->blockUnless($blockers, $directTargetsActiveOrFuture === 0, 'direct_target_still_active');
        $this->blockUnless($blockers, $directOutboxUnfinished === 0, 'direct_outbox_unfinished');
        $this->blockUnless($blockers, $ambiguousOutbox === 0, 'ambiguous_delivery_exists');
        $this->blockUnless($blockers, $queues['complete'], 'queue_evidence_incomplete');
        $this->blockUnless($blockers, $queues['jobs_requiring_policy'] === 0, 'pulse_queue_not_drained');
        $this->blockUnless($blockers, $queues['failed_jobs_requiring_policy'] === 0, 'failed_pulse_job_unresolved');
        $this->blockUnless($blockers, $queues['deployed_runtime_proven'], 'deployed_runtime_unproven');
        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);

        return [
            'schema_version' => 'pulse_global_direct_retirement_v1',
            'scope' => 'all_tenants_aggregate',
            'sensitive_fields' => 'excluded',
            'cutovers' => [
                'total' => $cutovers->count(),
                'incomplete' => $incompleteCutovers,
            ],
            'direct_connections_active' => $directConnectionsActive,
            'direct_targets_active_or_future' => $directTargetsActiveOrFuture,
            'direct_outbox_unfinished' => $directOutboxUnfinished,
            'ambiguous_outbox' => $ambiguousOutbox,
            'queues' => $queues,
            'ready' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $legacyInventory
     * @return array<string, mixed>
     */
    public function report(int $tenantId, ?array $legacyInventory = null): array
    {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('The Pulse readiness tenant ID must be positive.');
        }

        $cutover = $this->cutover($tenantId);
        $mapping = $this->mappingSummary($tenantId, $cutover);
        $connections = $this->connectionSummary($tenantId);
        $targets = $this->targetSummary($tenantId);
        $outbox = $this->outboxSummary($tenantId);
        $references = $this->referenceSummary($tenantId);
        $queues = $this->queueSummary($legacyInventory);
        $candidateRuntime = [
            'distribution_gateway_bound' => $this->container->bound(
                SocialDistributionGatewayInterface::class,
            ),
            'status_gateway_bound' => $this->container->bound(
                SocialDeliveryStatusGatewayInterface::class,
            ),
            'submission_handler_available' => false,
        ];

        $canaryBlockers = [];
        $this->blockUnless($canaryBlockers, $cutover !== null, 'cutover_registry_missing');
        $this->blockUnless($canaryBlockers, $mapping['total'] > 0, 'owner_mapping_missing');
        $this->blockUnless($canaryBlockers, $mapping['invalid'] === 0, 'owner_mapping_invalid');
        $this->blockUnless(
            $canaryBlockers,
            $mapping['total'] > 0 && $mapping['shadow_validated'] === $mapping['total'],
            'shadow_validation_incomplete',
        );
        $this->blockUnless(
            $canaryBlockers,
            $mapping['total'] > 0
                && $mapping['replacement_active'] === $mapping['total'],
            'candidate_connection_missing',
        );
        $this->blockUnless(
            $canaryBlockers,
            $candidateRuntime['distribution_gateway_bound'],
            'candidate_distribution_gateway_unbound',
        );
        $this->blockUnless(
            $canaryBlockers,
            $candidateRuntime['status_gateway_bound'],
            'candidate_status_gateway_unbound',
        );
        $this->blockUnless(
            $canaryBlockers,
            $candidateRuntime['submission_handler_available'],
            'candidate_submission_handler_unavailable',
        );
        $this->blockUnless(
            $canaryBlockers,
            $cutover !== null
                && $cutover->state === SocialTransportCutover::STATE_CANARY_ARMED
                && $cutover->hasCoherentState()
                && $cutover->hasCompleteH2Contract()
                && $this->mappingManifestMatches($cutover)
                && (int) $cutover->canary_minimum_deliveries
                    >= SocialTransportCutover::CANARY_MINIMUM_DELIVERIES
                && (int) $cutover->canary_minimum_hours
                    >= SocialTransportCutover::CANARY_MINIMUM_HOURS
                && (int) $cutover->canary_maximum_unknown
                    === SocialTransportCutover::CANARY_MAXIMUM_UNKNOWN
                && (int) $cutover->rollback_rto_seconds > 0
                && (int) $cutover->rollback_rto_seconds
                    <= SocialTransportCutover::ROLLBACK_MAXIMUM_RTO_SECONDS,
            'h2_canary_contract_missing',
        );
        $this->blockUnless($canaryBlockers, $targets['dual_transport_groups'] === 0, 'dual_transport_detected');
        $this->blockUnless(
            $canaryBlockers,
            $mapping['unmapped_direct_active'] === 0,
            'mapping_coverage_incomplete',
        );
        $this->blockUnless(
            $canaryBlockers,
            $references['active_direct'] === 0,
            'direct_reference_still_active',
        );
        $this->blockUnless(
            $canaryBlockers,
            $references['invalid'] === 0,
            'invalid_reference_exists',
        );
        $this->blockUnless($canaryBlockers, $outbox['ambiguous'] === 0, 'ambiguous_delivery_exists');
        $this->blockUnless($canaryBlockers, $queues['complete'], 'queue_evidence_incomplete');
        $this->blockUnless($canaryBlockers, $queues['deployed_runtime_proven'], 'deployed_runtime_unproven');

        $drainBlockers = [];
        $this->blockUnless($drainBlockers, $cutover !== null, 'cutover_registry_missing');
        $this->blockUnless(
            $drainBlockers,
            $cutover !== null
                && in_array((string) $cutover->state, [
                    SocialTransportCutover::STATE_DRAINING_LEGACY,
                    SocialTransportCutover::STATE_AWAITING_H3,
                ], true)
                && $cutover->active_transport_generation
                    === SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1
                && $cutover->hasCoherentState(),
            'drain_state_not_active',
        );
        $this->blockUnless(
            $drainBlockers,
            $cutover !== null
                && $cutover->direct_writer_barrier_at instanceof CarbonInterface
                && ! $cutover->direct_writer_barrier_at->isFuture(),
            'direct_writer_barrier_unproven',
        );
        $this->blockUnless(
            $drainBlockers,
            $cutover !== null
                && $cutover->state === SocialTransportCutover::STATE_AWAITING_H3
                && $cutover->hasCompleteLegacyDrainEvidence(),
            'drain_observation_window_unproven',
        );
        $this->blockUnless($drainBlockers, $connections['direct_active'] === 0, 'direct_connection_still_active');
        $this->blockUnless($drainBlockers, $targets['direct_active_or_future'] === 0, 'direct_target_still_active');
        $this->blockUnless($drainBlockers, $outbox['direct_unfinished'] === 0, 'direct_outbox_unfinished');
        $this->blockUnless($drainBlockers, $outbox['direct_unresolved_dead'] === 0, 'direct_outbox_dead_unresolved');
        $this->blockUnless($drainBlockers, $outbox['ambiguous'] === 0, 'ambiguous_delivery_exists');
        $this->blockUnless($drainBlockers, $references['active_direct'] === 0, 'direct_reference_still_active');
        $this->blockUnless($drainBlockers, $references['invalid'] === 0, 'invalid_reference_exists');
        $this->blockUnless($drainBlockers, $queues['complete'], 'queue_evidence_incomplete');
        $this->blockUnless($drainBlockers, $queues['jobs_requiring_policy'] === 0, 'pulse_queue_not_drained');
        $this->blockUnless($drainBlockers, $queues['failed_jobs_requiring_policy'] === 0, 'failed_pulse_job_unresolved');
        $this->blockUnless($drainBlockers, $queues['deployed_runtime_proven'], 'deployed_runtime_unproven');

        $h3Blockers = $drainBlockers;
        $this->blockUnless(
            $h3Blockers,
            $cutover !== null
                && $cutover->state === SocialTransportCutover::STATE_AWAITING_H3
                && $cutover->pilot_status === SocialTransportCutover::PILOT_PASSED
                && $cutover->hasCompleteCanaryEvidence()
                && $cutover->hasCompleteLegacyDrainEvidence(),
            'canary_evidence_incomplete',
        );
        $this->blockUnless(
            $h3Blockers,
            $cutover !== null
                && $mapping['total'] > 0
                && $mapping['invalid'] === 0
                && $this->mappingManifestMatches($cutover),
            'candidate_mapping_invalid',
        );
        $this->blockUnless(
            $h3Blockers,
            $mapping['total'] > 0
                && $mapping['replacement_active'] === $mapping['total'],
            'candidate_connection_missing',
        );
        $this->blockUnless(
            $h3Blockers,
            $candidateRuntime['distribution_gateway_bound'],
            'candidate_distribution_gateway_unbound',
        );
        $this->blockUnless(
            $h3Blockers,
            $candidateRuntime['status_gateway_bound'],
            'candidate_status_gateway_unbound',
        );
        $this->blockUnless(
            $h3Blockers,
            $candidateRuntime['submission_handler_available'],
            'candidate_submission_handler_unavailable',
        );

        sort($canaryBlockers, SORT_STRING);
        sort($drainBlockers, SORT_STRING);
        sort($h3Blockers, SORT_STRING);

        return [
            'schema_version' => 'pulse_transport_readiness_v1',
            'scope' => 'single_tenant_aggregate',
            'sensitive_fields' => 'excluded',
            'state' => $cutover?->state ?? SocialTransportCutover::STATE_LEGACY_ONLY,
            'active_transport_generation' => $cutover?->active_transport_generation
                ?? SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
            'mapping' => $mapping,
            'connections' => $connections,
            'targets' => $targets,
            'outbox' => $outbox,
            'references' => $references,
            'queues' => $queues,
            'candidate_runtime' => $candidateRuntime,
            'canary' => [
                'ready' => $canaryBlockers === [],
                'blockers' => $canaryBlockers,
            ],
            'legacy_drain' => [
                'ready' => $drainBlockers === [],
                'blockers' => $drainBlockers,
            ],
            'h3' => [
                'ready' => $h3Blockers === [],
                'blockers' => $h3Blockers,
            ],
        ];
    }

    private function cutover(int $tenantId): ?SocialTransportCutover
    {
        if (! Schema::hasTable('social_transport_cutovers')) {
            return null;
        }

        return SocialTransportCutover::query()
            ->where('user_id', $tenantId)
            ->first();
    }

    /** @return array{total:int,owner_validated:int,shadow_validated:int,replacement_active:int,unmapped_direct_active:int,invalid:int} */
    private function mappingSummary(int $tenantId, ?SocialTransportCutover $cutover): array
    {
        if ($cutover === null || ! Schema::hasTable('social_transport_cutover_mappings')) {
            return [
                'total' => 0,
                'owner_validated' => 0,
                'shadow_validated' => 0,
                'replacement_active' => 0,
                'unmapped_direct_active' => SocialAccountConnection::query()
                    ->where('user_id', $tenantId)
                    ->where('delivery_provider', SocialAccountConnection::DELIVERY_PROVIDER_DIRECT)
                    ->where(
                        'transport_generation',
                        SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
                    )
                    ->connected()
                    ->count(),
                'invalid' => 0,
            ];
        }

        $mappings = SocialTransportCutoverMapping::query()
            ->with(['legacyConnection', 'replacementConnection'])
            ->where('social_transport_cutover_id', $cutover->getKey())
            ->where('user_id', $tenantId)
            ->get();
        $invalid = $mappings->filter(function (SocialTransportCutoverMapping $mapping) use (
            $cutover,
            $tenantId,
        ): bool {
            $legacy = $mapping->legacyConnection;
            $replacement = $mapping->replacementConnection;

            return ! $legacy
                || ! $replacement
                || (int) $legacy->user_id !== $tenantId
                || (int) $replacement->user_id !== $tenantId
                || (int) $mapping->owner_validated_by_user_id !== $tenantId
                || $mapping->owner_validated_at === null
                || ! $this->validHash($mapping->owner_evidence_hash)
                || (($mapping->shadow_validated_at === null)
                    !== ($mapping->shadow_evidence_hash === null))
                || ($mapping->shadow_evidence_hash !== null
                    && ! $this->validHash($mapping->shadow_evidence_hash))
                || ($cutover->h2_approved_at !== null
                    && ! $this->mappingPredatesH2($cutover, $mapping))
                || (string) $legacy->delivery_provider !== SocialAccountConnection::DELIVERY_PROVIDER_DIRECT
                || (string) $legacy->transport_generation
                    !== SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1
                || (string) $replacement->delivery_provider
                    !== SocialAccountConnection::DELIVERY_PROVIDER_BUFFER
                || (string) $replacement->transport_generation
                    !== SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1
                || (string) $legacy->platform !== SocialAccountConnection::PLATFORM_FACEBOOK
                || (string) $replacement->platform !== SocialAccountConnection::PLATFORM_FACEBOOK
                || ! hash_equals(
                    (string) $mapping->logical_destination_key,
                    (string) $legacy->logical_destination_key,
                )
                || ! hash_equals(
                    (string) $mapping->logical_destination_key,
                    (string) $replacement->logical_destination_key,
                );
        })->count();
        $mappedLegacyConnectionIds = $mappings
            ->pluck('legacy_connection_id')
            ->map(fn (int|string $connectionId): int => (int) $connectionId)
            ->all();
        $unmappedDirectActive = SocialAccountConnection::query()
            ->where('user_id', $tenantId)
            ->where('delivery_provider', SocialAccountConnection::DELIVERY_PROVIDER_DIRECT)
            ->where(
                'transport_generation',
                SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
            )
            ->connected()
            ->when(
                $mappedLegacyConnectionIds !== [],
                fn (EloquentBuilder $query): EloquentBuilder => $query->whereNotIn(
                    'id',
                    $mappedLegacyConnectionIds,
                ),
            )
            ->count();

        return [
            'total' => $mappings->count(),
            'owner_validated' => $mappings->whereNotNull('owner_validated_at')->count(),
            'shadow_validated' => $mappings->whereNotNull('shadow_validated_at')->count(),
            'replacement_active' => $mappings->filter(
                fn (SocialTransportCutoverMapping $mapping): bool => $mapping->replacementConnection !== null
                    && $mapping->replacementConnection->status
                        === SocialAccountConnection::STATUS_CONNECTED
                    && (bool) $mapping->replacementConnection->is_active,
            )->count(),
            'unmapped_direct_active' => $unmappedDirectActive,
            'invalid' => $invalid,
        ];
    }

    /** @return array{direct_total:int,direct_active:int,candidate_total:int,candidate_active:int} */
    private function connectionSummary(int $tenantId): array
    {
        $query = SocialAccountConnection::query()->where('user_id', $tenantId);
        $direct = (clone $query)
            ->where('delivery_provider', SocialAccountConnection::DELIVERY_PROVIDER_DIRECT)
            ->where('transport_generation', SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1);
        $candidate = (clone $query)
            ->where('delivery_provider', SocialAccountConnection::DELIVERY_PROVIDER_BUFFER)
            ->where('transport_generation', SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1);

        return [
            'direct_total' => (clone $direct)->count(),
            'direct_active' => (clone $direct)->connected()->count(),
            'candidate_total' => (clone $candidate)->count(),
            'candidate_active' => (clone $candidate)->connected()->count(),
        ];
    }

    /** @return array{direct_total:int,direct_active_or_future:int,candidate_total:int,dual_transport_groups:int} */
    private function targetSummary(int $tenantId): array
    {
        $base = DB::table('social_post_targets as targets')
            ->join('social_posts as posts', 'posts.id', '=', 'targets.social_post_id')
            ->where('posts.user_id', $tenantId);
        $direct = (clone $base)
            ->where('targets.delivery_provider', SocialAccountConnection::DELIVERY_PROVIDER_DIRECT)
            ->where('targets.transport_generation', SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1);
        $candidate = (clone $base)
            ->where('targets.delivery_provider', SocialAccountConnection::DELIVERY_PROVIDER_BUFFER)
            ->where('targets.transport_generation', SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1);
        $activeOrFuture = (clone $direct)
            ->where(function (Builder $query): void {
                $query
                    ->where('targets.status', '!=', SocialPostTarget::STATUS_PUBLISHED)
                    ->orWhereIn('targets.delivery_status', [
                        SocialPost::DELIVERY_STATUS_QUEUED,
                        SocialPost::DELIVERY_STATUS_SCHEDULED,
                        SocialPost::DELIVERY_STATUS_SUBMITTED,
                        SocialPost::DELIVERY_STATUS_REMOTE_APPROVAL_REQUIRED,
                        SocialPost::DELIVERY_STATUS_UNKNOWN,
                        'sending',
                    ])
                    ->orWhere('posts.scheduled_for', '>', now());
            })
            ->count();
        $dualTransportGroupsQuery = (clone $base)
            ->whereNotNull('targets.logical_destination_key')
            ->select(['targets.social_post_id', 'targets.logical_destination_key'])
            ->groupBy('targets.social_post_id', 'targets.logical_destination_key')
            ->havingRaw('COUNT(DISTINCT targets.transport_generation) > 1');
        $dualTransportGroups = DB::query()
            ->fromSub($dualTransportGroupsQuery, 'dual_transport_groups')
            ->count();

        return [
            'direct_total' => (clone $direct)->count(),
            'direct_active_or_future' => $activeOrFuture,
            'candidate_total' => (clone $candidate)->count(),
            'dual_transport_groups' => $dualTransportGroups,
        ];
    }

    /** @return array{direct_total:int,direct_unfinished:int,direct_unresolved_dead:int,ambiguous:int,suspended:int} */
    private function outboxSummary(int $tenantId): array
    {
        if (! Schema::hasTable('social_delivery_outbox')) {
            return [
                'direct_total' => 0,
                'direct_unfinished' => 0,
                'direct_unresolved_dead' => 0,
                'ambiguous' => 0,
                'suspended' => 0,
            ];
        }

        $query = DB::table('social_delivery_outbox as outbox')
            ->where('outbox.user_id', $tenantId);
        $direct = (clone $query)
            ->where('outbox.delivery_provider', SocialAccountConnection::DELIVERY_PROVIDER_DIRECT)
            ->where(
                'outbox.transport_generation',
                SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
            );
        $unresolvedDead = (clone $direct)
            ->where(function (Builder $query): void {
                $query
                    ->where(function (Builder $query): void {
                        $query
                            ->where('outbox.status', SocialDeliveryOutbox::STATUS_DEAD)
                            ->where(function (Builder $query): void {
                                $query
                                    ->whereNull('outbox.reconciliation_resolved_at')
                                    ->orWhere(
                                        'outbox.reconciliation_resolution',
                                        SocialDeliveryOutbox::RECONCILIATION_RESOLUTION_ERROR,
                                    );
                            });
                    })
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->where('outbox.status', SocialDeliveryOutbox::STATUS_UNKNOWN)
                            ->where(
                                'outbox.reconciliation_resolution',
                                SocialDeliveryOutbox::RECONCILIATION_RESOLUTION_ERROR,
                            )
                            ->where(
                                'outbox.reconciliation_resolution_source',
                                SocialDeliveryOutbox::RECONCILIATION_SOURCE_STATUS_READ,
                            );
                    });
            })
            ->whereRaw(
                'NOT EXISTS (SELECT 1 FROM social_delivery_outbox AS recovery_outbox WHERE recovery_outbox.supersedes_outbox_id = outbox.id AND recovery_outbox.user_id = outbox.user_id)',
            )
            ->count();
        $directUnfinished = (clone $direct)
            ->where(function (Builder $query): void {
                $query
                    ->whereIn('outbox.status', [
                        SocialDeliveryOutbox::STATUS_PENDING,
                        SocialDeliveryOutbox::STATUS_CLAIMED,
                        SocialDeliveryOutbox::STATUS_SUBMITTING,
                        SocialDeliveryOutbox::STATUS_RETRYABLE,
                        SocialDeliveryOutbox::STATUS_SUSPENDED,
                    ])
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->where('outbox.status', SocialDeliveryOutbox::STATUS_UNKNOWN)
                            ->whereNull('outbox.reconciliation_resolved_at');
                    });
            })
            ->count();
        $ambiguous = (clone $query)
            ->where(function (Builder $query): void {
                $query
                    ->where('outbox.status', SocialDeliveryOutbox::STATUS_SUBMITTING)
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->where('outbox.status', SocialDeliveryOutbox::STATUS_UNKNOWN)
                            ->whereNull('outbox.reconciliation_resolved_at');
                    });
            })
            ->count();

        return [
            'direct_total' => (clone $direct)->count(),
            'direct_unfinished' => $directUnfinished,
            'direct_unresolved_dead' => $unresolvedDead,
            'ambiguous' => $ambiguous,
            'suspended' => (clone $query)
                ->where('outbox.status', SocialDeliveryOutbox::STATUS_SUSPENDED)
                ->count(),
        ];
    }

    /** @return array{automation_rules:int,templates:int,active_direct:int,invalid:int} */
    private function referenceSummary(int $tenantId): array
    {
        $connectionIdentities = SocialAccountConnection::query()
            ->where('user_id', $tenantId)
            ->get([
                'id',
                'delivery_provider',
                'transport_generation',
                'logical_destination_key',
            ])
            ->mapWithKeys(fn (SocialAccountConnection $connection): array => [
                (int) $connection->id => [
                    'delivery_provider' => (string) $connection->delivery_provider,
                    'transport_generation' => (string) $connection->transport_generation,
                    'logical_destination_key' => (string) $connection->logical_destination_key,
                ],
            ])
            ->all();
        $mappedCandidateDestinations = Schema::hasTable('social_transport_cutover_mappings')
            ? DB::table('social_transport_cutover_mappings')
                ->where('user_id', $tenantId)
                ->pluck('logical_destination_key', 'replacement_connection_id')
                ->mapWithKeys(fn (string $key, int|string $connectionId): array => [
                    (int) $connectionId => $key,
                ])
                ->all()
            : [];
        $automationRules = 0;
        $templates = 0;
        $invalid = 0;

        DB::table('social_automation_rules')
            ->where('user_id', $tenantId)
            ->where('is_active', true)
            ->select(['id', 'target_connection_ids'])
            ->orderBy('id')
            ->chunkById(
                200,
                function ($rows) use (
                    &$automationRules,
                    &$invalid,
                    $connectionIdentities,
                    $mappedCandidateDestinations,
                ): void {
                    foreach ($rows as $row) {
                        $valid = true;
                        $automationRules += $this->matchingReferenceCount(
                            $row->target_connection_ids ?? null,
                            $connectionIdentities,
                            $mappedCandidateDestinations,
                            $valid,
                        );

                        if (! $valid) {
                            $invalid++;
                        }
                    }
                },
            );
        DB::table('social_post_templates')
            ->where('user_id', $tenantId)
            ->select(['id', 'metadata'])
            ->orderBy('id')
            ->chunkById(
                200,
                function ($rows) use (
                    &$templates,
                    &$invalid,
                    $connectionIdentities,
                    $mappedCandidateDestinations,
                ): void {
                    foreach ($rows as $row) {
                        $metadataValid = true;
                        $metadata = $this->decodedObject($row->metadata ?? null, $metadataValid);
                        $referencesValid = true;
                        $templates += $this->matchingReferenceCount(
                            $metadata['selected_target_connection_ids'] ?? null,
                            $connectionIdentities,
                            $mappedCandidateDestinations,
                            $referencesValid,
                            true,
                        );

                        if (! $metadataValid || ! $referencesValid) {
                            $invalid++;
                        }
                    }
                },
            );

        return [
            'automation_rules' => $automationRules,
            'templates' => $templates,
            'active_direct' => $automationRules + $templates,
            'invalid' => $invalid,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $inventory
     * @return array{provided:bool,complete:bool,deployed_runtime_proven:bool,jobs_requiring_policy:int,failed_jobs_requiring_policy:int}
     */
    private function queueSummary(?array $inventory): array
    {
        if ($inventory === null) {
            return [
                'provided' => false,
                'complete' => false,
                'deployed_runtime_proven' => false,
                'jobs_requiring_policy' => 0,
                'failed_jobs_requiring_policy' => 0,
            ];
        }

        $manifest = is_array($inventory['queue_scope_manifest'] ?? null)
            ? $inventory['queue_scope_manifest']
            : [];
        $failed = is_array($inventory['failed_pulse_jobs'] ?? null)
            ? $inventory['failed_pulse_jobs']
            : [];
        $topology = is_array($inventory['configured_pulse_topology'] ?? null)
            ? $inventory['configured_pulse_topology']
            : [];
        $jobsRequiringPolicy = 0;

        foreach (($manifest['scopes'] ?? []) as $scope) {
            if (! is_array($scope)) {
                $jobsRequiringPolicy++;

                continue;
            }

            foreach (($scope['jobs_by_workload'] ?? []) as $workload) {
                if (! is_array($workload)) {
                    $jobsRequiringPolicy++;

                    continue;
                }

                $jobsRequiringPolicy += (int) ($workload['total'] ?? 0)
                    + (int) ($workload['unparseable_candidates'] ?? 0);
            }
        }

        $failedJobsRequiringPolicy = (int) ($failed['total'] ?? 0)
            + (int) ($failed['unparseable_candidates'] ?? 0);
        $complete = ($manifest['operator_attested_complete_scope_list'] ?? false) === true
            && (int) ($manifest['scope_count'] ?? -1) > 0
            && (int) ($manifest['scope_count'] ?? -1)
                === (int) ($manifest['measurable_scope_count'] ?? -2)
            && ($manifest['requires_job_policy'] ?? null) === false
            && ($failed['measurable'] ?? false) === true
            && ($failed['requires_job_policy'] ?? null) === false;

        return [
            'provided' => true,
            'complete' => $complete,
            'deployed_runtime_proven' => ($topology['deployed_runtime_proven'] ?? false) === true,
            'jobs_requiring_policy' => $jobsRequiringPolicy,
            'failed_jobs_requiring_policy' => $failedJobsRequiringPolicy,
        ];
    }

    /**
     * @param  array<int, array{delivery_provider:string,transport_generation:string,logical_destination_key:string}>  $connectionIdentities
     * @param  array<int, string>  $mappedCandidateDestinations
     */
    private function matchingReferenceCount(
        mixed $value,
        array $connectionIdentities,
        array $mappedCandidateDestinations,
        bool &$valid,
        bool $allowNull = false,
    ): int {
        $references = $this->decodedReferenceList($value, $valid, $allowNull);

        if (! $valid) {
            return 0;
        }

        $directReferences = 0;

        foreach ($references as $connectionId) {
            $identity = $connectionIdentities[$connectionId] ?? null;

            if ($identity === null) {
                $valid = false;

                return 0;
            }

            if ($identity['delivery_provider'] === SocialAccountConnection::DELIVERY_PROVIDER_DIRECT
                && $identity['transport_generation']
                    === SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1) {
                $directReferences++;

                continue;
            }

            $mappedDestination = $mappedCandidateDestinations[$connectionId] ?? null;
            if ($identity['delivery_provider'] === SocialAccountConnection::DELIVERY_PROVIDER_BUFFER
                && $identity['transport_generation']
                    === SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1
                && is_string($mappedDestination)
                && hash_equals($identity['logical_destination_key'], $mappedDestination)) {
                continue;
            }

            $valid = false;

            return 0;
        }

        return $directReferences;
    }

    /** @return array<int|string, mixed> */
    private function decodedObject(mixed $value, bool &$valid): array
    {
        $decoded = $this->decodedJsonArray($value, $valid);

        if (! $valid || ($decoded !== [] && array_is_list($decoded))) {
            $valid = false;

            return [];
        }

        return $decoded;
    }

    /** @return list<int> */
    private function decodedReferenceList(mixed $value, bool &$valid, bool $allowNull): array
    {
        if ($value === null) {
            $valid = $allowNull;

            return [];
        }

        $decoded = $this->decodedJsonArray($value, $valid);

        if (! $valid || ! array_is_list($decoded)) {
            $valid = false;

            return [];
        }

        $references = [];

        foreach ($decoded as $reference) {
            $validated = filter_var($reference, FILTER_VALIDATE_INT);

            if ($validated === false || (int) $validated <= 0) {
                $valid = false;

                return [];
            }

            $references[] = (int) $validated;
        }

        if (count($references) !== count(array_unique($references))) {
            $valid = false;

            return [];
        }

        return $references;
    }

    /** @return array<int|string, mixed> */
    private function decodedJsonArray(mixed $value, bool &$valid): array
    {
        if (is_array($value)) {
            $valid = true;

            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            $valid = false;

            return [];
        }

        $decoded = json_decode($value, true);

        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            $valid = false;

            return [];
        }

        $valid = true;

        return $decoded;
    }

    /** @param list<string> $blockers */
    private function blockUnless(array &$blockers, bool $condition, string $blocker): void
    {
        if (! $condition) {
            $blockers[] = $blocker;
        }
    }

    private function validHash(mixed $hash): bool
    {
        return is_string($hash) && preg_match('/\A[0-9a-f]{64}\z/', $hash) === 1;
    }

    private function mappingPredatesH2(
        SocialTransportCutover $cutover,
        SocialTransportCutoverMapping $mapping,
    ): bool {
        $h2ApprovedAt = $cutover->h2_approved_at;
        $ownerValidatedAt = $mapping->owner_validated_at;
        $shadowValidatedAt = $mapping->shadow_validated_at;

        return $h2ApprovedAt instanceof CarbonInterface
            && $ownerValidatedAt instanceof CarbonInterface
            && $shadowValidatedAt instanceof CarbonInterface
            && $ownerValidatedAt->lessThanOrEqualTo($h2ApprovedAt)
            && $shadowValidatedAt->lessThanOrEqualTo($h2ApprovedAt);
    }

    private function mappingManifestMatches(SocialTransportCutover $cutover): bool
    {
        $recordedHash = (string) $cutover->mapping_manifest_hash;

        return preg_match('/\A[0-9a-f]{64}\z/', $recordedHash) === 1
            && hash_equals($recordedHash, SocialTransportMappingManifest::hashFor($cutover));
    }
}
