<?php

namespace App\Services\Social;

use App\Data\Social\CreateSocialDeliveryData;
use App\Data\Social\SocialDeliveryResultData;
use App\Exceptions\Social\AmbiguousSocialPublishingException;
use App\Exceptions\Social\DefinitiveSocialPublishingRejectionException;
use App\Exceptions\Social\RetryableSocialPublishingException;
use App\Exceptions\Social\UnpublishableSocialMediaUrlException;
use App\Jobs\ProcessSocialDeliveryOutboxJob;
use App\Models\SocialAccountConnection;
use App\Models\SocialAutomationRule;
use App\Models\SocialDeliveryOutbox;
use App\Models\SocialPost;
use App\Models\SocialPostRevision;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Services\Social\Contracts\SocialDistributionGatewayInterface;
use App\Support\QueueWorkload;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Throwable;

class SocialPublishingService
{
    private const TARGET_DELIVERY_SENDING = 'sending';

    public function __construct(
        private readonly SocialProviderRegistry $registry,
        private readonly SocialPostRevisionService $revisionService,
        private readonly SocialPostRevisionSnapshotService $revisionSnapshots,
        private readonly SocialDeliveryOutboxService $deliveryOutboxes,
        private readonly SocialConnectionDeliveryMutex $connectionDeliveryMutex,
        private readonly SocialOperationalMessageSanitizer $messageSanitizer,
        private readonly SocialTransportPolicyService $transportPolicy,
        private readonly SocialDistributionGatewayInterface $distributionGateway,
        private readonly SocialPostRetryPolicy $retryPolicy,
        private readonly SocialMediaAssetService $mediaAssetService,
        private readonly SocialPublicationNotificationService $notifications,
    ) {}

    public function publishNow(User $owner, User $actor, SocialPost $post): SocialPost
    {
        return $this->queuePublication($owner, $actor, $post, 'immediate');
    }

    public function retryFailed(User $owner, User $actor, SocialPost $post): SocialPost
    {
        return $this->queuePublication(
            $owner,
            $actor,
            $post,
            'immediate',
            retryFailedOnly: true,
        );
    }

    public function publishNowFromAutopilot(
        User $owner,
        User $actor,
        SocialPost $post,
        SocialAutomationRule $rule,
        array $expectedPolicy,
        string $claimToken,
    ): SocialPost {
        return $this->queuePublication(
            $owner,
            $actor,
            $post,
            'immediate',
            $rule,
            $expectedPolicy,
            $claimToken,
        );
    }

    public function schedule(User $owner, User $actor, SocialPost $post): SocialPost
    {
        return $this->queuePublication($owner, $actor, $post, 'scheduled');
    }

    /**
     * @return array{pending_recovered:int,unknown_quarantined:int,aggregates_repaired:int,dispatched:int}
     */
    public function maintainDeliveryOutbox(int $batchSize = 100): array
    {
        $recovered = $this->deliveryOutboxes->recoverExpiredLeases(
            $batchSize,
            function (SocialDeliveryOutbox $outbox, string $status): void {
                if ($status !== SocialDeliveryOutbox::STATUS_UNKNOWN) {
                    return;
                }

                $this->markTargetUnknownForOutbox(
                    $outbox,
                    'The delivery lease expired after a remote effect became possible.',
                );
            },
        );

        return [
            'pending_recovered' => $recovered['pending'],
            'unknown_quarantined' => $recovered['unknown'],
            'aggregates_repaired' => $this->repairTerminalPostAggregates($batchSize),
            'dispatched' => $this->deliveryOutboxes->dispatchDue($batchSize),
        ];
    }

