<?php

namespace App\Services\Social;

use App\Jobs\ProcessSocialDeliveryOutboxJob;
use App\Models\SocialAccountConnection;
use App\Models\SocialDeliveryOutbox;
use App\Models\SocialPost;
use App\Models\SocialPostRevision;
use App\Models\SocialPostTarget;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use LogicException;

final class SocialDeliveryOutboxService
{
    private const DEFAULT_LEASE_SECONDS = 120;

    private const DEFAULT_MAX_ATTEMPTS = 5;

    private const TRANSACTION_ATTEMPTS = 3;

    private const MAX_BATCH_SIZE = 1000;

    private const MAX_PAYLOAD_BYTES = 262_144;

    public function __construct(
        private SocialPostRevisionSnapshotService $revisionSnapshots,
        private SocialOperationalMessageSanitizer $messageSanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createForTarget(
        User $actor,
        SocialPostTarget $target,
        SocialPostRevision $revision,
        SocialAccountConnection $connection,
        array $payload,
        DateTimeInterface $availableAt,
        string $operation = SocialDeliveryOutbox::OPERATION_CREATE,
        int $recoveryGeneration = 0,
        ?SocialDeliveryOutbox $supersedes = null,
        ?string $externalOrganizationId = null,
        ?string $externalChannelId = null,
    ): SocialDeliveryOutbox {
        if (DB::transactionLevel() < 1) {
            throw new LogicException(
                'A Pulse delivery outbox entry must be created inside the publication transaction.'
            );
        }

        $target = SocialPostTarget::query()->findOrFail($target->getKey());
        $revision = SocialPostRevision::query()->findOrFail($revision->getKey());
        $connection = SocialAccountConnection::query()->findOrFail($connection->getKey());
        $post = SocialPost::query()->findOrFail($target->social_post_id);
        $tenantId = $actor->accountOwnerId();

        $this->assertOperation($operation);
        $this->assertTenantRevisionAndTransport(
            $tenantId,
            $post,
            $target,
            $revision,
            $connection,
        );
        $this->assertRecovery($tenantId, $target, $revision, $operation, $recoveryGeneration, $supersedes);

        $canonicalPayload = $this->canonicalPayload($payload, $post, $target, $revision, $connection);
        $payloadHash = hash('sha256', $this->encodeCanonical($canonicalPayload));
        $availableAt = CarbonImmutable::instance($availableAt)->utc()->startOfSecond();
        $externalOrganizationId = $this->normalizeProviderIdentifier(
            $externalOrganizationId,
            'organization',
        );
        $externalChannelId = $this->normalizeProviderIdentifier($externalChannelId, 'channel');
        $idempotencyKey = $this->idempotencyKey(
            $tenantId,
            $target,
            $revision,
            $operation,
            $recoveryGeneration,
        );

        $attributes = [
            'user_id' => $tenantId,
            'social_post_target_id' => $target->getKey(),
            'social_post_revision_id' => $revision->getKey(),
            'social_provider_connection_id' => $connection->getKey(),
            'operation' => $operation,
            'delivery_provider' => $target->delivery_provider,
            'transport_generation' => $target->transport_generation,
            'logical_destination_key' => $target->logical_destination_key,
            'external_organization_id_snapshot' => $externalOrganizationId,
            'external_channel_id_snapshot' => $externalChannelId,
            'editorial_revision' => $revision->revision_number,
            'recovery_generation' => $recoveryGeneration,
            'supersedes_outbox_id' => $supersedes?->getKey(),
            'correlation_key' => hash('sha256', 'pulse-delivery-correlation:v1|'.$idempotencyKey),
            'payload_hash' => $payloadHash,
            'payload' => $canonicalPayload,
            'status' => SocialDeliveryOutbox::STATUS_PENDING,
            'attempts' => 0,
            'available_at' => $availableAt,
            'claim_version' => 0,
        ];

        $outbox = SocialDeliveryOutbox::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            $attributes,
        );

        $this->assertIdempotentMatch($outbox, $attributes, $idempotencyKey);

        return $outbox;
    }