    public function handleOutboxPublication(int $outboxId): void
    {
        $claim = $this->deliveryOutboxes->claim(
            $outboxId,
            'pulse-social-publish:'.Str::lower((string) Str::ulid()),
            min(3600, max(120, QueueWorkload::timeout('social_publish') + 60)),
        );

        if ($claim === null) {
            $this->repairTerminalOutboxAggregate($outboxId);

            return;
        }

        try {
            $outbox = $claim['outbox'];
            $claimToken = $claim['claim_token'];
            $claimVersion = $claim['claim_version'];
            $target = SocialPostTarget::query()
                ->with([
                    'socialPost.targets.socialAccountConnection',
                    'socialAccountConnection',
                    'currentRevision',
                    'lastSubmittedRevision',
                ])
                ->find($outbox->social_post_target_id);

            if (! $target || ! $target->socialPost) {
                $this->deliveryOutboxes->markDead(
                    $outboxId,
                    $claimToken,
                    $claimVersion,
                    'integrity',
                    'missing_local_target',
                    'The local Pulse delivery target no longer exists.',
                );

                return;
            }

            $post = $target->socialPost;

            if ((int) $outbox->user_id !== (int) $post->user_id
                || (int) $outbox->social_post_target_id !== (int) $target->id) {
                $this->deliveryOutboxes->markDead(
                    $outboxId,
                    $claimToken,
                    $claimVersion,
                    'integrity',
                    'tenant_boundary_mismatch',
                    'The Pulse outbox entry failed its workspace boundary check.',
                );

                return;
            }

            if ((int) $target->last_submitted_revision_id !== (int) $outbox->social_post_revision_id) {
                $this->deliveryOutboxes->markDead(
                    $outboxId,
                    $claimToken,
                    $claimVersion,
                    'superseded',
                    'submitted_revision_replaced',
                    'A newer human submission replaced this Pulse delivery operation.',
                );

                return;
            }

            if ((string) $post->status === SocialPost::STATUS_PENDING_APPROVAL) {
                $this->deliveryOutboxes->markDead(
                    $outboxId,
                    $claimToken,
                    $claimVersion,
                    'superseded',
                    'local_decision_is_terminal',
                    'A local human decision closed this Pulse delivery before dispatch.',
                );

                return;
            }

            if ($this->targetIsTerminal($target)) {
                $this->deliveryOutboxes->markDead(
                    $outboxId,
                    $claimToken,
                    $claimVersion,
                    'superseded',
                    'local_decision_is_terminal',
                    'A local human decision closed this Pulse delivery before dispatch.',
                );

                return;
            }

            $revision = $target->lastSubmittedRevision;

            if (! $revision
                || (int) $revision->id !== (int) $outbox->social_post_revision_id
                || ! $this->submittedRevisionIsValid($post, $target, $revision)) {
                $message = 'The immutable Pulse revision for this delivery is missing, stale, or unapproved.';
                $this->deliveryOutboxes->markDead(
                    $outboxId,
                    $claimToken,
                    $claimVersion,
                    'integrity',
                    'invalid_submitted_revision',
                    $message,
                    fn (): mixed => $this->quarantineTargetIntegrity($post, $target, $message),
                );

                return;
            }

            $connection = $target->socialAccountConnection;
            if (! $connection) {
                $message = 'This social account is no longer connected or active for publishing.';
                $this->deliveryOutboxes->markDead(
                    $outboxId,
                    $claimToken,
                    $claimVersion,
                    'configuration',
                    'connection_unavailable',
                    $message,
                    fn (SocialDeliveryOutbox $entry): mixed => $this->markTargetFailedForOutbox($entry, $message),
                );

                return;
            }

            if (! $this->outboxRuntimeIdentityIsValid($outbox, $post, $target, $revision, $connection)) {
                $message = 'This Pulse outbox entry no longer matches its tenant, revision, destination, or transport snapshot.';
                $this->deliveryOutboxes->markDead(
                    $outboxId,
                    $claimToken,
                    $claimVersion,
                    'integrity',
                    'runtime_identity_mismatch',
                    $message,
                    fn (SocialDeliveryOutbox $entry): mixed => $this->markTargetFailedForOutbox($entry, $message),
                );

                return;
            }

            $connectionLock = $this->connectionDeliveryMutex->acquire((int) $connection->id);

            if ($connectionLock === null) {
                $message = 'This Pulse social connection is busy with another delivery or connection change.';
                $this->deliveryOutboxes->markRetryable(
                    $outboxId,
                    $claimToken,
                    $claimVersion,
                    now()->addSeconds(5),
                    'retryable',
                    'connection_delivery_lock_busy',
                    $message,
                    afterTransition: function (SocialDeliveryOutbox $entry) use ($message): void {
                        if ($entry->status === SocialDeliveryOutbox::STATUS_DEAD) {
                            $this->markTargetFailedForOutbox($entry, $message);
                        }
                    },
                );

                return;
            }

            try {
                $connection = SocialAccountConnection::query()
                    ->find($outbox->social_provider_connection_id);

                if (! $connection
                    || ! $this->outboxRuntimeIdentityIsValid($outbox, $post, $target, $revision, $connection)) {
                    $message = 'This social account changed or disconnected before Pulse could submit the delivery.';
                    $this->deliveryOutboxes->markDead(
                        $outboxId,
                        $claimToken,
                        $claimVersion,
                        'configuration',
                        'connection_changed_before_submission',
                        $message,
                        fn (SocialDeliveryOutbox $entry): mixed => $this->markTargetFailedForOutbox($entry, $message),
                    );

                    return;
                }

                if (! $this->transportPolicy->allowsExistingRemoteEffect(
                    (int) $outbox->user_id,
                    (string) $outbox->transport_generation,
                    (int) $connection->id,
                    (string) $outbox->logical_destination_key,
                )) {
                    $this->deliveryOutboxes->suspendBeforeRequest(
                        $outboxId,
                        $claimToken,
                        $claimVersion,
                    );

                    return;
                }

                if (! $this->targetUsesSupportedTransport($target, $connection)) {
                    $message = 'This Pulse target is not assigned to a supported delivery worker.';
                    $this->deliveryOutboxes->markDead(
                        $outboxId,
                        $claimToken,
                        $claimVersion,
                        'configuration',
                        'transport_not_supported',
                        $message,
                        fn (SocialDeliveryOutbox $entry): mixed => $this->markTargetFailedForOutbox($entry, $message),
                    );

                    return;
                }

                if (! $this->connectionCanPublish($connection)) {
                    $message = 'This social account is no longer connected or active for publishing.';
                    $this->deliveryOutboxes->markDead(
                        $outboxId,
                        $claimToken,
                        $claimVersion,
                        'configuration',
                        'connection_unavailable',
                        $message,
                        fn (SocialDeliveryOutbox $entry): mixed => $this->markTargetFailedForOutbox($entry, $message),
                    );

                    return;
                }

                try {
                    $payload = $this->deliveryOutboxes->verifiedPayload($outbox);
                } catch (Throwable $exception) {
                    $message = $this->exceptionMessage(
                        $exception,
                        'The local Pulse delivery payload failed its pre-request validation.',
                    );
                    $this->deliveryOutboxes->markDead(
                        $outboxId,
                        $claimToken,
                        $claimVersion,
                        'integrity',
                        'pre_request_validation_failed',
                        $message,
                        fn (SocialDeliveryOutbox $entry): mixed => $this->markTargetFailedForOutbox($entry, $message),
                    );
                    $this->recordConnectionError($connection, $message);

                    return;
                }

                try {
                    $usesBufferTransport = $this->targetUsesBufferTransport($target, $connection);
                    $publisher = $usesBufferTransport
                        ? null
                        : $this->registry->publisher((string) $connection->platform);
                    $bufferDelivery = $usesBufferTransport
                        ? $this->bufferDeliveryData($outbox, $payload)
                        : null;
                } catch (UnpublishableSocialMediaUrlException $exception) {
                    $message = $this->exceptionMessage(
                        $exception,
                        'Buffer cannot access this Pulse media URL.',
                    );
                    $this->deliveryOutboxes->markDead(
                        $outboxId,
                        $claimToken,
                        $claimVersion,
                        'validation',
                        'media_url_not_public',
                        $message,
                        fn (SocialDeliveryOutbox $entry): mixed => $this->markTargetFailedForOutbox($entry, $message),
                    );
                    $this->recordConnectionError($connection, $message);

                    return;
                } catch (Throwable $exception) {
                    $message = $this->exceptionMessage(
                        $exception,
                        'The Pulse delivery could not be prepared for its provider.',
                    );
                    $this->deliveryOutboxes->markDead(
                        $outboxId,
                        $claimToken,
                        $claimVersion,
                        'validation',
                        'provider_request_invalid',
                        $message,
                        fn (SocialDeliveryOutbox $entry): mixed => $this->markTargetFailedForOutbox($entry, $message),
                    );

                    return;
                }

                try {
                    $started = $this->deliveryOutboxes->startSubmitting(
                        $outboxId,
                        $claimToken,
                        $claimVersion,
                        fn (SocialDeliveryOutbox $entry): mixed => $this->markTargetPublishingForOutbox($entry),
                    );
                } catch (LogicException $exception) {
                    $this->deliveryOutboxes->markDead(
                        $outboxId,
                        $claimToken,
                        $claimVersion,
                        'superseded',
                        'local_decision_changed',
                        $this->exceptionMessage($exception),
                    );

                    return;
                }

                if (! $started) {
                    return;
                }

                $this->refreshPostStatus($post->fresh(['targets.socialAccountConnection']));

                try {
                    if ($bufferDelivery instanceof CreateSocialDeliveryData) {
                        $bufferResult = $this->distributionGateway->createPost($bufferDelivery);

                        if ($bufferResult->status !== SocialDeliveryResultData::STATUS_SUBMITTED
                            || trim((string) $bufferResult->providerPostId) === '') {
                            throw new AmbiguousSocialPublishingException(
                                'Buffer may have accepted the Pulse request without a verifiable result.',
                            );
                        }

                        $submittedAt = now();
                        $this->deliveryOutboxes->markCompleted(
                            $outboxId,
                            $claimToken,
                            $claimVersion,
                            (string) $bufferResult->providerPostId,
                            $submittedAt,
                            fn (SocialDeliveryOutbox $entry): mixed => $this->markTargetBufferSubmittedForOutbox(
                                $entry,
                                $bufferResult,
                                $submittedAt,
                            ),
                        );

                        return;
                    }

                    $result = $publisher?->publish($connection, $payload) ?? [];
                    $providerPostId = trim((string) data_get($result, 'provider_post_id'));

                    if ($providerPostId === '') {
                        throw new AmbiguousSocialPublishingException(
                            'The provider accepted the Pulse request without a verifiable post identifier.',
                        );
                    }

                    $publishedAt = $this->resolveDate(data_get($result, 'published_at')) ?? now();
                    $this->deliveryOutboxes->markCompleted(
                        $outboxId,
                        $claimToken,
                        $claimVersion,
                        $providerPostId,
                        $publishedAt,
                        fn (SocialDeliveryOutbox $entry): mixed => $this->markTargetPublishedForOutbox(
                            $entry,
                            $connection,
                            $result,
                            $publishedAt,
                        ),
                    );
                } catch (RetryableSocialPublishingException $exception) {
                    $message = $this->exceptionMessage($exception);

                    if (! $exception->remoteEffectIsImpossible()) {
                        $this->deliveryOutboxes->markUnknown(
                            $outboxId,
                            $claimToken,
                            $claimVersion,
                            'ambiguous',
                            'create_retry_safety_not_proven',
                            $message,
                            fn (SocialDeliveryOutbox $entry): mixed => $this->markTargetUnknownForOutbox($entry, $message),
                        );
                    } else {
                        $availableAt = now()->addSeconds(
                            $this->outboxRetryDelaySeconds((int) $outbox->attempts),
                        );
                        $this->deliveryOutboxes->markRetryable(
                            $outboxId,
                            $claimToken,
                            $claimVersion,
                            $availableAt,
                            'retryable',
                            'provider_rejected_without_effect',
                            $message,
                            afterTransition: function (SocialDeliveryOutbox $entry) use ($message): void {
                                if ($entry->status === SocialDeliveryOutbox::STATUS_DEAD) {
                                    $this->markTargetFailedForOutbox($entry, $message);

                                    return;
                                }

                                $this->recordRetryableTargetFailureForOutbox($entry, $message);
                            },
                        );
                    }

                    $this->recordConnectionError($connection, $message);
                } catch (DefinitiveSocialPublishingRejectionException $exception) {
                    $message = $this->exceptionMessage($exception);
                    $this->deliveryOutboxes->markDead(
                        $outboxId,
                        $claimToken,
                        $claimVersion,
                        'validation',
                        'provider_rejected_without_effect',
                        $message,
                        fn (SocialDeliveryOutbox $entry): mixed => $this->markTargetFailedForOutbox($entry, $message),
                    );
                    $this->recordConnectionError($connection, $message);
                } catch (InvalidArgumentException|ValidationException $exception) {
                    $message = $this->exceptionMessage($exception);
                    $this->deliveryOutboxes->markUnknown(
                        $outboxId,
                        $claimToken,
                        $claimVersion,
                        'ambiguous',
                        'invalid_result_after_request_start',
                        $message,
                        fn (SocialDeliveryOutbox $entry): mixed => $this->markTargetUnknownForOutbox($entry, $message),
                    );
                    $this->recordConnectionError($connection, $message);
                } catch (AmbiguousSocialPublishingException|ConnectionException $exception) {
                    $message = $this->exceptionMessage($exception);
                    $this->deliveryOutboxes->markUnknown(
                        $outboxId,
                        $claimToken,
                        $claimVersion,
                        'ambiguous',
                        'remote_effect_possible',
                        $message,
                        fn (SocialDeliveryOutbox $entry): mixed => $this->markTargetUnknownForOutbox($entry, $message),
                    );
                    $this->recordConnectionError($connection, $message);
                } catch (Throwable $exception) {
                    $message = $this->exceptionMessage($exception);
                    $this->deliveryOutboxes->markUnknown(
                        $outboxId,
                        $claimToken,
                        $claimVersion,
                        'ambiguous',
                        'unexpected_after_request_start',
                        $message,
                        fn (SocialDeliveryOutbox $entry): mixed => $this->markTargetUnknownForOutbox($entry, $message),
                    );
                    $this->recordConnectionError($connection, $message);
                }
            } finally {
                $connectionLock->release();
            }
        } finally {
            $this->repairTerminalOutboxAggregate($outboxId);
        }
    }

    private function queuePublication(
        User $owner,
        User $actor,
        SocialPost $post,
        string $mode,
        ?SocialAutomationRule $autopilotRule = null,
        ?array $expectedAutopilotPolicy = null,
        ?string $autopilotClaimToken = null,
        bool $retryFailedOnly = false,
    ): SocialPost {
        $tenantLock = $this->connectionDeliveryMutex->acquireTenant((int) $owner->getKey());

        if ($tenantLock === null) {
            throw ValidationException::withMessages([
                'post' => 'Pulse transport is changing for this workspace. Retry this publication shortly.',
            ]);
        }

        try {
            return $this->queuePublicationWhileTenantLocked(
                $owner,
                $actor,
                $post,
                $mode,
                $autopilotRule,
                $expectedAutopilotPolicy,
                $autopilotClaimToken,
                $retryFailedOnly,
            );
        } finally {
            $tenantLock->release();
        }
    }