    /**
     * @return array<string, mixed>
     */
    public function verifiedPayload(SocialDeliveryOutbox $outbox): array
    {
        $this->assertPositiveId((int) $outbox->getKey(), 'outbox');
        $outbox = SocialDeliveryOutbox::query()->findOrFail($outbox->getKey());

        try {
            $payload = $outbox->payload;
        } catch (DecryptException $exception) {
            throw new LogicException(
                'The Pulse delivery outbox payload failed its integrity check.',
                previous: $exception,
            );
        }

        if (! is_array($payload)) {
            throw new LogicException('The Pulse delivery outbox payload failed its integrity check.');
        }

        try {
            $this->assertPayloadHasNoSecrets($payload);
            $canonicalPayload = $this->canonicalize($payload);
            $computedHash = hash('sha256', $this->encodeCanonical($canonicalPayload));
        } catch (InvalidArgumentException $exception) {
            throw new LogicException(
                'The Pulse delivery outbox payload failed its integrity check.',
                previous: $exception,
            );
        }

        if (preg_match('/\A[0-9a-f]{64}\z/', (string) $outbox->payload_hash) !== 1
            || ! hash_equals((string) $outbox->payload_hash, $computedHash)) {
            throw new LogicException('The Pulse delivery outbox payload failed its integrity check.');
        }

        return $canonicalPayload;
    }

    /**
     * @return array{outbox:SocialDeliveryOutbox,claim_token:string,claim_version:int}|null
     */
    public function claim(
        int $outboxId,
        string $claimedBy,
        int $leaseSeconds = self::DEFAULT_LEASE_SECONDS,
    ): ?array {
        $this->assertPositiveId($outboxId, 'outbox');
        $claimedBy = $this->normalizeClaimedBy($claimedBy);

        if ($leaseSeconds < 1 || $leaseSeconds > 3600) {
            throw new InvalidArgumentException('The Pulse delivery outbox lease duration is invalid.');
        }

        $claimedAt = now();
        $claimToken = (string) Str::uuid();
        $updated = SocialDeliveryOutbox::query()
            ->whereKey($outboxId)
            ->whereIn('status', [
                SocialDeliveryOutbox::STATUS_PENDING,
                SocialDeliveryOutbox::STATUS_RETRYABLE,
            ])
            ->where('available_at', '<=', $claimedAt)
            ->whereNull('request_started_at')
            ->update([
                'status' => SocialDeliveryOutbox::STATUS_CLAIMED,
                'attempts' => DB::raw('attempts + 1'),
                'claimed_at' => $claimedAt,
                'claim_expires_at' => $claimedAt->copy()->addSeconds($leaseSeconds),
                'claimed_by' => $claimedBy,
                'claim_token' => $claimToken,
                'claim_version' => DB::raw('claim_version + 1'),
                'updated_at' => $claimedAt,
            ]);

        if ($updated !== 1) {
            return null;
        }

        $outbox = SocialDeliveryOutbox::query()
            ->whereKey($outboxId)
            ->where('claim_token', $claimToken)
            ->firstOrFail();

        return [
            'outbox' => $outbox,
            'claim_token' => $claimToken,
            'claim_version' => (int) $outbox->claim_version,
        ];
    }

    /**
     * @param  (callable(SocialDeliveryOutbox): void)|null  $afterTransition
     */
    public function startSubmitting(
        int $outboxId,
        string $claimToken,
        int $claimVersion,
        ?callable $afterTransition = null,
    ): bool {
        return $this->casTransition(
            $outboxId,
            $claimToken,
            $claimVersion,
            [SocialDeliveryOutbox::STATUS_CLAIMED],
            [
                'status' => SocialDeliveryOutbox::STATUS_SUBMITTING,
                'request_started_at' => now(),
            ],
            $afterTransition,
            requireRequestNotStarted: true,
        );
    }

    /**
     * @param  (callable(SocialDeliveryOutbox): void)|null  $afterTransition
     */
    public function markCompleted(
        int $outboxId,
        string $claimToken,
        int $claimVersion,
        string $providerPostId,
        ?DateTimeInterface $submittedAt = null,
        ?callable $afterTransition = null,
    ): bool {
        $providerPostId = trim($providerPostId);

        if ($providerPostId === '' || Str::length($providerPostId) > 191) {
            throw new InvalidArgumentException('The Pulse provider post identifier is invalid.');
        }

        $processedAt = now();

        return $this->casTransition(
            $outboxId,
            $claimToken,
            $claimVersion,
            [SocialDeliveryOutbox::STATUS_SUBMITTING],
            [
                'status' => SocialDeliveryOutbox::STATUS_COMPLETED,
                'provider_post_id' => $providerPostId,
                'submitted_at' => $submittedAt === null
                    ? $processedAt
                    : CarbonImmutable::instance($submittedAt)->utc()->startOfSecond(),
                'processed_at' => $processedAt,
                'claim_expires_at' => null,
                'claim_token' => null,
                'last_error_category' => null,
                'last_error_code' => null,
                'last_error_message' => null,
            ],
            $afterTransition,
        );
    }

    /**
     * @param  (callable(SocialDeliveryOutbox): void)|null  $afterTransition
     */
    public function markDead(
        int $outboxId,
        string $claimToken,
        int $claimVersion,
        string $errorCategory,
        ?string $errorCode,
        ?string $errorMessage,
        ?callable $afterTransition = null,
    ): bool {
        $processedAt = now();

        return $this->casTransition(
            $outboxId,
            $claimToken,
            $claimVersion,
            [
                SocialDeliveryOutbox::STATUS_CLAIMED,
                SocialDeliveryOutbox::STATUS_SUBMITTING,
            ],
            [
                'status' => SocialDeliveryOutbox::STATUS_DEAD,
                'processed_at' => $processedAt,
                'claim_expires_at' => null,
                'claim_token' => null,
                ...$this->errorAttributes($errorCategory, $errorCode, $errorMessage),
            ],
            $afterTransition,
        );
    }

    /**
     * @param  (callable(SocialDeliveryOutbox): void)|null  $afterTransition
     */
    public function markUnknown(
        int $outboxId,
        string $claimToken,
        int $claimVersion,
        string $errorCategory,
        ?string $errorCode,
        ?string $errorMessage,
        ?callable $afterTransition = null,
    ): bool {
        $processedAt = now();

        return $this->casTransition(
            $outboxId,
            $claimToken,
            $claimVersion,
            [SocialDeliveryOutbox::STATUS_SUBMITTING],
            [
                'status' => SocialDeliveryOutbox::STATUS_UNKNOWN,
                'processed_at' => $processedAt,
                'claim_expires_at' => null,
                'claim_token' => null,
                ...$this->errorAttributes($errorCategory, $errorCode, $errorMessage),
            ],
            $afterTransition,
        );
    }