    private function queuePublicationWhileTenantLocked(
        User $owner,
        User $actor,
        SocialPost $post,
        string $mode,
        ?SocialAutomationRule $autopilotRule = null,
        ?array $expectedAutopilotPolicy = null,
        ?string $autopilotClaimToken = null,
        bool $retryFailedOnly = false,
    ): SocialPost {
        $this->assertOwnership($owner, $post);

        $postId = DB::transaction(function () use (
            $owner,
            $actor,
            $post,
            $mode,
            $autopilotRule,
            $expectedAutopilotPolicy,
            $autopilotClaimToken,
            $retryFailedOnly,
        ): int {
            $lockedPost = SocialPost::query()
                ->byUser((int) $owner->id)
                ->whereKey($post->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($retryFailedOnly && $this->retryIsAlreadyQueued($lockedPost)) {
                return (int) $lockedPost->id;
            }

            $retryTargetIds = null;

            if ($retryFailedOnly) {
                $retryTargetIds = $this->assertCanRetry($lockedPost);
            } else {
                $this->assertCanQueue($lockedPost);

                if (in_array((string) $lockedPost->status, [
                    SocialPost::STATUS_FAILED,
                    SocialPost::STATUS_PARTIAL_FAILED,
                ], true)) {
                    $retryTargetIds = $this->assertCanRetry($lockedPost);
                }
            }

            $lockedPost->load(['targets.socialAccountConnection']);

            if ($lockedPost->targets->isEmpty()) {
                throw ValidationException::withMessages([
                    'post' => 'Add at least one connected Pulse target before publishing this post.',
                ]);
            }

            $dispatchFailedTargetsOnly = is_array($retryTargetIds);

            foreach ($lockedPost->targets as $target) {
                if ($dispatchFailedTargetsOnly
                    && ! in_array((int) $target->id, $retryTargetIds, true)) {
                    continue;
                }

                $connection = $target->socialAccountConnection;

                if ($connection) {
                    $this->transportPolicy->assertNewSubmissionAllowed(
                        (int) $lockedPost->user_id,
                        (string) $connection->transport_generation,
                        (int) $connection->id,
                        (string) $target->logical_destination_key,
                    );
                }
            }

            $requestedAt = now();
            $scheduledFor = null;

            if ($mode === 'scheduled') {
                $scheduledFor = $lockedPost->scheduled_for;

                if (! $scheduledFor instanceof Carbon) {
                    throw ValidationException::withMessages([
                        'scheduled_for' => 'Choose a future date before scheduling this Pulse post.',
                    ]);
                }

                if ($scheduledFor->lessThanOrEqualTo($requestedAt)) {
                    throw ValidationException::withMessages([
                        'scheduled_for' => 'Choose a future date before scheduling this Pulse post.',
                    ]);
                }
            } elseif ($lockedPost->scheduled_for !== null) {
                $lockedPost->forceFill(['scheduled_for' => null])->save();
            }

            $submissionRevision = $autopilotRule
                ? $this->revisionService->approveByAutopilotPolicy(
                    $lockedPost,
                    $actor,
                    $requestedAt,
                    (int) $autopilotRule->id,
                    $expectedAutopilotPolicy ?? [],
                    $autopilotClaimToken ?? '',
                )
                : $this->revisionService->approveDirectly(
                    $lockedPost,
                    $actor,
                    $requestedAt,
                );
            $lockedPost->load(['targets.socialAccountConnection']);

            $dispatchableTargets = collect();
            $dispatchableOutboxes = collect();

            foreach ($lockedPost->targets as $target) {
                if ($dispatchFailedTargetsOnly
                    && ! in_array((int) $target->id, $retryTargetIds, true)) {
                    continue;
                }

                $connection = $target->socialAccountConnection;
                if (! $connection) {
                    $this->markTargetFailed($target, 'This social account is no longer connected or active for publishing.');

                    continue;
                }

                if (! $this->targetBelongsToPostTenant($lockedPost, $target, $connection)) {
                    $this->markTargetFailed($target, 'This Pulse target is not valid for this workspace.');

                    continue;
                }

                if (! $this->targetUsesSupportedTransport($target, $connection)) {
                    $this->markTargetFailed($target, 'This Pulse target is not assigned to a supported delivery worker.');

                    continue;
                }

                if (! $this->connectionCanPublish($connection)) {
                    $this->markTargetFailed($target, 'This social account is no longer connected or active for publishing.');

                    continue;
                }

                $target->forceFill([
                    'status' => $mode === 'scheduled'
                        ? SocialPostTarget::STATUS_SCHEDULED
                        : SocialPostTarget::STATUS_PENDING,
                    'delivery_status' => $mode === 'scheduled'
                        ? SocialPost::DELIVERY_STATUS_SCHEDULED
                        : SocialPost::DELIVERY_STATUS_QUEUED,
                    'sync_status' => SocialPost::SYNC_STATUS_PENDING,
                    'last_submitted_revision_id' => $submissionRevision->id,
                    'payload_hash' => $submissionRevision->payload_hash,
                    'published_at' => $dispatchFailedTargetsOnly ? $target->published_at : null,
                    'failed_at' => null,
                    'failure_reason' => null,
                    'metadata' => array_merge((array) ($target->metadata ?? []), [
                        'dispatch_mode' => $mode,
                        'dispatch_requested_at' => $requestedAt->toIso8601String(),
                        'queued_for' => $scheduledFor?->toIso8601String(),
                    ]),
                ])->save();

                $dispatchableTarget = $target->fresh(['socialAccountConnection']);
                $dispatchableTargets->push($dispatchableTarget);
                [$recoveryGeneration, $supersededOutbox] = $this->outboxRecoveryContext(
                    $dispatchableTarget,
                    $submissionRevision,
                    $autopilotRule === null,
                );
                $dispatchableOutboxes->push($this->deliveryOutboxes->createForTarget(
                    $actor,
                    $dispatchableTarget,
                    $submissionRevision,
                    $connection,
                    $this->publishPayload($submissionRevision, $dispatchableTarget, $connection),
                    $this->targetUsesBufferTransport($dispatchableTarget, $connection)
                        ? $requestedAt
                        : ($scheduledFor ?? $requestedAt),
                    recoveryGeneration: $recoveryGeneration,
                    supersedes: $supersededOutbox,
                    externalOrganizationId: $this->targetUsesBufferTransport($dispatchableTarget, $connection)
                        ? (string) data_get($connection->metadata, 'buffer.organization_id')
                        : null,
                    externalChannelId: $this->targetUsesBufferTransport($dispatchableTarget, $connection)
                        ? (string) $connection->external_account_id
                        : null,
                ));
            }

            $lockedPost->forceFill([
                'updated_by_user_id' => $actor->id,
                'status' => $mode === 'scheduled'
                    ? SocialPost::STATUS_SCHEDULED
                    : ($dispatchableTargets->isNotEmpty() ? SocialPost::STATUS_PUBLISHING : SocialPost::STATUS_FAILED),
                'delivery_status' => $mode === 'scheduled'
                    ? SocialPost::DELIVERY_STATUS_SCHEDULED
                    : ($dispatchableTargets->isNotEmpty()
                        ? SocialPost::DELIVERY_STATUS_QUEUED
                        : SocialPost::DELIVERY_STATUS_FAILED),
                'delivery_status_source' => SocialPost::STATUS_SOURCE_DERIVED,
                'delivery_aggregated_at' => $requestedAt,
                'scheduled_for' => $mode === 'scheduled' ? $scheduledFor : null,
                'published_at' => $dispatchFailedTargetsOnly ? $lockedPost->published_at : null,
                'failed_at' => null,
                'failure_reason' => null,
                'metadata' => array_merge((array) ($lockedPost->metadata ?? []), [
                    'publish_mode' => $mode,
                    'publish_requested_at' => $requestedAt->toIso8601String(),
                    'publish_requested_by_user_id' => $actor->id,
                    'queued_targets_count' => $dispatchableTargets->count(),
                    ...($retryFailedOnly ? [
                        'retry_requested_at' => $requestedAt->toIso8601String(),
                        'retry_requested_by_user_id' => $actor->id,
                    ] : []),
                ]),
            ])->save();

            foreach ($dispatchableOutboxes as $outbox) {
                $dispatch = ProcessSocialDeliveryOutboxJob::dispatch((int) $outbox->id);

                if ($mode === 'scheduled'
                    && $scheduledFor instanceof Carbon
                    && (string) $outbox->transport_generation
                        === SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1) {
                    $dispatch->delay($scheduledFor);
                }
            }

            $this->refreshPostStatus($lockedPost);

            return (int) $lockedPost->id;
        });

        return SocialPost::query()
            ->with(['targets.socialAccountConnection'])
            ->findOrFail($postId);
    }

    private function assertOwnership(User $owner, SocialPost $post): void
    {
        if ((int) $post->user_id !== (int) $owner->id) {
            abort(404);
        }
    }

    private function assertCanQueue(SocialPost $post): void
    {
        if ($post->delivery_status === SocialPost::DELIVERY_STATUS_UNKNOWN
            || $post->targets()->where('delivery_status', SocialPost::DELIVERY_STATUS_UNKNOWN)->exists()) {
            throw ValidationException::withMessages([
                'post' => 'This Pulse post has an ambiguous delivery outcome and must be reconciled before any retry.',
            ]);
        }

        if ($post->status === SocialPost::STATUS_PENDING_APPROVAL) {
            throw ValidationException::withMessages([
                'post' => 'This Pulse post is waiting for approval and cannot be queued directly.',
            ]);
        }

        if ($post->status === SocialPost::STATUS_SCHEDULED
            && filled(data_get($post->metadata, 'publish_requested_at'))) {
            throw ValidationException::withMessages([
                'post' => 'This Pulse post is already scheduled for publication.',
            ]);
        }

        if ($post->status === SocialPost::STATUS_PUBLISHING) {
            throw ValidationException::withMessages([
                'post' => 'This Pulse post is already being published.',
            ]);
        }

        if ($post->status === SocialPost::STATUS_PUBLISHED) {
            throw ValidationException::withMessages([
                'post' => 'This Pulse post is already published. Duplicate it before posting it again.',
            ]);
        }
    }

    /**
     * @return array<int, int>
     */
    private function assertCanRetry(SocialPost $post): array
    {
        $targets = $post->targets()->lockForUpdate()->get();

        if ($this->retryPolicy->hasAmbiguousOutcome($post, $targets)) {
            throw ValidationException::withMessages([
                'post' => 'This Pulse post has an ambiguous delivery outcome and must be reconciled before any retry.',
            ]);
        }

        if (! in_array((string) $post->status, [
            SocialPost::STATUS_FAILED,
            SocialPost::STATUS_PARTIAL_FAILED,
        ], true)) {
            throw ValidationException::withMessages([
                'post' => 'Only a failed or partially failed Pulse publication can be retried.',
            ]);
        }

        $retryableTargetIds = $this->retryPolicy->retryableTargetIds(
            $post,
            $targets,
            lockOutboxes: true,
        );

        if ($retryableTargetIds === []) {
            throw ValidationException::withMessages([
                'post' => 'This Pulse publication has no failed target that can be retried safely.',
            ]);
        }

        return $retryableTargetIds;
    }

    private function retryIsAlreadyQueued(SocialPost $post): bool
    {
        return filled(data_get($post->metadata, 'retry_requested_at'))
            && $post->targets()
                ->where(function (Builder $query): void {
                    $query
                        ->whereIn('status', [
                            SocialPostTarget::STATUS_PENDING,
                            SocialPostTarget::STATUS_SCHEDULED,
                            SocialPostTarget::STATUS_PUBLISHING,
                        ])
                        ->orWhereIn('delivery_status', [
                            SocialPost::DELIVERY_STATUS_QUEUED,
                            SocialPost::DELIVERY_STATUS_SUBMITTED,
                            SocialPost::DELIVERY_STATUS_SCHEDULED,
                            SocialPost::DELIVERY_STATUS_REMOTE_APPROVAL_REQUIRED,
                            self::TARGET_DELIVERY_SENDING,
                        ]);
                })
                ->exists();
    }

    private function connectionCanPublish(SocialAccountConnection $connection): bool
    {
        return (bool) $connection->is_active
            && (string) $connection->status === SocialAccountConnection::STATUS_CONNECTED;
    }

    private function targetIsTerminal(SocialPostTarget $target): bool
    {
        if ($target->current_revision_id !== null) {
            return in_array((string) $target->delivery_status, [
                SocialPost::DELIVERY_STATUS_PUBLISHED,
                SocialPost::DELIVERY_STATUS_FAILED,
                SocialPost::DELIVERY_STATUS_UNKNOWN,
                SocialPost::DELIVERY_STATUS_CANCELED,
            ], true);
        }

        return in_array((string) $target->status, [
            SocialPostTarget::STATUS_PUBLISHED,
            SocialPostTarget::STATUS_FAILED,
            SocialPostTarget::STATUS_CANCELED,
        ], true);
    }

    private function targetBelongsToPostTenant(
        SocialPost $post,
        SocialPostTarget $target,
        SocialAccountConnection $connection
    ): bool {
        return (int) $target->social_post_id === (int) $post->id
            && (int) $target->social_account_connection_id === (int) $connection->id
            && (int) $post->user_id === (int) $connection->user_id;
    }

    private function outboxRuntimeIdentityIsValid(
        SocialDeliveryOutbox $outbox,
        SocialPost $post,
        SocialPostTarget $target,
        SocialPostRevision $revision,
        SocialAccountConnection $connection,
    ): bool {
        return (int) $outbox->user_id === (int) $post->user_id
            && (int) $outbox->social_post_target_id === (int) $target->id
            && (int) $outbox->social_post_revision_id === (int) $revision->id
            && (int) $outbox->social_provider_connection_id === (int) $connection->id
            && (string) $outbox->operation === SocialDeliveryOutbox::OPERATION_CREATE
            && (int) $outbox->editorial_revision === (int) $revision->revision_number
            && $this->targetBelongsToPostTenant($post, $target, $connection)
            && (string) $outbox->delivery_provider === (string) $target->delivery_provider
            && (string) $outbox->transport_generation === (string) $target->transport_generation
            && (string) $connection->delivery_provider === (string) $target->delivery_provider
            && (string) $connection->transport_generation === (string) $target->transport_generation
            && hash_equals(
                (string) $outbox->logical_destination_key,
                (string) $target->logical_destination_key,
            )
            && hash_equals(
                (string) $connection->logical_destination_key,
                (string) $target->logical_destination_key,
            )
            && ($this->targetUsesDirectTransport($target, $connection)
                || (hash_equals(
                    (string) data_get($connection->metadata, 'buffer.organization_id'),
                    (string) $outbox->external_organization_id_snapshot,
                )
                    && hash_equals(
                        (string) $connection->external_account_id,
                        (string) $outbox->external_channel_id_snapshot,
                    )));
    }

    /**
     * @return array{0:int,1:SocialDeliveryOutbox|null}
     */
    private function outboxRecoveryContext(
        SocialPostTarget $target,
        SocialPostRevision $revision,
        bool $allowExplicitRecovery,
    ): array {
        $latest = SocialDeliveryOutbox::query()
            ->where('social_post_target_id', $target->id)
            ->where('social_post_revision_id', $revision->id)
            ->where('operation', SocialDeliveryOutbox::OPERATION_CREATE)
            ->orderByDesc('recovery_generation')
            ->lockForUpdate()
            ->first();

        if (! $latest) {
            return [0, null];
        }

        if ((string) $latest->status === SocialDeliveryOutbox::STATUS_UNKNOWN
            && (string) $latest->reconciliation_resolution
                === SocialDeliveryOutbox::RECONCILIATION_RESOLUTION_ERROR) {
            throw ValidationException::withMessages([
                'post' => 'The remote delivery failed. Duplicate the post or create a new delivery instead of remapping this target.',
            ]);
        }

        if (! $allowExplicitRecovery
            || ! $this->outboxCanBeExplicitlyRecovered($latest, $target, $revision)) {
            throw ValidationException::withMessages([
                'post' => 'This Pulse delivery already has an active, completed, or ambiguous outbox operation.',
            ]);
        }

        return [(int) $latest->recovery_generation + 1, $latest];
    }

    private function outboxCanBeExplicitlyRecovered(
        SocialDeliveryOutbox $outbox,
        SocialPostTarget $target,
        SocialPostRevision $revision,
    ): bool {
        $identityMatches = (int) $outbox->user_id === (int) $revision->user_id
            && (int) $outbox->social_post_target_id === (int) $target->getKey()
            && (int) $outbox->social_post_revision_id === (int) $revision->getKey()
            && (int) $outbox->social_provider_connection_id
                === (int) $target->social_account_connection_id
            && (string) $outbox->delivery_provider === (string) $target->delivery_provider
            && (string) $outbox->transport_generation === (string) $target->transport_generation
            && hash_equals(
                (string) $outbox->logical_destination_key,
                (string) $target->logical_destination_key,
            );
        $unresolvedDead = (string) $outbox->status === SocialDeliveryOutbox::STATUS_DEAD
            && $outbox->reconciliation_resolution === null
            && $outbox->reconciliation_resolved_at === null
            && $outbox->reconciliation_observed_at === null
            && $outbox->reconciliation_resolution_source === null;

        return $identityMatches && $unresolvedDead;
    }

    private function markTargetPublishingForOutbox(SocialDeliveryOutbox $outbox): void
    {
        $target = $this->lockTargetForOutbox($outbox);

        if (! $target
            || $this->targetIsTerminal($target)
            || ! in_array((string) $target->status, [
                SocialPostTarget::STATUS_PENDING,
                SocialPostTarget::STATUS_SCHEDULED,
                SocialPostTarget::STATUS_PUBLISHING,
            ], true)) {
            throw new LogicException('A local human decision changed this Pulse target before submission.');
        }

        $target->forceFill([
            'status' => SocialPostTarget::STATUS_PUBLISHING,
            'delivery_status' => self::TARGET_DELIVERY_SENDING,
            'sync_status' => SocialPost::SYNC_STATUS_PENDING,
            'failed_at' => null,
            'failure_reason' => null,
            'metadata' => array_merge((array) ($target->metadata ?? []), [
                'publishing_started_at' => now()->toIso8601String(),
            ]),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function markTargetPublishedForOutbox(
        SocialDeliveryOutbox $outbox,
        SocialAccountConnection $connection,
        array $result,
        Carbon $publishedAt,
    ): void {
        $target = $this->lockTargetForOutbox($outbox);

        if (! $target
            || $target->status !== SocialPostTarget::STATUS_PUBLISHING
            || $target->delivery_status !== self::TARGET_DELIVERY_SENDING) {
            return;
        }

        $target->forceFill([
            'status' => SocialPostTarget::STATUS_PUBLISHED,
            'delivery_status' => SocialPost::DELIVERY_STATUS_PUBLISHED,
            'sync_status' => SocialPost::SYNC_STATUS_SYNCED,
            'provider_post_id' => (string) $outbox->provider_post_id,
            'submitted_at' => $outbox->submitted_at,
            'last_synced_at' => $publishedAt,
            'next_reconcile_at' => null,
            'published_at' => $publishedAt,
            'failed_at' => null,
            'failure_reason' => null,
            'metadata' => array_merge((array) ($target->metadata ?? []), [
                'published_via' => (string) $connection->platform,
                'provider_post_id' => (string) $outbox->provider_post_id,
                'provider_publish_message' => $this->sanitizeOperationalMessage(
                    (string) data_get($result, 'message'),
                    'Pulse delivery completed.',
                ),
                ...$this->safeProviderResultMetadata($result),
            ]),
        ])->save();
    }

    private function markTargetBufferSubmittedForOutbox(
        SocialDeliveryOutbox $outbox,
        SocialDeliveryResultData $result,
        Carbon $submittedAt,
    ): void {
        $target = $this->lockTargetForOutbox($outbox);

        if (! $target
            || $target->status !== SocialPostTarget::STATUS_PUBLISHING
            || $target->delivery_status !== self::TARGET_DELIVERY_SENDING) {
            return;
        }

        $providerStatus = Str::lower(trim((string) $result->providerStatus));
        $isSent = $providerStatus === 'sent';
        $isError = $providerStatus === 'error';
        $requiresRemoteApproval = in_array($providerStatus, ['draft', 'needs_approval'], true);
        $isScheduled = $providerStatus === 'scheduled';
        $isSending = $providerStatus === 'sending';

        $target->forceFill([
            'status' => match (true) {
                $isSent => SocialPostTarget::STATUS_PUBLISHED,
                $isError => SocialPostTarget::STATUS_FAILED,
                $isScheduled => SocialPostTarget::STATUS_SCHEDULED,
                default => SocialPostTarget::STATUS_PUBLISHING,
            },
            'delivery_status' => match (true) {
                $isSent => SocialPost::DELIVERY_STATUS_PUBLISHED,
                $isError => SocialPost::DELIVERY_STATUS_FAILED,
                $requiresRemoteApproval => SocialPost::DELIVERY_STATUS_REMOTE_APPROVAL_REQUIRED,
                $isScheduled => SocialPost::DELIVERY_STATUS_SCHEDULED,
                $isSending => self::TARGET_DELIVERY_SENDING,
                default => SocialPost::DELIVERY_STATUS_SUBMITTED,
            },
            'sync_status' => $isSent || $isError
                ? SocialPost::SYNC_STATUS_SYNCED
                : SocialPost::SYNC_STATUS_PENDING,
            'provider_post_id' => (string) $outbox->provider_post_id,
            'provider_status' => $providerStatus !== '' ? $providerStatus : null,
            'remote_scheduled_for' => $result->remoteScheduledFor,
            'submitted_at' => $outbox->submitted_at,
            'last_synced_at' => $submittedAt,
            'next_reconcile_at' => $isSent || $isError ? null : now()->addMinute(),
            'published_at' => $isSent ? $submittedAt : null,
            'failed_at' => $isError ? $submittedAt : null,
            'failure_reason' => $isError ? 'Buffer reported a delivery error.' : null,
            'provider_error_code' => $isError ? 'buffer_delivery_error' : null,
            'provider_error_message' => $isError ? 'Buffer reported a delivery error.' : null,
            'metadata' => array_merge((array) ($target->metadata ?? []), [
                'published_via' => SocialAccountConnection::DELIVERY_PROVIDER_BUFFER,
                'provider_post_id' => (string) $outbox->provider_post_id,
                'provider_status' => $providerStatus !== '' ? $providerStatus : null,
            ]),
        ])->save();
    }

    private function markTargetFailedForOutbox(SocialDeliveryOutbox $outbox, string $message): void
    {
        $target = $this->lockTargetForOutbox($outbox);

        if (! $target || $this->targetIsTerminal($target)) {
            return;
        }

        $this->markTargetFailed($target, $message);
    }

    private function markTargetUnknownForOutbox(SocialDeliveryOutbox $outbox, string $message): ?int
    {
        $target = $this->lockTargetForOutbox($outbox);

        if (! $target || $this->targetIsTerminal($target)) {
            return null;
        }

        $postId = (int) $target->social_post_id;
        $this->markTargetUnknown($target, $message);

        return $postId;
    }

    private function recordRetryableTargetFailureForOutbox(
        SocialDeliveryOutbox $outbox,
        string $message,
    ): void {
        $target = $this->lockTargetForOutbox($outbox);

        if (! $target
            || $target->status !== SocialPostTarget::STATUS_PUBLISHING
            || $target->delivery_status !== self::TARGET_DELIVERY_SENDING) {
            return;
        }

        $this->recordRetryableTargetFailure($target, $message);
    }

    private function lockTargetForOutbox(SocialDeliveryOutbox $outbox): ?SocialPostTarget
    {
        $target = SocialPostTarget::query()
            ->whereKey($outbox->social_post_target_id)
            ->where('last_submitted_revision_id', $outbox->social_post_revision_id)
            ->lockForUpdate()
            ->first();

        if (! $target) {
            return null;
        }

        $tenantId = SocialPost::query()
            ->whereKey($target->social_post_id)
            ->value('user_id');

        return (int) $tenantId === (int) $outbox->user_id ? $target : null;
    }

    private function outboxRetryDelaySeconds(int $attempts): int
    {
        return min(300, 30 * (2 ** max(0, min(4, $attempts - 1))));
    }

    private function submittedRevisionIsValid(
        SocialPost $post,
        SocialPostTarget $target,
        SocialPostRevision $revision,
    ): bool {
        if ((int) $revision->social_post_id !== (int) $post->id
            || (int) $revision->user_id !== (int) $post->user_id
            || $revision->approved_at === null
            || $revision->approval_provenance === null
            || ! hash_equals(
                (string) $revision->payload_hash,
                $this->revisionSnapshots->hashForRevision($revision),
            )
            || (int) $target->last_submitted_revision_id !== (int) $revision->id) {
            return false;
        }

        $currentRevision = $target->currentRevision;

        return $currentRevision instanceof SocialPostRevision
            && (int) $currentRevision->social_post_id === (int) $post->id
            && (int) $currentRevision->user_id === (int) $post->user_id
            && (int) $currentRevision->revision_number === (int) $target->current_editorial_revision
            && hash_equals((string) $currentRevision->payload_hash, (string) $target->payload_hash);
    }

    private function targetUsesDirectTransport(
        SocialPostTarget $target,
        SocialAccountConnection $connection
    ): bool {
        $targetKey = (string) $target->logical_destination_key;
        $connectionKey = (string) $connection->logical_destination_key;

        return (string) $target->delivery_provider === SocialAccountConnection::DELIVERY_PROVIDER_DIRECT
            && (string) $target->transport_generation
                === SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1
            && (string) $connection->delivery_provider
                === SocialAccountConnection::DELIVERY_PROVIDER_DIRECT
            && (string) $connection->transport_generation
                === SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1
            && preg_match('/\Aldk:v1:[0-9a-f]{64}\z/', $targetKey) === 1
            && hash_equals($connectionKey, $targetKey);
    }

    private function targetUsesBufferTransport(
        SocialPostTarget $target,
        SocialAccountConnection $connection,
    ): bool {
        $targetKey = (string) $target->logical_destination_key;
        $connectionKey = (string) $connection->logical_destination_key;

        return (string) $target->delivery_provider
                === SocialAccountConnection::DELIVERY_PROVIDER_BUFFER
            && (string) $target->transport_generation
                === SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1
            && (string) $connection->delivery_provider
                === SocialAccountConnection::DELIVERY_PROVIDER_BUFFER
            && (string) $connection->transport_generation
                === SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1
            && preg_match('/\Aldk:v1:[0-9a-f]{64}\z/', $targetKey) === 1
            && hash_equals($connectionKey, $targetKey);
    }

    private function targetUsesSupportedTransport(
        SocialPostTarget $target,
        SocialAccountConnection $connection,
    ): bool {
        return $this->targetUsesDirectTransport($target, $connection)
            || $this->targetUsesBufferTransport($target, $connection);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function bufferDeliveryData(
        SocialDeliveryOutbox $outbox,
        array $payload,
    ): CreateSocialDeliveryData {
        $scheduledFor = $this->resolveDate(data_get($payload, 'scheduled_for'));
        $arguments = [
            'tenantId' => (int) $outbox->user_id,
            'connectionId' => (int) $outbox->social_provider_connection_id,
            'externalOrganizationId' => (string) $outbox->external_organization_id_snapshot,
            'externalChannelId' => (string) $outbox->external_channel_id_snapshot,
            'text' => trim((string) data_get($payload, 'text')),
            'idempotencyKey' => (string) $outbox->idempotency_key,
            'correlationKey' => $outbox->correlation_key === null
                ? null
                : (string) $outbox->correlation_key,
            'assets' => $this->bufferAssetsFromPayload($payload),
            'linkUrl' => filled(data_get($payload, 'link_url'))
                ? trim((string) data_get($payload, 'link_url'))
                : null,
        ];

        if ($scheduledFor instanceof Carbon) {
            return CreateSocialDeliveryData::scheduled(
                ...$arguments,
                scheduledFor: CarbonImmutable::instance($scheduledFor),
            );
        }

        return CreateSocialDeliveryData::immediate(...$arguments);
    }

    /**
     * @return array<string, mixed>
     */
    private function publishPayload(
        SocialPostRevision $revision,
        SocialPostTarget $target,
        SocialAccountConnection $connection
    ): array {
        $baseContent = (array) $revision->base_content;
        $sourceSnapshot = (array) $revision->source_snapshot;
        $mediaAssets = $this->revisionMediaItems($revision);
        $image = collect($mediaAssets)
            ->first(fn (array $item): bool => (
                in_array(strtolower(trim((string) ($item['type'] ?? 'image'))), ['', 'image'], true)
                && trim((string) ($item['url'] ?? '')) !== ''
            ));

        return [
            'post_id' => $revision->social_post_id,
            'target_id' => $target->id,
            'revision_id' => $revision->id,
            'platform' => $connection->platform,
            'text' => trim((string) data_get($baseContent, 'content_payload.text')),
            'image_url' => trim((string) ($image['url'] ?? '')) ?: null,
            'media_assets' => array_values($mediaAssets),
            'link_url' => data_get($baseContent, 'link_url'),
            'scheduled_for' => optional($revision->scheduled_for)->toIso8601String(),
            'source_type' => data_get($sourceSnapshot, 'source_type'),
            'source_id' => data_get($sourceSnapshot, 'source_id'),
            'source_label' => data_get($sourceSnapshot, 'source_label'),
            'metadata' => [
                'connection_label' => $connection->label,
                'provider_label' => data_get($target->metadata, 'provider_label'),
                'target_type' => data_get($target->metadata, 'target_type'),
                'link_cta_label' => data_get($baseContent, 'link_cta_label'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function bufferAssetsFromPayload(array $payload): array
    {
        $assets = collect((array) data_get($payload, 'media_assets', []))
            ->filter(fn (mixed $asset): bool => is_array($asset))
            ->map(fn (array $asset): ?array => $this->bufferAssetFromPayload($asset))
            ->filter()
            ->values()
            ->all();

        if ($assets !== []) {
            return $assets;
        }

        $legacyImageUrl = trim((string) data_get($payload, 'image_url'));

        return $legacyImageUrl === '' ? [] : [[
            'type' => 'image',
            'url' => $this->publicMediaUrl($legacyImageUrl),
        ]];
    }

    /**
     * @param  array<string, mixed>  $asset
     * @return array<string, mixed>|null
     */
    private function bufferAssetFromPayload(array $asset): ?array
    {
        $type = strtolower(trim((string) ($asset['type'] ?? 'image')));
        $url = trim((string) ($asset['url'] ?? ''));

        if (! in_array($type, ['image', 'video', 'document'], true) || $url === '') {
            return null;
        }

        return array_filter([
            'type' => $type,
            'url' => $this->publicMediaUrl($url, $asset),
            'alt_text' => trim((string) ($asset['alt_text'] ?? '')) ?: null,
            'title' => trim((string) ($asset['title'] ?? '')) ?: null,
            'thumbnail_url' => filled($asset['thumbnail_url'] ?? null)
                ? ($type === 'document'
                    ? $this->documentThumbnailUrlForPayload((string) $asset['thumbnail_url'])
                    : $this->publicMediaUrl((string) $asset['thumbnail_url']))
                : null,
            'thumbnail_offset' => isset($asset['thumbnail_offset'])
                ? (int) $asset['thumbnail_offset']
                : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>|null  $asset
     */
    private function publicMediaUrl(string $value, ?array $asset = null): string
    {
        $url = trim($value);
        $url = preg_match('/\Ahttps?:\/\//i', $url) === 1
            ? $url
            : url('/'.ltrim($url, '/'));

        $publicBaseUrl = rtrim(
            trim((string) config('services.social.media.public_base_url')),
            '/',
        );

        if ($publicBaseUrl === '') {
            return $url;
        }

        $ownedUploadPath = $asset !== null
            && (string) ($asset['source'] ?? '') === 'upload'
            && (string) ($asset['disk'] ?? '') === 'public'
            ? ltrim(trim((string) ($asset['path'] ?? '')), '/')
            : '';

        if ($ownedUploadPath !== '') {
            return $publicBaseUrl.'/'.$ownedUploadPath;
        }

        $publicDiskUrl = rtrim(trim((string) config('filesystems.disks.public.url')), '/');

        if ($publicDiskUrl !== '' && Str::startsWith($url, $publicDiskUrl.'/')) {
            return $publicBaseUrl.'/'.Str::after($url, $publicDiskUrl.'/');
        }

        return $url;
    }

    private function documentThumbnailUrlForPayload(string $value): string
    {
        $url = trim($value);
        $url = preg_match('/\Ahttps?:\/\//i', $url) === 1
            ? $url
            : url('/'.ltrim($url, '/'));

        if ($this->isLegacyFirstPartyDocumentThumbnail($url)) {
            $url = $this->mediaAssetService->documentThumbnailUrl();
        }

        return $this->publicMediaUrl($url);
    }

    private function isLegacyFirstPartyDocumentThumbnail(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts)
            || (string) ($parts['path'] ?? '') !== '/brand/social-card.png') {
            return false;
        }

        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        $firstPartyHosts = collect([
            parse_url((string) config('app.url'), PHP_URL_HOST),
            parse_url((string) config('filesystems.disks.public.url'), PHP_URL_HOST),
        ])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (mixed $value): string => strtolower(trim((string) $value)))
            ->unique();

        return $host !== '' && $firstPartyHosts->contains($host);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function revisionMediaItems(SocialPostRevision $revision): array
    {
        $snapshot = (array) ($revision->media_snapshot ?? []);

        if (array_is_list($snapshot)) {
            return $snapshot;
        }

        if ((int) ($snapshot['schema_version'] ?? 0) !== 1
            || ! is_array($snapshot['items'] ?? null)
            || ! array_is_list($snapshot['items'])) {
            throw ValidationException::withMessages([
                'media' => 'The immutable Pulse media snapshot uses an unsupported schema.',
            ]);
        }

        return $snapshot['items'];
    }

    private function markTargetFailed(SocialPostTarget $target, string $message): void
    {
        $foundation = $target->current_revision_id !== null
            ? [
                'delivery_status' => SocialPost::DELIVERY_STATUS_FAILED,
                'sync_status' => SocialPost::SYNC_STATUS_ERROR,
            ]
            : [];

        $target->forceFill([
            'status' => SocialPostTarget::STATUS_FAILED,
            ...$foundation,
            'failed_at' => now(),
            'failure_reason' => $message,
            'metadata' => array_merge((array) ($target->metadata ?? []), [
                'last_publish_error' => $message,
            ]),
        ])->save();
    }

    private function markTargetUnknown(SocialPostTarget $target, string $message): void
    {
        $foundation = $target->current_revision_id !== null
            ? [
                'delivery_status' => SocialPost::DELIVERY_STATUS_UNKNOWN,
                'sync_status' => SocialPost::SYNC_STATUS_ERROR,
            ]
            : [];

        $target->forceFill([
            'status' => SocialPostTarget::STATUS_FAILED,
            ...$foundation,
            'failed_at' => null,
            'failure_reason' => null,
            'metadata' => array_merge((array) ($target->metadata ?? []), [
                'ambiguous_publish_outcome' => $message,
                'ambiguous_publish_outcome_at' => now()->toIso8601String(),
            ]),
        ])->save();
    }

    private function quarantineTargetIntegrity(
        SocialPost $post,
        SocialPostTarget $target,
        string $message,
    ): void {
        DB::transaction(function () use ($post, $target, $message): void {
            $lockedPost = SocialPost::query()
                ->whereKey($post->id)
                ->where('user_id', $post->user_id)
                ->lockForUpdate()
                ->first();
            $lockedTarget = SocialPostTarget::query()
                ->whereKey($target->id)
                ->where('social_post_id', $post->id)
                ->where('last_submitted_revision_id', $target->last_submitted_revision_id)
                ->lockForUpdate()
                ->first();

            if (! $lockedPost || ! $lockedTarget) {
                return;
            }

            $quarantinedAt = now();
            $targetMetadata = array_merge((array) ($lockedTarget->metadata ?? []), [
                'delivery_integrity_error' => $message,
                'delivery_integrity_error_at' => $quarantinedAt->toIso8601String(),
            ]);
            $postMetadata = array_merge((array) ($lockedPost->metadata ?? []), [
                'delivery_integrity_error' => $message,
                'delivery_integrity_error_at' => $quarantinedAt->toIso8601String(),
            ]);

            DB::table('social_post_targets')
                ->where('id', $lockedTarget->id)
                ->where('social_post_id', $lockedPost->id)
                ->where('last_submitted_revision_id', $lockedTarget->last_submitted_revision_id)
                ->update([
                    'status' => SocialPostTarget::STATUS_FAILED,
                    'delivery_status' => SocialPost::DELIVERY_STATUS_UNKNOWN,
                    'sync_status' => SocialPost::SYNC_STATUS_ERROR,
                    'failed_at' => null,
                    'failure_reason' => null,
                    'metadata' => json_encode(
                        $targetMetadata,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    ),
                    'updated_at' => $quarantinedAt,
                ]);

            DB::table('social_posts')
                ->where('id', $lockedPost->id)
                ->where('user_id', $lockedPost->user_id)
                ->update([
                    'status' => SocialPost::STATUS_FAILED,
                    'delivery_status' => SocialPost::DELIVERY_STATUS_UNKNOWN,
                    'sync_status' => SocialPost::SYNC_STATUS_ERROR,
                    'delivery_status_source' => SocialPost::STATUS_SOURCE_DERIVED,
                    'sync_status_source' => SocialPost::STATUS_SOURCE_DERIVED,
                    'delivery_aggregated_at' => $quarantinedAt,
                    'failed_at' => null,
                    'failure_reason' => null,
                    'metadata' => json_encode(
                        $postMetadata,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    ),
                    'updated_at' => $quarantinedAt,
                ]);
        });
    }

    private function recordRetryableTargetFailure(SocialPostTarget $target, string $message): void
    {
        $foundation = $target->current_revision_id !== null
            ? ['delivery_status' => self::TARGET_DELIVERY_SENDING]
            : [];

        $target->forceFill([
            'status' => SocialPostTarget::STATUS_PUBLISHING,
            ...$foundation,
            'failed_at' => null,
            'failure_reason' => null,
            'metadata' => array_merge((array) ($target->metadata ?? []), [
                'last_publish_error' => $message,
                'last_publish_error_at' => now()->toIso8601String(),
            ]),
        ])->save();
    }

    private function recordConnectionError(SocialAccountConnection $connection, string $message): void
    {
        $connection->forceFill([
            'last_error' => $message,
        ])->save();
    }

    private function exceptionMessage(
        ?Throwable $exception,
        string $fallback = 'This Pulse target could not be published.'
    ): string {
        $message = trim((string) $exception?->getMessage());

        return $this->sanitizeOperationalMessage($message, $fallback);
    }

    private function sanitizeOperationalMessage(string $message, string $fallback): string
    {
        return $this->messageSanitizer->sanitize($message, $fallback) ?? $fallback;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, string>
     */
    private function safeProviderResultMetadata(array $result): array
    {
        $metadata = (array) data_get($result, 'metadata', []);
        $safe = [];

        foreach (['transport', 'platform'] as $key) {
            $value = trim((string) ($metadata[$key] ?? ''));

            if ($value !== '') {
                $safe[$key] = Str::limit($value, 191, '');
            }
        }

        return $safe;
    }

    private function resolveDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        $raw = trim((string) $value);

        return $raw !== '' ? Carbon::parse($raw) : null;
    }

    private function repairTerminalPostAggregates(int $batchSize): int
    {
        $outboxIds = SocialDeliveryOutbox::query()
            ->whereIn('status', [
                SocialDeliveryOutbox::STATUS_COMPLETED,
                SocialDeliveryOutbox::STATUS_UNKNOWN,
                SocialDeliveryOutbox::STATUS_DEAD,
            ])
            ->whereNull('aggregate_repaired_at')
            ->orderBy('id')
            ->limit($batchSize)
            ->pluck('id');
        $repaired = 0;

        foreach ($outboxIds as $outboxId) {
            if ($this->repairTerminalOutboxAggregate((int) $outboxId)) {
                $repaired++;
            }
        }

        return $repaired;
    }

    private function repairTerminalOutboxAggregate(int $outboxId): bool
    {
        $outbox = SocialDeliveryOutbox::query()
            ->whereKey($outboxId)
            ->whereIn('status', [
                SocialDeliveryOutbox::STATUS_COMPLETED,
                SocialDeliveryOutbox::STATUS_UNKNOWN,
                SocialDeliveryOutbox::STATUS_DEAD,
            ])
            ->whereNull('aggregate_repaired_at')
            ->first();

        if (! $outbox) {
            return false;
        }

        $postId = SocialPostTarget::query()
            ->whereKey($outbox->social_post_target_id)
            ->value('social_post_id');
        $repairedAt = now();

        return DB::transaction(function () use ($outbox, $postId, $repairedAt): bool {
            if (is_numeric($postId)) {
                $post = SocialPost::query()
                    ->whereKey((int) $postId)
                    ->where('user_id', $outbox->user_id)
                    ->lockForUpdate()
                    ->first();

                if ($post && (string) $post->status !== SocialPost::STATUS_PENDING_APPROVAL) {
                    $targets = $post->targets()
                        ->with('socialAccountConnection')
                        ->lockForUpdate()
                        ->get();
                    $outboxTarget = $targets->first(
                        fn (SocialPostTarget $target): bool => (int) $target->id
                            === (int) $outbox->social_post_target_id,
                    );

                    if ($outboxTarget
                        && (int) $outboxTarget->last_submitted_revision_id
                            === (int) $outbox->social_post_revision_id) {
                        $post->setRelation('targets', $targets);
                        $attributes = $post->current_editorial_revision !== null
                            ? $this->foundationPostStatusAttributes($post, $targets)
                            : $this->legacyPostStatusAttributes($post, $targets);

                        try {
                            $post->forceFill($attributes)->save();
                            $this->notifications->notifyForTenant((int) $post->user_id, (int) $post->id);
                        } catch (LogicException $exception) {
                            if (! Str::startsWith($exception->getMessage(), [
                                'A Pulse post approved revision',
                                'A Pulse post must reference its current immutable revision',
                            ])) {
                                throw $exception;
                            }

                            $post->refresh();
                        }
                    }
                }
            }

            $updated = SocialDeliveryOutbox::query()
                ->whereKey($outbox->id)
                ->whereIn('status', [
                    SocialDeliveryOutbox::STATUS_COMPLETED,
                    SocialDeliveryOutbox::STATUS_UNKNOWN,
                    SocialDeliveryOutbox::STATUS_DEAD,
                ])
                ->whereNull('aggregate_repaired_at')
                ->update([
                    'aggregate_repaired_at' => $repairedAt,
                    'updated_at' => $repairedAt,
                ]);

            return $updated === 1;
        }, 3);
    }

    private function refreshPostStatus(SocialPost $post): SocialPost
    {
        return DB::transaction(function () use ($post): SocialPost {
            $lockedPost = SocialPost::query()
                ->whereKey($post->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $targets = $lockedPost->targets()
                ->with('socialAccountConnection')
                ->lockForUpdate()
                ->get();
            $lockedPost->setRelation('targets', $targets);

            $attributes = $lockedPost->current_editorial_revision !== null
                ? $this->foundationPostStatusAttributes($lockedPost, $targets)
                : $this->legacyPostStatusAttributes($lockedPost, $targets);

            $lockedPost->forceFill($attributes)->save();
            $this->notifications->notifyForTenant((int) $lockedPost->user_id, (int) $lockedPost->id);

            return $lockedPost->fresh(['targets.socialAccountConnection']);
        });
    }

    /**
     * @param  Collection<int, SocialPostTarget>  $targets
     * @return array<string, mixed>
     */
    private function foundationPostStatusAttributes(SocialPost $post, Collection $targets): array
    {
        $deliveryCounts = $this->targetAttributeCounts($targets, 'delivery_status');
        $syncCounts = $this->targetAttributeCounts($targets, 'sync_status');
        $deliveryStatus = $this->aggregateDeliveryStatus($targets);
        $syncStatus = $this->aggregateSyncStatus($targets);
        $failedTargets = $targets->filter(
            fn (SocialPostTarget $target): bool => (string) $target->delivery_status
                === SocialPost::DELIVERY_STATUS_FAILED
        );
        $publishedTargets = $targets->filter(
            fn (SocialPostTarget $target): bool => (string) $target->delivery_status
                === SocialPost::DELIVERY_STATUS_PUBLISHED
        );

        return [
            'status' => $this->legacyStatusForDelivery($post, $deliveryStatus),
            'delivery_status' => $deliveryStatus,
            'sync_status' => $syncStatus,
            'delivery_status_source' => SocialPost::STATUS_SOURCE_DERIVED,
            'sync_status_source' => SocialPost::STATUS_SOURCE_DERIVED,
            'delivery_aggregated_at' => now(),
            'published_at' => $this->latestTargetDate($publishedTargets, 'published_at'),
            'failed_at' => $this->latestTargetDate($failedTargets, 'failed_at'),
            'failure_reason' => $failedTargets->isNotEmpty()
                ? $this->buildDeliveryFailureReason($failedTargets)
                : null,
            'metadata' => array_merge((array) ($post->metadata ?? []), [
                'status_summary' => [
                    'pending' => (int) ($deliveryCounts[SocialPost::DELIVERY_STATUS_NOT_SUBMITTED] ?? 0)
                        + (int) ($deliveryCounts[SocialPost::DELIVERY_STATUS_QUEUED] ?? 0)
                        + (int) ($deliveryCounts[SocialPost::DELIVERY_STATUS_SUBMITTED] ?? 0),
                    'scheduled' => (int) ($deliveryCounts[SocialPost::DELIVERY_STATUS_SCHEDULED] ?? 0)
                        + (int) ($deliveryCounts[SocialPost::DELIVERY_STATUS_REMOTE_APPROVAL_REQUIRED] ?? 0),
                    'publishing' => (int) ($deliveryCounts[self::TARGET_DELIVERY_SENDING] ?? 0),
                    'published' => (int) ($deliveryCounts[SocialPost::DELIVERY_STATUS_PUBLISHED] ?? 0),
                    'failed' => (int) ($deliveryCounts[SocialPost::DELIVERY_STATUS_FAILED] ?? 0),
                    'canceled' => (int) ($deliveryCounts[SocialPost::DELIVERY_STATUS_CANCELED] ?? 0),
                    'unknown' => (int) ($deliveryCounts[SocialPost::DELIVERY_STATUS_UNKNOWN] ?? 0),
                    'total' => $targets->count(),
                ],
                'delivery_status_summary' => $deliveryCounts,
                'sync_status_summary' => $syncCounts,
            ]),
        ];
    }

    /**
     * @param  Collection<int, SocialPostTarget>  $targets
     * @return array<string, mixed>
     */
    private function legacyPostStatusAttributes(SocialPost $post, Collection $targets): array
    {
        $counts = $this->targetStatusCounts($targets);
        $totalTargets = max(0, $targets->count());
        $failedCount = (int) ($counts[SocialPostTarget::STATUS_FAILED] ?? 0)
            + (int) ($counts[SocialPostTarget::STATUS_CANCELED] ?? 0);
        $publishedCount = (int) ($counts[SocialPostTarget::STATUS_PUBLISHED] ?? 0);
        $publishingCount = (int) ($counts[SocialPostTarget::STATUS_PUBLISHING] ?? 0);
        $scheduledCount = (int) ($counts[SocialPostTarget::STATUS_SCHEDULED] ?? 0);
        $pendingCount = (int) ($counts[SocialPostTarget::STATUS_PENDING] ?? 0);
        $isQueuedPublication = (bool) data_get($post->metadata, 'publish_requested_at');
        $publishMode = (string) data_get($post->metadata, 'publish_mode');

        $status = SocialPost::STATUS_DRAFT;

        if ((string) $post->status === SocialPost::STATUS_PENDING_APPROVAL && ! $isQueuedPublication) {
            $status = SocialPost::STATUS_PENDING_APPROVAL;
        } elseif ($publishingCount > 0) {
            $status = SocialPost::STATUS_PUBLISHING;
        } elseif ($totalTargets > 0 && $publishedCount === $totalTargets) {
            $status = SocialPost::STATUS_PUBLISHED;
        } elseif ($totalTargets > 0 && $failedCount === $totalTargets) {
            $status = SocialPost::STATUS_FAILED;
        } elseif ($failedCount > 0 && ($publishedCount > 0 || $scheduledCount > 0 || $pendingCount > 0)) {
            $status = SocialPost::STATUS_PARTIAL_FAILED;
        } elseif ($scheduledCount > 0) {
            $status = SocialPost::STATUS_SCHEDULED;
        } elseif ($pendingCount > 0 && $isQueuedPublication && $publishMode === 'immediate') {
            $status = SocialPost::STATUS_PUBLISHING;
        }

        $publishedAt = $publishedCount > 0
            ? $this->latestTargetDate($targets, 'published_at')
            : null;

        $failedAt = $failedCount > 0
            ? $this->latestTargetDate($targets, 'failed_at')
            : null;

        $failureReason = $failedCount > 0
            ? $this->buildFailureReason($targets, $failedCount)
            : null;

        return [
            'status' => $status,
            'published_at' => $publishedAt,
            'failed_at' => $failedAt,
            'failure_reason' => $failureReason,
            'metadata' => array_merge((array) ($post->metadata ?? []), [
                'status_summary' => [
                    'pending' => $pendingCount,
                    'scheduled' => $scheduledCount,
                    'publishing' => $publishingCount,
                    'published' => $publishedCount,
                    'failed' => $failedCount,
                    'total' => $totalTargets,
                ],
            ]),
        ];
    }

    /**
     * @param  Collection<int, SocialPostTarget>  $targets
     */
    private function aggregateDeliveryStatus(Collection $targets): string
    {
        if ($targets->isEmpty()) {
            return SocialPost::DELIVERY_STATUS_NOT_SUBMITTED;
        }

        $allowed = [
            SocialPost::DELIVERY_STATUS_NOT_SUBMITTED,
            SocialPost::DELIVERY_STATUS_QUEUED,
            SocialPost::DELIVERY_STATUS_SUBMITTED,
            SocialPost::DELIVERY_STATUS_SCHEDULED,
            SocialPost::DELIVERY_STATUS_REMOTE_APPROVAL_REQUIRED,
            self::TARGET_DELIVERY_SENDING,
            SocialPost::DELIVERY_STATUS_PUBLISHED,
            SocialPost::DELIVERY_STATUS_FAILED,
            SocialPost::DELIVERY_STATUS_UNKNOWN,
            SocialPost::DELIVERY_STATUS_CANCELED,
        ];
        $statuses = $targets->map(
            fn (SocialPostTarget $target): string => (string) $target->delivery_status
        );

        if ($statuses->contains(fn (string $status): bool => ! in_array($status, $allowed, true))
            || $statuses->contains(SocialPost::DELIVERY_STATUS_UNKNOWN)) {
            return SocialPost::DELIVERY_STATUS_UNKNOWN;
        }

        $nonCanceled = $statuses->reject(
            fn (string $status): bool => $status === SocialPost::DELIVERY_STATUS_CANCELED
        );
        $failedCount = $nonCanceled->filter(
            fn (string $status): bool => $status === SocialPost::DELIVERY_STATUS_FAILED
        )->count();

        if ($failedCount > 0 && $failedCount < $nonCanceled->count()) {
            return SocialPost::DELIVERY_STATUS_PARTIAL_FAILED;
        }

        if ($nonCanceled->isNotEmpty() && $failedCount === $nonCanceled->count()) {
            return SocialPost::DELIVERY_STATUS_FAILED;
        }

        if ($nonCanceled->contains(self::TARGET_DELIVERY_SENDING)) {
            return SocialPost::DELIVERY_STATUS_PUBLISHING;
        }

        foreach ([
            SocialPost::DELIVERY_STATUS_REMOTE_APPROVAL_REQUIRED,
            SocialPost::DELIVERY_STATUS_SCHEDULED,
            SocialPost::DELIVERY_STATUS_SUBMITTED,
            SocialPost::DELIVERY_STATUS_QUEUED,
        ] as $status) {
            if ($nonCanceled->contains($status)) {
                return $status;
            }
        }

        if ($nonCanceled->isNotEmpty()
            && $nonCanceled->every(
                fn (string $status): bool => $status === SocialPost::DELIVERY_STATUS_PUBLISHED
            )) {
            return SocialPost::DELIVERY_STATUS_PUBLISHED;
        }

        if ($nonCanceled->isEmpty()) {
            return SocialPost::DELIVERY_STATUS_CANCELED;
        }

        return SocialPost::DELIVERY_STATUS_NOT_SUBMITTED;
    }

    /**
     * @param  Collection<int, SocialPostTarget>  $targets
     */
    private function aggregateSyncStatus(Collection $targets): string
    {
        if ($targets->isEmpty()) {
            return SocialPost::SYNC_STATUS_PENDING;
        }

        $allowed = [
            SocialPost::SYNC_STATUS_PENDING,
            SocialPost::SYNC_STATUS_SYNCED,
            SocialPost::SYNC_STATUS_ERROR,
            SocialPost::SYNC_STATUS_RECONNECT_REQUIRED,
        ];
        $statuses = $targets->map(
            fn (SocialPostTarget $target): string => (string) $target->sync_status
        );

        if ($statuses->contains(fn (string $status): bool => ! in_array($status, $allowed, true))) {
            return SocialPost::SYNC_STATUS_ERROR;
        }

        foreach ([
            SocialPost::SYNC_STATUS_RECONNECT_REQUIRED,
            SocialPost::SYNC_STATUS_ERROR,
            SocialPost::SYNC_STATUS_PENDING,
        ] as $status) {
            if ($statuses->contains($status)) {
                return $status;
            }
        }

        return SocialPost::SYNC_STATUS_SYNCED;
    }

    private function legacyStatusForDelivery(SocialPost $post, string $deliveryStatus): string
    {
        return match ($deliveryStatus) {
            SocialPost::DELIVERY_STATUS_QUEUED,
            SocialPost::DELIVERY_STATUS_SUBMITTED,
            SocialPost::DELIVERY_STATUS_REMOTE_APPROVAL_REQUIRED,
            SocialPost::DELIVERY_STATUS_PUBLISHING => SocialPost::STATUS_PUBLISHING,
            SocialPost::DELIVERY_STATUS_SCHEDULED => SocialPost::STATUS_SCHEDULED,
            SocialPost::DELIVERY_STATUS_PUBLISHED => SocialPost::STATUS_PUBLISHED,
            SocialPost::DELIVERY_STATUS_PARTIAL_FAILED => SocialPost::STATUS_PARTIAL_FAILED,
            SocialPost::DELIVERY_STATUS_FAILED,
            SocialPost::DELIVERY_STATUS_UNKNOWN,
            SocialPost::DELIVERY_STATUS_CANCELED => SocialPost::STATUS_FAILED,
            default => (string) $post->status === SocialPost::STATUS_PENDING_APPROVAL
                && ! data_get($post->metadata, 'publish_requested_at')
                    ? SocialPost::STATUS_PENDING_APPROVAL
                    : SocialPost::STATUS_DRAFT,
        };
    }

    /**
     * @param  Collection<int, SocialPostTarget>  $targets
     * @return array<string, int>
     */
    private function targetAttributeCounts(Collection $targets, string $attribute): array
    {
        return $targets
            ->groupBy(fn (SocialPostTarget $target): string => (string) $target->getAttribute($attribute))
            ->map(fn (Collection $group): int => $group->count())
            ->all();
    }

    /**
     * @param  Collection<int, SocialPostTarget>  $targets
     */
    private function latestTargetDate(Collection $targets, string $attribute): ?Carbon
    {
        $value = $targets
            ->pluck($attribute)
            ->filter()
            ->sortByDesc(fn ($date) => $date?->timestamp ?? 0)
            ->first();

        return $value instanceof Carbon ? $value : null;
    }

    /**
     * @param  Collection<int, SocialPostTarget>  $failedTargets
     */
    private function buildDeliveryFailureReason(Collection $failedTargets): string
    {
        $failedCount = $failedTargets->count();
        $reasons = $failedTargets
            ->pluck('failure_reason')
            ->filter(fn ($reason): bool => trim((string) $reason) !== '')
            ->values();

        if ($reasons->isEmpty()) {
            return $failedCount === 1
                ? '1 Pulse target failed.'
                : sprintf('%d Pulse targets failed.', $failedCount);
        }

        if ($failedCount === 1) {
            return (string) $reasons->first();
        }

        return sprintf('%d Pulse targets failed. %s', $failedCount, (string) $reasons->first());
    }

    /**
     * @param  Collection<int, SocialPostTarget>  $targets
     * @return array<string, int>
     */
    private function targetStatusCounts(Collection $targets): array
    {
        return $targets
            ->groupBy(fn (SocialPostTarget $target) => (string) $target->status)
            ->map(fn (Collection $group) => $group->count())
            ->all();
    }

    /**
     * @param  Collection<int, SocialPostTarget>  $targets
     */
    private function buildFailureReason(Collection $targets, int $failedCount): string
    {
        $reasons = $targets
            ->filter(fn (SocialPostTarget $target) => in_array((string) $target->status, [
                SocialPostTarget::STATUS_FAILED,
                SocialPostTarget::STATUS_CANCELED,
            ], true))
            ->pluck('failure_reason')
            ->filter(fn ($reason) => trim((string) $reason) !== '')
            ->values();

        if ($reasons->isEmpty()) {
            return $failedCount === 1
                ? '1 Pulse target failed.'
                : sprintf('%d Pulse targets failed.', $failedCount);
        }

        if ($failedCount === 1) {
            return (string) $reasons->first();
        }

        return sprintf('%d Pulse targets failed. %s', $failedCount, (string) $reasons->first());
    }
}