    /**
     * @param  (callable(SocialDeliveryOutbox): void)|null  $afterTransition
     */
    public function markRetryable(
        int $outboxId,
        string $claimToken,
        int $claimVersion,
        DateTimeInterface $availableAt,
        string $errorCategory,
        ?string $errorCode,
        ?string $errorMessage,
        int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        ?callable $afterTransition = null,
    ): ?string {
        $this->assertClaimIdentity($outboxId, $claimToken, $claimVersion);

        if ($maxAttempts < 1 || $maxAttempts > 100) {
            throw new InvalidArgumentException('The Pulse delivery outbox attempt limit is invalid.');
        }

        $availableAt = CarbonImmutable::instance($availableAt)->utc()->startOfSecond();
        $transitionedAt = now();

        return DB::transaction(function () use (
            $outboxId,
            $claimToken,
            $claimVersion,
            $availableAt,
            $errorCategory,
            $errorCode,
            $errorMessage,
            $maxAttempts,
            $afterTransition,
            $transitionedAt,
        ): ?string {
            $candidate = $this->activeClaimQuery(
                $outboxId,
                $claimToken,
                $claimVersion,
                [
                    SocialDeliveryOutbox::STATUS_CLAIMED,
                    SocialDeliveryOutbox::STATUS_SUBMITTING,
                ],
                $transitionedAt,
            )
                ->first(['id', 'attempts']);

            if (! $candidate) {
                return null;
            }

            $status = (int) $candidate->attempts >= $maxAttempts
                ? SocialDeliveryOutbox::STATUS_DEAD
                : SocialDeliveryOutbox::STATUS_RETRYABLE;
            $attributes = [
                'status' => $status,
                'available_at' => $availableAt,
                'claimed_at' => null,
                'claim_expires_at' => null,
                'claimed_by' => null,
                'claim_token' => null,
                'request_started_at' => null,
                'processed_at' => $status === SocialDeliveryOutbox::STATUS_DEAD
                    ? $transitionedAt
                    : null,
                ...$this->errorAttributes($errorCategory, $errorCode, $errorMessage),
                'updated_at' => $transitionedAt,
            ];
            $updated = $this->activeClaimQuery(
                $outboxId,
                $claimToken,
                $claimVersion,
                [
                    SocialDeliveryOutbox::STATUS_CLAIMED,
                    SocialDeliveryOutbox::STATUS_SUBMITTING,
                ],
                $transitionedAt,
            )
                ->where('attempts', $candidate->attempts)
                ->update($attributes);

            if ($updated !== 1) {
                return null;
            }

            $outbox = SocialDeliveryOutbox::query()->findOrFail($outboxId);
            if ($afterTransition !== null) {
                $afterTransition($outbox);
            }

            return $status;
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function suspendBeforeRequest(
        int $outboxId,
        string $claimToken,
        int $claimVersion,
        string $reasonCode = 'transport_transition_hold',
    ): bool {
        if (preg_match('/\A[a-z][a-z0-9_]{1,63}\z/', $reasonCode) !== 1) {
            throw new InvalidArgumentException('The Pulse delivery suspension reason is invalid.');
        }

        return $this->casTransition(
            $outboxId,
            $claimToken,
            $claimVersion,
            [SocialDeliveryOutbox::STATUS_CLAIMED],
            [
                'status' => SocialDeliveryOutbox::STATUS_SUSPENDED,
                'attempts' => DB::raw('CASE WHEN attempts > 0 THEN attempts - 1 ELSE 0 END'),
                'claimed_at' => null,
                'claim_expires_at' => null,
                'claimed_by' => null,
                'claim_token' => null,
                'request_started_at' => null,
                'last_error_category' => 'control_plane',
                'last_error_code' => $reasonCode,
                'last_error_message' => 'Pulse delivery is suspended before any remote request.',
            ],
            afterTransition: null,
            requireRequestNotStarted: true,
        );
    }

    public function resumeSuspendedForTenantTransport(
        int $tenantId,
        string $deliveryProvider,
        string $transportGeneration,
    ): int {
        $this->assertPositiveId($tenantId, 'tenant');

        $transportPairIsSupported = ($deliveryProvider === SocialAccountConnection::DELIVERY_PROVIDER_DIRECT
                && $transportGeneration === SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1)
            || ($deliveryProvider === SocialAccountConnection::DELIVERY_PROVIDER_BUFFER
                && $transportGeneration === SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1);

        if (! $transportPairIsSupported) {
            throw new InvalidArgumentException(
                'Suspended Pulse deliveries require an exact supported transport identity.',
            );
        }

        if (DB::transactionLevel() < 1) {
            throw new LogicException(
                'Suspended Pulse deliveries may only resume inside an audited transport transition.',
            );
        }

        $resumedAt = now();

        return SocialDeliveryOutbox::query()
            ->where('user_id', $tenantId)
            ->where('delivery_provider', $deliveryProvider)
            ->where('transport_generation', $transportGeneration)
            ->where('status', SocialDeliveryOutbox::STATUS_SUSPENDED)
            ->whereNull('request_started_at')
            ->whereNull('processed_at')
            ->whereNull('provider_post_id')
            ->where('last_error_category', 'control_plane')
            ->where('last_error_code', 'transport_transition_hold')
            ->update([
                'status' => SocialDeliveryOutbox::STATUS_PENDING,
                'available_at' => $resumedAt,
                'last_error_category' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'updated_at' => $resumedAt,
            ]);
    }

    /**
     * @param  (callable(SocialDeliveryOutbox, string): void)|null  $afterRecovery
     * @return array{pending:int,unknown:int}
     */
    public function recoverExpiredLeases(
        int $batchSize = 100,
        ?callable $afterRecovery = null,
    ): array {
        $batchSize = $this->normalizeBatchSize($batchSize);
        $expiredAt = now();
        $outboxIds = SocialDeliveryOutbox::query()
            ->whereIn('status', [
                SocialDeliveryOutbox::STATUS_CLAIMED,
                SocialDeliveryOutbox::STATUS_SUBMITTING,
            ])
            ->whereNotNull('claim_expires_at')
            ->where('claim_expires_at', '<=', $expiredAt)
            ->orderBy('claim_expires_at')
            ->orderBy('id')
            ->limit($batchSize)
            ->pluck('id');
        $recovered = ['pending' => 0, 'unknown' => 0];

        foreach ($outboxIds as $outboxId) {
            $status = DB::transaction(function () use (
                $outboxId,
                $expiredAt,
                $afterRecovery,
            ): ?string {
                $outbox = SocialDeliveryOutbox::query()
                    ->whereKey($outboxId)
                    ->whereIn('status', [
                        SocialDeliveryOutbox::STATUS_CLAIMED,
                        SocialDeliveryOutbox::STATUS_SUBMITTING,
                    ])
                    ->whereNotNull('claim_expires_at')
                    ->where('claim_expires_at', '<=', $expiredAt)
                    ->lockForUpdate()
                    ->first();

                if (! $outbox) {
                    return null;
                }

                $canSafelyRetry = $outbox->status === SocialDeliveryOutbox::STATUS_CLAIMED
                    && $outbox->request_started_at === null;
                $status = $canSafelyRetry
                    ? SocialDeliveryOutbox::STATUS_PENDING
                    : SocialDeliveryOutbox::STATUS_UNKNOWN;
                $attributes = [
                    'status' => $status,
                    'claim_expires_at' => null,
                    'claim_token' => null,
                    'claim_version' => DB::raw('claim_version + 1'),
                    'updated_at' => $expiredAt,
                ];

                if ($canSafelyRetry) {
                    $attributes = [
                        ...$attributes,
                        'available_at' => $expiredAt,
                        'claimed_at' => null,
                        'claimed_by' => null,
                        'request_started_at' => null,
                        'last_error_category' => 'lease_recovered',
                        'last_error_code' => 'pre_request_lease_expired',
                        'last_error_message' => 'The expired pre-request lease was safely returned to pending.',
                    ];
                } else {
                    $attributes = [
                        ...$attributes,
                        'processed_at' => $expiredAt,
                        'last_error_category' => 'ambiguous',
                        'last_error_code' => 'lease_expired_after_request_start',
                        'last_error_message' => 'The delivery lease expired after a remote effect became possible.',
                    ];
                }

                $updated = $outbox->newQuery()
                    ->whereKey($outbox->getKey())
                    ->where('claim_version', $outbox->claim_version)
                    ->update($attributes);

                if ($updated !== 1) {
                    return null;
                }

                $recoveredOutbox = SocialDeliveryOutbox::query()->findOrFail($outbox->getKey());
                if ($afterRecovery !== null) {
                    $afterRecovery($recoveredOutbox, $status);
                }

                return $status;
            }, self::TRANSACTION_ATTEMPTS);

            if ($status === SocialDeliveryOutbox::STATUS_PENDING) {
                $recovered['pending']++;
            } elseif ($status === SocialDeliveryOutbox::STATUS_UNKNOWN) {
                $recovered['unknown']++;
            }
        }

        return $recovered;
    }

    public function dispatchDue(int $batchSize = 100): int
    {
        $batchSize = $this->normalizeBatchSize($batchSize);
        $dueAt = now();
        $outboxIds = SocialDeliveryOutbox::query()
            ->whereIn('status', [
                SocialDeliveryOutbox::STATUS_PENDING,
                SocialDeliveryOutbox::STATUS_RETRYABLE,
            ])
            ->whereNull('request_started_at')
            ->where('available_at', '<=', $dueAt)
            ->orderBy('available_at')
            ->orderBy('id')
            ->limit($batchSize)
            ->pluck('id');

        foreach ($outboxIds as $outboxId) {
            ProcessSocialDeliveryOutboxJob::dispatch((int) $outboxId);
        }

        return $outboxIds->count();
    }

    public function purgeForTenantDeletion(int $tenantId): int
    {
        $this->assertPositiveId($tenantId, 'tenant');

        if (DB::transactionLevel() < 1) {
            throw new LogicException(
                'Pulse delivery history must be purged inside the account deletion transaction.'
            );
        }

        $outboxes = SocialDeliveryOutbox::query()
            ->where('user_id', $tenantId)
            ->lockForUpdate()
            ->get([
                'id',
                'social_post_target_id',
                'social_post_revision_id',
                'social_provider_connection_id',
            ]);

        if ($outboxes->isEmpty()) {
            return 0;
        }

        $targetIds = $outboxes->pluck('social_post_target_id')->unique()->values();
        $revisionIds = $outboxes->pluck('social_post_revision_id')->unique()->values();
        $connectionIds = $outboxes->pluck('social_provider_connection_id')->unique()->values();
        $ownedTargetCount = DB::table('social_post_targets as target')
            ->join('social_posts as post', 'post.id', '=', 'target.social_post_id')
            ->whereIn('target.id', $targetIds)
            ->where('post.user_id', $tenantId)
            ->distinct()
            ->count('target.id');
        $ownedRevisionCount = SocialPostRevision::query()
            ->whereIn('id', $revisionIds)
            ->where('user_id', $tenantId)
            ->count();
        $ownedConnectionCount = SocialAccountConnection::query()
            ->whereIn('id', $connectionIds)
            ->where('user_id', $tenantId)
            ->count();
        $hasForeignReferences = SocialDeliveryOutbox::query()
            ->where('user_id', '!=', $tenantId)
            ->where(function (Builder $query) use ($targetIds, $revisionIds, $connectionIds): void {
                $query->whereIn('social_post_target_id', $targetIds)
                    ->orWhereIn('social_post_revision_id', $revisionIds)
                    ->orWhereIn('social_provider_connection_id', $connectionIds);
            })
            ->exists();

        if ($ownedTargetCount !== $targetIds->count()
            || $ownedRevisionCount !== $revisionIds->count()
            || $ownedConnectionCount !== $connectionIds->count()
            || $hasForeignReferences) {
            throw new LogicException(
                'Pulse delivery history crosses a workspace boundary and cannot be purged automatically.'
            );
        }

        $outboxIds = $outboxes->pluck('id');
        DB::table('social_delivery_outbox')
            ->whereIn('id', $outboxIds)
            ->update(['supersedes_outbox_id' => null]);
        $deleted = DB::table('social_delivery_outbox')
            ->where('user_id', $tenantId)
            ->whereIn('id', $outboxIds)
            ->delete();

        if ($deleted !== $outboxes->count()) {
            throw new LogicException('Pulse delivery history changed during account deletion.');
        }

        return $deleted;
    }

    /**
     * @param  array<int, string>  $fromStatuses
     * @param  array<string, mixed>  $attributes
     * @param  (callable(SocialDeliveryOutbox): void)|null  $afterTransition
     */
    private function casTransition(
        int $outboxId,
        string $claimToken,
        int $claimVersion,
        array $fromStatuses,
        array $attributes,
        ?callable $afterTransition,
        bool $requireRequestNotStarted = false,
    ): bool {
        $this->assertClaimIdentity($outboxId, $claimToken, $claimVersion);
        $transitionedAt = now();

        return DB::transaction(function () use (
            $outboxId,
            $claimToken,
            $claimVersion,
            $fromStatuses,
            $attributes,
            $afterTransition,
            $requireRequestNotStarted,
            $transitionedAt,
        ): bool {
            $query = $this->activeClaimQuery(
                $outboxId,
                $claimToken,
                $claimVersion,
                $fromStatuses,
                $transitionedAt,
            );

            if ($requireRequestNotStarted) {
                $query->whereNull('request_started_at');
            }

            $updated = $query->update([
                ...$attributes,
                'updated_at' => $transitionedAt,
            ]);

            if ($updated !== 1) {
                return false;
            }

            $outbox = SocialDeliveryOutbox::query()->findOrFail($outboxId);
            if ($afterTransition !== null) {
                $afterTransition($outbox);
            }

            return true;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * @param  array<int, string>  $statuses
     */
    private function activeClaimQuery(
        int $outboxId,
        string $claimToken,
        int $claimVersion,
        array $statuses,
        DateTimeInterface $at,
    ): Builder {
        return SocialDeliveryOutbox::query()
            ->whereKey($outboxId)
            ->whereIn('status', $statuses)
            ->where('claim_token', $claimToken)
            ->where('claim_version', $claimVersion)
            ->whereNotNull('claim_expires_at')
            ->where('claim_expires_at', '>', $at);
    }

    private function assertClaimIdentity(int $outboxId, string $claimToken, int $claimVersion): void
    {
        $this->assertPositiveId($outboxId, 'outbox');

        if (preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i',
            $claimToken,
        ) !== 1 || $claimVersion < 1) {
            throw new InvalidArgumentException('The Pulse delivery outbox claim identity is invalid.');
        }
    }

    private function assertTenantRevisionAndTransport(
        int $tenantId,
        SocialPost $post,
        SocialPostTarget $target,
        SocialPostRevision $revision,
        SocialAccountConnection $connection,
    ): void {
        if ((int) $post->user_id !== $tenantId
            || (int) $target->social_post_id !== (int) $post->getKey()
            || (int) $connection->user_id !== $tenantId
            || (int) $target->social_account_connection_id !== (int) $connection->getKey()
            || (int) $revision->user_id !== $tenantId
            || (int) $revision->social_post_id !== (int) $post->getKey()) {
            throw new LogicException('The Pulse delivery outbox identity crosses a workspace boundary.');
        }

        if ($revision->approved_at === null
            || $revision->approval_provenance === null
            || (int) $post->approved_revision_id !== (int) $revision->getKey()
            || (int) $post->current_editorial_revision !== (int) $revision->revision_number
            || ! hash_equals((string) $revision->payload_hash, (string) $post->payload_hash)
            || (int) $target->current_revision_id !== (int) $revision->getKey()
            || (int) $target->last_submitted_revision_id !== (int) $revision->getKey()
            || (int) $target->current_editorial_revision !== (int) $revision->revision_number
            || ! hash_equals((string) $revision->payload_hash, (string) $target->payload_hash)
            || ! hash_equals(
                (string) $revision->payload_hash,
                $this->revisionSnapshots->hashForRevision($revision),
            )) {
            throw new LogicException('The Pulse delivery outbox revision is not the approved submitted snapshot.');
        }

        $targetLogicalKey = (string) $target->logical_destination_key;

        if (trim((string) $target->delivery_provider) === ''
            || trim((string) $target->transport_generation) === ''
            || preg_match('/\Aldk:v1:[0-9a-f]{64}\z/', $targetLogicalKey) !== 1
            || (string) $target->delivery_provider !== (string) $connection->delivery_provider
            || (string) $target->transport_generation !== (string) $connection->transport_generation
            || ! hash_equals((string) $connection->logical_destination_key, $targetLogicalKey)) {
            throw new LogicException('The Pulse delivery outbox transport snapshot is inconsistent.');
        }
    }

    private function assertRecovery(
        int $tenantId,
        SocialPostTarget $target,
        SocialPostRevision $revision,
        string $operation,
        int $recoveryGeneration,
        ?SocialDeliveryOutbox $supersedes,
    ): void {
        if ($recoveryGeneration < 0 || $recoveryGeneration > 65_535) {
            throw new InvalidArgumentException('The Pulse delivery recovery generation is invalid.');
        }

        if ($recoveryGeneration > 0 && $supersedes === null) {
            throw new LogicException('A Pulse delivery recovery must reference the superseded outbox entry.');
        }

        if ($supersedes === null) {
            return;
        }

        $supersedes = SocialDeliveryOutbox::query()->findOrFail($supersedes->getKey());

        $sameRevisionRecovery = (int) $supersedes->social_post_revision_id === (int) $revision->getKey()
            && $recoveryGeneration === (int) $supersedes->recovery_generation + 1;
        $newRevisionRecovery = (int) $supersedes->social_post_revision_id !== (int) $revision->getKey()
            && $recoveryGeneration === 0
            && (int) $revision->revision_number > (int) $supersedes->editorial_revision;
        $unresolvedDeadRecovery = (string) $supersedes->status
                === SocialDeliveryOutbox::STATUS_DEAD
            && $supersedes->reconciliation_resolution === null
            && $supersedes->reconciliation_resolved_at === null
            && $supersedes->reconciliation_observed_at === null
            && $supersedes->reconciliation_resolution_source === null;
        $statusCanBeRecovered = $unresolvedDeadRecovery;
        $successors = SocialDeliveryOutbox::query()
            ->where('supersedes_outbox_id', $supersedes->getKey())
            ->get([
                'user_id',
                'social_post_target_id',
                'social_post_revision_id',
                'operation',
                'recovery_generation',
            ]);
        $successorsAreIdempotent = $successors->every(
            fn (SocialDeliveryOutbox $successor): bool => (int) $successor->user_id === $tenantId
                && (int) $successor->social_post_target_id === (int) $target->getKey()
                && (int) $successor->social_post_revision_id === (int) $revision->getKey()
                && (string) $successor->operation === $operation
                && (int) $successor->recovery_generation === $recoveryGeneration,
        );

        if ((int) $supersedes->user_id !== $tenantId
            || (int) $supersedes->social_post_target_id !== (int) $target->getKey()
            || (string) $supersedes->operation !== $operation
            || (int) $supersedes->social_provider_connection_id
                !== (int) $target->social_account_connection_id
            || (string) $supersedes->delivery_provider !== (string) $target->delivery_provider
            || (string) $supersedes->transport_generation !== (string) $target->transport_generation
            || ! hash_equals(
                (string) $supersedes->logical_destination_key,
                (string) $target->logical_destination_key,
            )
            || ! $statusCanBeRecovered
            || (! $sameRevisionRecovery && ! $newRevisionRecovery)
            || ! $successorsAreIdempotent) {
            throw new LogicException('The superseded Pulse delivery outbox entry is invalid.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function canonicalPayload(
        array $payload,
        SocialPost $post,
        SocialPostTarget $target,
        SocialPostRevision $revision,
        SocialAccountConnection $connection,
    ): array {
        if ($payload === []
            || (int) ($payload['post_id'] ?? 0) !== (int) $post->getKey()
            || (int) ($payload['target_id'] ?? 0) !== (int) $target->getKey()
            || trim((string) ($payload['platform'] ?? '')) !== (string) $connection->platform
            || (isset($payload['revision_id'])
                && (int) $payload['revision_id'] !== (int) $revision->getKey())) {
            throw new InvalidArgumentException('The Pulse delivery payload does not match its target snapshot.');
        }

        $this->assertPayloadHasNoSecrets($payload);
        $canonicalPayload = $this->canonicalize($payload);
        $encoded = $this->encodeCanonical($canonicalPayload);

        if (strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('The Pulse delivery payload exceeds the allowed size.');
        }

        return $canonicalPayload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertPayloadHasNoSecrets(array $payload): void
    {
        foreach ($payload as $key => $value) {
            $normalizedKey = Str::snake((string) $key);

            if (Str::contains($normalizedKey, [
                'authorization',
                'cookie',
                'credential',
                'oauth_code',
                'password',
                'private_key',
                'api_key',
                'code_verifier',
                'signature',
                'token',
                'secret',
                'x_amz_',
            ])) {
                throw new InvalidArgumentException('The Pulse delivery payload contains a forbidden secret field.');
            }

            if (is_array($value)) {
                $this->assertPayloadHasNoSecrets($value);
            }
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            if (! is_null($value) && ! is_bool($value) && ! is_int($value)
                && ! is_float($value) && ! is_string($value)) {
                throw new InvalidArgumentException('The Pulse delivery payload contains an unsupported value.');
            }

            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function encodeCanonical(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The Pulse delivery payload cannot be encoded.', previous: $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function assertIdempotentMatch(
        SocialDeliveryOutbox $outbox,
        array $expected,
        string $idempotencyKey,
    ): void {
        $matches = hash_equals((string) $outbox->idempotency_key, $idempotencyKey)
            && (int) $outbox->user_id === (int) $expected['user_id']
            && (int) $outbox->social_post_target_id === (int) $expected['social_post_target_id']
            && (int) $outbox->social_post_revision_id === (int) $expected['social_post_revision_id']
            && (int) $outbox->social_provider_connection_id === (int) $expected['social_provider_connection_id']
            && (string) $outbox->operation === (string) $expected['operation']
            && (string) $outbox->delivery_provider === (string) $expected['delivery_provider']
            && (string) $outbox->transport_generation === (string) $expected['transport_generation']
            && hash_equals(
                (string) $outbox->logical_destination_key,
                (string) $expected['logical_destination_key'],
            )
            && $outbox->external_organization_id_snapshot === $expected['external_organization_id_snapshot']
            && $outbox->external_channel_id_snapshot === $expected['external_channel_id_snapshot']
            && (int) $outbox->editorial_revision === (int) $expected['editorial_revision']
            && (int) $outbox->recovery_generation === (int) $expected['recovery_generation']
            && $outbox->supersedes_outbox_id === $expected['supersedes_outbox_id']
            && hash_equals((string) $outbox->payload_hash, (string) $expected['payload_hash'])
            && hash_equals(
                $this->encodeCanonical($this->canonicalize((array) $outbox->payload)),
                $this->encodeCanonical($expected['payload']),
            )
            && $outbox->available_at?->equalTo($expected['available_at']) === true;

        if (! $matches) {
            throw new LogicException('The existing Pulse delivery outbox entry conflicts with its idempotency key.');
        }
    }

    private function idempotencyKey(
        int $tenantId,
        SocialPostTarget $target,
        SocialPostRevision $revision,
        string $operation,
        int $recoveryGeneration,
    ): string {
        return hash('sha256', $this->encodeCanonical([
            'contract' => 'pulse-delivery-outbox:v1',
            'editorial_revision' => (int) $revision->revision_number,
            'operation' => $operation,
            'recovery_generation' => $recoveryGeneration,
            'social_post_revision_id' => (int) $revision->getKey(),
            'social_post_target_id' => (int) $target->getKey(),
            'tenant_id' => $tenantId,
        ]));
    }

    /**
     * @return array{last_error_category:string,last_error_code:string|null,last_error_message:string|null}
     */
    private function errorAttributes(
        string $errorCategory,
        ?string $errorCode,
        ?string $errorMessage,
    ): array {
        $errorCategory = Str::lower(trim($errorCategory));

        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $errorCategory) !== 1) {
            throw new InvalidArgumentException('The Pulse delivery error category is invalid.');
        }

        $errorCode = $errorCode === null ? null : trim($errorCode);
        if ($errorCode === '') {
            $errorCode = null;
        }

        if ($errorCode !== null && Str::length($errorCode) > 191) {
            $errorCode = Str::limit($errorCode, 191, '');
        }

        return [
            'last_error_category' => $errorCategory,
            'last_error_code' => $errorCode,
            'last_error_message' => $this->messageSanitizer->sanitize($errorMessage),
        ];
    }

    private function normalizeProviderIdentifier(?string $identifier, string $kind): ?string
    {
        if ($identifier === null) {
            return null;
        }

        $identifier = trim($identifier);

        if ($identifier === '' || Str::length($identifier) > 191) {
            throw new InvalidArgumentException("The Pulse provider {$kind} identifier is invalid.");
        }

        return $identifier;
    }

    private function normalizeClaimedBy(string $claimedBy): string
    {
        $claimedBy = trim($claimedBy);

        if ($claimedBy === '' || Str::length($claimedBy) > 191) {
            throw new InvalidArgumentException('The Pulse delivery outbox worker identity is invalid.');
        }

        return $claimedBy;
    }

    private function normalizeBatchSize(int $batchSize): int
    {
        if ($batchSize < 1 || $batchSize > self::MAX_BATCH_SIZE) {
            throw new InvalidArgumentException('The Pulse delivery outbox batch size is invalid.');
        }

        return $batchSize;
    }

    private function assertPositiveId(int $id, string $kind): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException("The Pulse delivery {$kind} identifier is invalid.");
        }
    }

    private function assertOperation(string $operation): void
    {
        if ($operation !== SocialDeliveryOutbox::OPERATION_CREATE) {
            throw new InvalidArgumentException(
                'The current Pulse delivery runtime accepts create operations only.',
            );
        }
    }
}
