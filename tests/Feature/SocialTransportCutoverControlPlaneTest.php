<?php

use App\Jobs\ProcessSocialDeliveryOutboxJob;
use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\SocialDeliveryOutbox;
use App\Models\SocialPost;
use App\Models\SocialPostRevision;
use App\Models\SocialPostTarget;
use App\Models\SocialTransportCutover;
use App\Models\SocialTransportCutoverEvent;
use App\Models\SocialTransportCutoverMapping;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\Social\Contracts\PlatformPublisherInterface;
use App\Services\Social\SocialAccountConnectionService;
use App\Services\Social\SocialConnectionDeliveryMutex;
use App\Services\Social\SocialDeliveryOutboxService;
use App\Services\Social\SocialPostRevisionService;
use App\Services\Social\SocialProviderRegistry;
use App\Services\Social\SocialPublishingService;
use App\Services\Social\SocialTransportCutoverService;
use App\Services\Social\SocialTransportMappingManifest;
use App\Services\Social\SocialTransportPolicyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

final class PulseStep3InterleavingPublisher implements PlatformPublisherInterface
{
    public int $publishCalls = 0;

    public ?string $transitionError = null;

    public ?string $stateDuringPublish = null;

    public ?int $eventCountDuringPublish = null;

    public function __construct(
        private readonly User $tenant,
        private readonly string $holdEvidenceHash,
    ) {}

    public function key(): string
    {
        return SocialAccountConnection::PLATFORM_FACEBOOK;
    }

    public function label(): string
    {
        return 'Step 3 interleaving Facebook publisher';
    }

    public function definition(): array
    {
        return ['key' => $this->key(), 'label' => $this->label()];
    }

    public function beginAuthorization(SocialAccountConnection $connection, string $state): array
    {
        return ['redirect_url' => 'https://example.test/pulse-step-3'];
    }

    public function completeAuthorization(SocialAccountConnection $connection, array $payload): array
    {
        return [];
    }

    public function refreshCredentials(array $credentials): array
    {
        return $credentials;
    }

    public function publish(SocialAccountConnection $connection, array $payload): array
    {
        $this->publishCalls++;

        try {
            app(SocialTransportCutoverService::class)->placeOnRollbackHold(
                $this->tenant,
                $this->tenant,
                $this->holdEvidenceHash,
            );
        } catch (LogicException $exception) {
            $this->transitionError = $exception->getMessage();
        }

        $this->stateDuringPublish = SocialTransportCutover::query()
            ->where('user_id', $this->tenant->getKey())
            ->value('state');
        $this->eventCountDuringPublish = SocialTransportCutoverEvent::query()
            ->where('user_id', $this->tenant->getKey())
            ->count();

        return [
            'provider_post_id' => 'step3-interleaving-'.$connection->getKey(),
            'published_at' => now()->toIso8601String(),
            'metadata' => ['transport' => 'step3-test'],
        ];
    }
}

final class PulseStep3InterleavingRegistry extends SocialProviderRegistry
{
    public function __construct(
        private readonly PlatformPublisherInterface $facebook,
    ) {}

    public function definitions(): array
    {
        return [$this->facebook->definition()];
    }

    public function publisher(string $platform): PlatformPublisherInterface
    {
        if ($platform !== SocialAccountConnection::PLATFORM_FACEBOOK) {
            throw new InvalidArgumentException('Unsupported Step 3 test platform.');
        }

        return $this->facebook;
    }
}

final class PulseStep3ReleaseInterleavingPolicy extends SocialTransportPolicyService
{
    public ?string $releaseError = null;

    private bool $attemptedRelease = false;

    public function __construct(
        private readonly User $tenant,
        private readonly string $releaseEvidenceHash,
    ) {}

    public function allowsExistingRemoteEffect(
        int $tenantId,
        string $transportGeneration,
        ?int $connectionId = null,
        ?string $logicalDestinationKey = null,
    ): bool {
        if (! $this->attemptedRelease) {
            $this->attemptedRelease = true;

            try {
                app(SocialTransportCutoverService::class)->resumeLegacyAfterRollbackHold(
                    $this->tenant,
                    $this->tenant,
                    $this->releaseEvidenceHash,
                );
            } catch (LogicException $exception) {
                $this->releaseError = $exception->getMessage();
            }
        }

        return parent::allowsExistingRemoteEffect(
            $tenantId,
            $transportGeneration,
            $connectionId,
            $logicalDestinationKey,
        );
    }
}

function pulseStep3DirectConnection(User $owner, string $suffix = 'primary'): SocialAccountConnection
{
    $externalAccountId = 'step3-direct-'.$suffix.'-'.$owner->id;

    return SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Step 3 direct Facebook page',
        'external_account_id' => $externalAccountId,
        ...pulseDirectTransportIdentity(
            $owner,
            SocialAccountConnection::PLATFORM_FACEBOOK,
            $externalAccountId,
        ),
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
    ]);
}

function pulseStep3ReplacementConnection(
    User $owner,
    SocialAccountConnection $legacy,
    string $suffix = 'primary',
): SocialAccountConnection {
    return SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Step 3 replacement Facebook page',
        'external_account_id' => 'step3-replacement-'.$suffix.'-'.$owner->id,
        'delivery_provider' => SocialAccountConnection::DELIVERY_PROVIDER_BUFFER,
        'transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
        'logical_destination_key' => $legacy->logical_destination_key,
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
        'metadata' => [
            'buffer' => [
                'account_id' => 'step3-account-'.$owner->id,
                'organization_id' => 'step3-organization-'.$owner->id,
                'catalog_only' => false,
                'publication_enabled' => true,
            ],
        ],
    ]);
}

function pulseStep3ValidateShadow(SocialTransportCutoverMapping $mapping, string $evidence): void
{
    DB::table('social_transport_cutover_mappings')
        ->where('id', $mapping->getKey())
        ->update([
            'owner_validated_at' => now()->subDays(12),
            'shadow_validated_at' => now()->subDays(12),
            'shadow_evidence_hash' => hash('sha256', $evidence),
        ]);
}

function pulseStep3DeliveryApprover(): User
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'superadmin'],
        ['description' => 'Superadmin'],
    );

    return User::factory()->create(['role_id' => $role->id]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function pulseStep3ActivateCanary(
    User $h2Approver,
    SocialTransportCutover $cutover,
    array $overrides = [],
): SocialTransportCutover {
    $h2ApprovedAt = now()->subMinutes(3);
    $cutoverAt = now()->subMinutes(2);
    $canaryStartedAt = now()->subMinute();

    DB::table('social_transport_cutovers')
        ->where('id', $cutover->getKey())
        ->update(array_replace([
            'state' => SocialTransportCutover::STATE_CANARY_ACTIVE,
            'active_transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
            'pilot_status' => SocialTransportCutover::PILOT_ACTIVE,
            'rollback_status' => SocialTransportCutover::ROLLBACK_AVAILABLE,
            'h2_approved_by_user_id' => $h2Approver->getKey(),
            'h2_approval_authority' => SocialTransportCutover::APPROVAL_AUTHORITY_SUPERADMIN,
            'h2_approved_at' => $h2ApprovedAt,
            'h2_evidence_hash' => hash('sha256', 'step3 H2 evidence'),
            'canary_contract_hash' => hash('sha256', 'step3 canary contract'),
            'mapping_manifest_hash' => SocialTransportMappingManifest::hashFor($cutover),
            'canary_minimum_deliveries' => SocialTransportCutover::CANARY_MINIMUM_DELIVERIES,
            'canary_minimum_hours' => SocialTransportCutover::CANARY_MINIMUM_HOURS,
            'canary_maximum_unknown' => SocialTransportCutover::CANARY_MAXIMUM_UNKNOWN,
            'rollback_rto_seconds' => SocialTransportCutover::ROLLBACK_MAXIMUM_RTO_SECONDS,
            'cutover_at' => $cutoverAt,
            'canary_started_at' => $canaryStartedAt,
        ], $overrides));

    return $cutover->fresh();
}

/**
 * @param  array<string, mixed>  $overrides
 */
function pulseStep3CompleteCutover(
    User $h2Approver,
    User $h3Actor,
    SocialTransportCutover $cutover,
    array $overrides = [],
): SocialTransportCutover {
    DB::table('social_transport_cutovers')
        ->where('id', $cutover->getKey())
        ->update(array_replace([
            'state' => SocialTransportCutover::STATE_CUTOVER_COMPLETE,
            'active_transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
            'pilot_status' => SocialTransportCutover::PILOT_PASSED,
            'legacy_drain_status' => SocialTransportCutover::DRAIN_COMPLETE,
            'rollback_status' => SocialTransportCutover::ROLLBACK_FORBIDDEN,
            'h2_approved_by_user_id' => $h2Approver->getKey(),
            'h2_approval_authority' => SocialTransportCutover::APPROVAL_AUTHORITY_SUPERADMIN,
            'h2_approved_at' => now()->subDays(10),
            'h2_evidence_hash' => hash('sha256', 'step3 complete H2 evidence'),
            'canary_contract_hash' => hash('sha256', 'step3 complete canary contract'),
            'mapping_manifest_hash' => SocialTransportMappingManifest::hashFor($cutover),
            'canary_minimum_deliveries' => SocialTransportCutover::CANARY_MINIMUM_DELIVERIES,
            'canary_minimum_hours' => SocialTransportCutover::CANARY_MINIMUM_HOURS,
            'canary_maximum_unknown' => SocialTransportCutover::CANARY_MAXIMUM_UNKNOWN,
            'rollback_rto_seconds' => SocialTransportCutover::ROLLBACK_MAXIMUM_RTO_SECONDS,
            'cutover_at' => now()->subDays(9),
            'canary_started_at' => now()->subDays(8),
            'canary_completed_at' => now()->subDay(),
            'canary_evidence_hash' => hash('sha256', 'step3 canary observations'),
            'canary_observed_deliveries' => SocialTransportCutover::CANARY_MINIMUM_DELIVERIES,
            'canary_observed_unknown' => SocialTransportCutover::CANARY_MAXIMUM_UNKNOWN,
            'canary_observed_rollback_rto_seconds' => SocialTransportCutover::ROLLBACK_MAXIMUM_RTO_SECONDS,
            'direct_writer_barrier_at' => now()->subHours(20),
            'legacy_drain_observation_started_at' => now()->subHours(18),
            'legacy_drain_completed_at' => now()->subHours(12),
            'legacy_drain_evidence_hash' => hash('sha256', 'step3 legacy drain evidence'),
            'h3_approved_by_user_id' => $h3Actor->getKey(),
            'h3_approval_authority' => SocialTransportCutover::APPROVAL_AUTHORITY_SUPERADMIN,
            'h3_evidence_hash' => hash('sha256', 'step3 H3 evidence'),
            'h3_go_general_at' => now()->subHours(10),
            'h3_direct_removal_authorized_at' => now()->subHours(9),
            'rollback_window_ends_at' => now()->subHours(8),
            'direct_retired_at' => now()->subHours(7),
        ], $overrides));

    return $cutover->fresh();
}

/**
 * @return array{post:SocialPost,target:SocialPostTarget,revision:SocialPostRevision,outbox:SocialDeliveryOutbox}
 */
function pulseStep3QueuedDelivery(
    User $owner,
    SocialAccountConnection $connection,
): array {
    $post = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'content_payload' => ['text' => 'Approved Step 3 delivery'],
        'media_payload' => [],
        'status' => SocialPost::STATUS_DRAFT,
    ]);
    $target = SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'social_account_connection_id' => $connection->id,
        'delivery_provider' => $connection->delivery_provider,
        'transport_generation' => $connection->transport_generation,
        'logical_destination_key' => $connection->logical_destination_key,
        'status' => SocialPostTarget::STATUS_PENDING,
    ]);
    $revision = app(SocialPostRevisionService::class)->approveDirectly($post, $owner, now());
    $target->refresh()->forceFill([
        'last_submitted_revision_id' => $revision->id,
        'delivery_status' => SocialPost::DELIVERY_STATUS_QUEUED,
        'sync_status' => SocialPost::SYNC_STATUS_PENDING,
        'status' => SocialPostTarget::STATUS_PENDING,
    ])->save();
    $target = $target->fresh();
    $outbox = DB::transaction(fn (): SocialDeliveryOutbox => app(SocialDeliveryOutboxService::class)
        ->createForTarget(
            $owner,
            $target,
            $revision,
            $connection,
            [
                'post_id' => $post->id,
                'target_id' => $target->id,
                'revision_id' => $revision->id,
                'platform' => $connection->platform,
                'text' => 'Approved Step 3 delivery',
            ],
            now(),
        ));

    return [
        'post' => $post->fresh(),
        'target' => $target,
        'revision' => $revision,
        'outbox' => $outbox,
    ];
}

it('initializes an audited tenant registry and enters rollback hold idempotently without fallback', function () {
    $owner = User::factory()->create();
    $foreignActor = User::factory()->create();
    $membership = TeamMember::factory()->create(['account_id' => $owner->id]);
    $workspaceMember = $membership->user()->firstOrFail();
    $service = app(SocialTransportCutoverService::class);
    $evidence = hash('sha256', 'step3-initialization-evidence');

    expect(fn () => $service->initialize($owner, $workspaceMember, $evidence))
        ->toThrow(LogicException::class, 'workspace owner or a superadmin');
    app(AccountDeletionService::class)->deleteUser($workspaceMember);
    expect(User::query()->whereKey($workspaceMember->id)->exists())->toBeFalse();

    $initialized = $service->initialize($owner, $owner, $evidence);
    $replayed = $service->initialize($owner, $owner, $evidence);

    expect($initialized->state)->toBe(SocialTransportCutover::STATE_LEGACY_ONLY)
        ->and($initialized->active_transport_generation)
        ->toBe(SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1)
        ->and($replayed->id)->toBe($initialized->id)
        ->and(SocialTransportCutoverEvent::query()->where('user_id', $owner->id)->count())->toBe(1);

    expect(fn () => $service->placeOnRollbackHold($owner, $foreignActor, $evidence))
        ->toThrow(LogicException::class, 'workspace owner or a superadmin');

    $held = $service->placeOnRollbackHold(
        $owner,
        $owner,
        hash('sha256', 'step3-rollback-evidence'),
    );
    $replayedHold = $service->placeOnRollbackHold(
        $owner,
        $owner,
        hash('sha256', 'step3-rollback-evidence'),
    );

    expect($held->state)->toBe(SocialTransportCutover::STATE_ROLLBACK_HOLD)
        ->and($held->active_transport_generation)
        ->toBe(SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1)
        ->and($held->rollback_status)->toBe(SocialTransportCutover::ROLLBACK_REQUESTED)
        ->and($replayedHold->id)->toBe($held->id)
        ->and(SocialTransportCutoverEvent::query()->where('user_id', $owner->id)->count())->toBe(2)
        ->and(app(SocialTransportPolicyService::class)->allowsNewSubmission(
            $owner->id,
            SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
        ))->toBeFalse()
        ->and(app(SocialTransportPolicyService::class)->allowsExistingRemoteEffect(
            $owner->id,
            SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
        ))->toBeFalse();

    expect(fn () => $service->placeOnRollbackHold(
        $owner,
        $owner,
        hash('sha256', 'different-idempotent-retry-evidence'),
    ))->toThrow(LogicException::class, 'replay evidence does not match');
});

it('records only an exact owner validated Facebook replacement mapping and leaves shadow proof closed', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $membership = TeamMember::factory()->create(['account_id' => $owner->id]);
    $workspaceMember = $membership->user()->firstOrFail();
    $legacy = pulseStep3DirectConnection($owner);
    $replacement = pulseStep3ReplacementConnection($owner, $legacy);
    $foreignReplacement = pulseStep3ReplacementConnection(
        $otherOwner,
        pulseStep3DirectConnection($otherOwner),
    );
    $service = app(SocialTransportCutoverService::class);
    $ownerEvidence = hash('sha256', 'owner mapping evidence');

    expect(fn () => $service->recordOwnerValidatedMapping(
        $owner,
        $otherOwner,
        $legacy,
        $replacement,
        $ownerEvidence,
    ))->toThrow(LogicException::class, 'workspace owner or a superadmin');

    expect(fn () => $service->recordOwnerValidatedMapping(
        $owner,
        $workspaceMember,
        $legacy,
        $replacement,
        $ownerEvidence,
    ))->toThrow(LogicException::class, 'workspace owner or a superadmin');

    expect(fn () => $service->recordOwnerValidatedMapping(
        $owner,
        $owner,
        $legacy,
        $foreignReplacement,
        $ownerEvidence,
    ))->toThrow(LogicException::class, 'exact replacement');

    $mapping = $service->recordOwnerValidatedMapping(
        $owner,
        $owner,
        $legacy,
        $replacement,
        $ownerEvidence,
    );
    $replayed = $service->recordOwnerValidatedMapping(
        $owner,
        $owner,
        $legacy,
        $replacement,
        $ownerEvidence,
    );
    expect($mapping->user_id)->toBe($owner->id)
        ->and($mapping->logical_destination_key)->toBe($legacy->logical_destination_key)
        ->and($replayed->id)->toBe($mapping->id)
        ->and($mapping->shadow_validated_at)->toBeNull()
        ->and($mapping->shadow_evidence_hash)->toBeNull()
        ->and(SocialTransportCutoverEvent::query()->where('user_id', $owner->id)->count())->toBe(2);

    expect(function () use ($mapping): void {
        $mapping->forceFill(['logical_destination_key' => 'ldk:v1:'.str_repeat('f', 64)])->save();
    })->toThrow(LogicException::class, 'mapping is immutable');

    $secondLegacy = pulseStep3DirectConnection($owner, 'partial-h2');
    $secondReplacement = pulseStep3ReplacementConnection($owner, $secondLegacy, 'partial-h2');
    DB::table('social_transport_cutovers')
        ->where('user_id', $owner->id)
        ->update(['h2_evidence_hash' => hash('sha256', 'partial H2 must freeze mappings')]);

    expect(fn () => $service->recordOwnerValidatedMapping(
        $owner,
        $owner,
        $secondLegacy,
        $secondReplacement,
        hash('sha256', 'mapping after partial H2'),
    ))->toThrow(LogicException::class, 'frozen before H2');

    expect(fn () => $mapping->delete())
        ->toThrow(LogicException::class, 'mappings cannot be deleted');

    expect(fn () => app(SocialAccountConnectionService::class)->destroy($owner, $legacy))
        ->toThrow(ValidationException::class, 'audited transport mapping');
});

it('keeps the real superadmin operator in the immutable mapping audit event', function () {
    $owner = User::factory()->create();
    $superadminRole = Role::query()->firstOrCreate(
        ['name' => 'superadmin'],
        ['description' => 'Superadmin'],
    );
    $superadmin = User::factory()->create(['role_id' => $superadminRole->id]);
    $legacy = pulseStep3DirectConnection($owner, 'superadmin-audit');
    $replacement = pulseStep3ReplacementConnection($owner, $legacy, 'superadmin-audit');

    $mapping = app(SocialTransportCutoverService::class)->recordOwnerValidatedMapping(
        $owner,
        $superadmin,
        $legacy,
        $replacement,
        hash('sha256', 'superadmin owner evidence audit'),
    );
    $event = SocialTransportCutoverEvent::query()
        ->where('user_id', $owner->id)
        ->where('reason', 'owner_mapping_validated')
        ->sole();

    expect($mapping->owner_validated_by_user_id)->toBe($owner->id)
        ->and($event->actor_user_id)->toBe($superadmin->id);
});

it('suspends a claimed outbox before any remote request while rollback hold is active', function () {
    Http::preventStrayRequests();
    $owner = User::factory()->create();
    $connection = pulseStep3DirectConnection($owner, 'outbox');
    $delivery = pulseStep3QueuedDelivery($owner, $connection);
    app(SocialTransportCutoverService::class)->placeOnRollbackHold(
        $owner,
        $owner,
        hash('sha256', 'hold before remote request'),
    );
    DB::table('social_account_connections')->where('id', $connection->id)->update([
        'status' => SocialAccountConnection::STATUS_DISCONNECTED,
        'is_active' => false,
    ]);

    app(SocialPublishingService::class)->handleOutboxPublication($delivery['outbox']->id);

    $outbox = $delivery['outbox']->fresh();
    expect($outbox->status)->toBe(SocialDeliveryOutbox::STATUS_SUSPENDED)
        ->and($outbox->attempts)->toBe(0)
        ->and($outbox->request_started_at)->toBeNull()
        ->and($outbox->processed_at)->toBeNull()
        ->and($outbox->provider_post_id)->toBeNull()
        ->and($delivery['target']->fresh()->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_QUEUED);

    DB::table('social_delivery_outbox')->where('id', $outbox->id)->update([
        'status' => SocialDeliveryOutbox::STATUS_UNKNOWN,
        'request_started_at' => now(),
        'reconciliation_resolved_at' => null,
    ]);
    expect(fn () => app(SocialTransportCutoverService::class)->resumeLegacyAfterRollbackHold(
        $owner,
        $owner,
        hash('sha256', 'release blocked by ambiguity'),
    ))->toThrow(LogicException::class, 'must be reconciled')
        ->and(SocialTransportCutover::query()->where('user_id', $owner->id)->value('state'))
        ->toBe(SocialTransportCutover::STATE_ROLLBACK_HOLD);
    DB::table('social_delivery_outbox')->where('id', $outbox->id)->update([
        'status' => SocialDeliveryOutbox::STATUS_SUSPENDED,
        'request_started_at' => null,
    ]);

    $releaseEvidence = hash('sha256', 'explicit legacy rollback release');
    $released = app(SocialTransportCutoverService::class)->resumeLegacyAfterRollbackHold(
        $owner,
        $owner,
        $releaseEvidence,
    );
    $replayed = app(SocialTransportCutoverService::class)->resumeLegacyAfterRollbackHold(
        $owner,
        $owner,
        $releaseEvidence,
    );
    $outbox->refresh();

    expect($released->state)->toBe(SocialTransportCutover::STATE_LEGACY_ONLY)
        ->and($replayed->id)->toBe($released->id)
        ->and($outbox->status)->toBe(SocialDeliveryOutbox::STATUS_PENDING)
        ->and($outbox->request_started_at)->toBeNull()
        ->and($outbox->attempts)->toBe(0)
        ->and($outbox->last_error_code)->toBeNull()
        ->and(SocialTransportCutoverEvent::query()->where('user_id', $owner->id)->count())
        ->toBe(3);

    $replacement = pulseStep3ReplacementConnection($owner, $connection, 'release-replay');
    app(SocialTransportCutoverService::class)->recordOwnerValidatedMapping(
        $owner,
        $owner,
        $connection,
        $replacement,
        hash('sha256', 'mapping after rollback release'),
    );
    DB::table('social_delivery_outbox')->where('id', $outbox->id)->update([
        'status' => SocialDeliveryOutbox::STATUS_SUSPENDED,
        'request_started_at' => null,
        'processed_at' => null,
        'provider_post_id' => null,
        'last_error_category' => 'control_plane',
        'last_error_code' => 'transport_transition_hold',
        'last_error_message' => 'Pulse delivery is suspended before any remote request.',
    ]);
    app(SocialTransportCutoverService::class)->resumeLegacyAfterRollbackHold(
        $owner,
        $owner,
        $releaseEvidence,
    );

    expect($outbox->fresh()->status)->toBe(SocialDeliveryOutbox::STATUS_PENDING)
        ->and(SocialTransportCutoverEvent::query()->where('user_id', $owner->id)->count())
        ->toBe(4);

    expect(fn () => app(SocialTransportCutoverService::class)->resumeLegacyAfterRollbackHold(
        $owner,
        $owner,
        hash('sha256', 'different release evidence'),
    ))->toThrow(LogicException::class, 'release replay evidence does not match');
});

it('never commits rollback hold while a delivery connection mutex is active', function () {
    $owner = User::factory()->create();
    $connection = pulseStep3DirectConnection($owner, 'rollback-fence');
    $service = app(SocialTransportCutoverService::class);
    $service->initialize($owner, $owner, hash('sha256', 'rollback fence initialization'));
    $eventCount = SocialTransportCutoverEvent::query()->where('user_id', $owner->id)->count();
    $connectionLock = app(SocialConnectionDeliveryMutex::class)->acquire((int) $connection->id);

    expect($connectionLock)->not->toBeNull();

    try {
        expect(fn () => $service->placeOnRollbackHold(
            $owner,
            $owner,
            hash('sha256', 'rollback fence hold'),
        ))->toThrow(LogicException::class, 'delivery is active');
    } finally {
        $connectionLock?->release();
    }

    expect(SocialTransportCutover::query()->where('user_id', $owner->id)->value('state'))
        ->toBe(SocialTransportCutover::STATE_LEGACY_ONLY)
        ->and(SocialTransportCutoverEvent::query()->where('user_id', $owner->id)->count())
        ->toBe($eventCount);

    expect($service->placeOnRollbackHold(
        $owner,
        $owner,
        hash('sha256', 'rollback fence hold'),
    )->state)->toBe(SocialTransportCutover::STATE_ROLLBACK_HOLD);
});

it('linearizes rollback hold against the real provider call boundary', function () {
    $owner = User::factory()->create();
    $connection = pulseStep3DirectConnection($owner, 'provider-interleaving');
    $delivery = pulseStep3QueuedDelivery($owner, $connection);
    $cutoverService = app(SocialTransportCutoverService::class);
    $cutoverService->initialize(
        $owner,
        $owner,
        hash('sha256', 'provider interleaving initialization'),
    );
    $eventCount = SocialTransportCutoverEvent::query()
        ->where('user_id', $owner->id)
        ->count();
    $holdEvidence = hash('sha256', 'provider interleaving hold');
    $publisher = new PulseStep3InterleavingPublisher($owner, $holdEvidence);
    app()->instance(
        SocialProviderRegistry::class,
        new PulseStep3InterleavingRegistry($publisher),
    );

    app(SocialPublishingService::class)->handleOutboxPublication($delivery['outbox']->id);

    expect($publisher->publishCalls)->toBe(1)
        ->and($publisher->transitionError)->toContain('delivery is active')
        ->and($publisher->stateDuringPublish)->toBe(SocialTransportCutover::STATE_LEGACY_ONLY)
        ->and($publisher->eventCountDuringPublish)->toBe($eventCount)
        ->and($delivery['outbox']->fresh()->status)->toBe(SocialDeliveryOutbox::STATUS_COMPLETED)
        ->and(SocialTransportCutoverEvent::query()->where('user_id', $owner->id)->count())
        ->toBe($eventCount);

    expect($cutoverService->placeOnRollbackHold(
        $owner,
        $owner,
        $holdEvidence,
    )->state)->toBe(SocialTransportCutover::STATE_ROLLBACK_HOLD)
        ->and(SocialTransportCutoverEvent::query()->where('user_id', $owner->id)->count())
        ->toBe($eventCount + 1);
});

it('linearizes rollback release with a worker deciding whether to suspend', function () {
    $owner = User::factory()->create();
    $connection = pulseStep3DirectConnection($owner, 'release-interleaving');
    $delivery = pulseStep3QueuedDelivery($owner, $connection);
    $cutoverService = app(SocialTransportCutoverService::class);
    $cutoverService->placeOnRollbackHold(
        $owner,
        $owner,
        hash('sha256', 'release interleaving hold'),
    );
    $releaseEvidence = hash('sha256', 'release interleaving evidence');
    $policy = new PulseStep3ReleaseInterleavingPolicy($owner, $releaseEvidence);
    app()->instance(SocialTransportPolicyService::class, $policy);

    app(SocialPublishingService::class)->handleOutboxPublication($delivery['outbox']->id);

    expect($policy->releaseError)->toContain('delivery is active')
        ->and($delivery['outbox']->fresh()->status)->toBe(SocialDeliveryOutbox::STATUS_SUSPENDED)
        ->and(SocialTransportCutover::query()->where('user_id', $owner->id)->value('state'))
        ->toBe(SocialTransportCutover::STATE_ROLLBACK_HOLD)
        ->and(SocialTransportCutoverEvent::query()->where('user_id', $owner->id)->count())
        ->toBe(2);

    $released = $cutoverService->resumeLegacyAfterRollbackHold(
        $owner,
        $owner,
        $releaseEvidence,
    );

    expect($released->state)->toBe(SocialTransportCutover::STATE_LEGACY_ONLY)
        ->and($delivery['outbox']->fresh()->status)->toBe(SocialDeliveryOutbox::STATUS_PENDING)
        ->and(SocialTransportCutoverEvent::query()->where('user_id', $owner->id)->count())
        ->toBe(3);
});

it('blocks a new publication atomically while keeping the editorial state untouched', function () {
    Queue::fake([ProcessSocialDeliveryOutboxJob::class]);
    $owner = User::factory()->create();
    $connection = pulseStep3DirectConnection($owner, 'new-submission');
    $post = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'content_payload' => ['text' => 'Must remain local'],
        'status' => SocialPost::STATUS_DRAFT,
    ]);
    SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'social_account_connection_id' => $connection->id,
        'delivery_provider' => $connection->delivery_provider,
        'transport_generation' => $connection->transport_generation,
        'logical_destination_key' => $connection->logical_destination_key,
        'status' => SocialPostTarget::STATUS_PENDING,
    ]);
    app(SocialTransportCutoverService::class)->placeOnRollbackHold(
        $owner,
        $owner,
        hash('sha256', 'hold new submissions'),
    );
    $revisionCount = SocialPostRevision::query()->where('social_post_id', $post->id)->count();

    expect(fn () => app(SocialPublishingService::class)->publishNow($owner, $owner, $post))
        ->toThrow(ValidationException::class, 'transport transition is reviewed');

    expect(SocialPostRevision::query()->where('social_post_id', $post->id)->count())
        ->toBe($revisionCount)
        ->and(SocialDeliveryOutbox::query()->where('user_id', $owner->id)->count())->toBe(0)
        ->and($post->fresh()->status)->toBe(SocialPost::STATUS_DRAFT);
    Queue::assertNothingPushed();
});

it('creates no publication artifacts while a tenant transport transition mutex is active', function () {
    Queue::fake([ProcessSocialDeliveryOutboxJob::class]);
    $owner = User::factory()->create();
    $connection = pulseStep3DirectConnection($owner, 'tenant-fence');
    $post = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'content_payload' => ['text' => 'Must wait for the transition'],
        'status' => SocialPost::STATUS_DRAFT,
    ]);
    SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'social_account_connection_id' => $connection->id,
        'delivery_provider' => $connection->delivery_provider,
        'transport_generation' => $connection->transport_generation,
        'logical_destination_key' => $connection->logical_destination_key,
        'status' => SocialPostTarget::STATUS_PENDING,
    ]);
    $tenantLock = app(SocialConnectionDeliveryMutex::class)->acquireTenant((int) $owner->id);

    expect($tenantLock)->not->toBeNull();

    try {
        expect(fn () => app(SocialPublishingService::class)->publishNow($owner, $owner, $post))
            ->toThrow(ValidationException::class, 'transport is changing');
    } finally {
        $tenantLock?->release();
    }

    expect(SocialPostRevision::query()->where('social_post_id', $post->id)->count())->toBe(0)
        ->and(SocialDeliveryOutbox::query()->where('user_id', $owner->id)->count())->toBe(0)
        ->and($post->fresh()->status)->toBe(SocialPost::STATUS_DRAFT);
    Queue::assertNothingPushed();
});

it('fails closed for internally inconsistent H2 H3 and active transport snapshots', function () {
    $owner = User::factory()->create();
    $cutover = app(SocialTransportCutoverService::class)->initialize(
        $owner,
        $owner,
        hash('sha256', 'coherent policy initialization'),
    );
    $policy = app(SocialTransportPolicyService::class);
    $assertBothTransportsBlocked = function () use ($owner, $policy): void {
        expect($policy->allowsNewSubmission(
            $owner->id,
            SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
        ))->toBeFalse()->and($policy->allowsNewSubmission(
            $owner->id,
            SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
        ))->toBeFalse()->and($policy->allowsExistingRemoteEffect(
            $owner->id,
            SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
        ))->toBeFalse()->and($policy->allowsExistingRemoteEffect(
            $owner->id,
            SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
        ))->toBeFalse();
    };

    DB::table('social_transport_cutovers')->where('id', $cutover->id)->update([
        'active_transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
    ]);
    $assertBothTransportsBlocked();

    DB::table('social_transport_cutovers')->where('id', $cutover->id)->update([
        'state' => SocialTransportCutover::STATE_CANARY_ACTIVE,
        'active_transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
        'pilot_status' => SocialTransportCutover::PILOT_ACTIVE,
        'canary_started_at' => now(),
        'h2_approved_by_user_id' => null,
        'h2_approved_at' => now(),
        'h2_evidence_hash' => hash('sha256', 'incomplete H2 evidence'),
        'canary_contract_hash' => hash('sha256', 'incomplete H2 contract'),
        'canary_minimum_deliveries' => 10,
        'canary_minimum_hours' => 168,
        'canary_maximum_unknown' => 0,
        'rollback_rto_seconds' => 300,
    ]);
    $assertBothTransportsBlocked();

    DB::table('social_transport_cutovers')->where('id', $cutover->id)->update([
        'state' => SocialTransportCutover::STATE_CUTOVER_COMPLETE,
        'pilot_status' => SocialTransportCutover::PILOT_PASSED,
        'legacy_drain_status' => SocialTransportCutover::DRAIN_COMPLETE,
        'h2_approved_by_user_id' => $owner->id,
        'canary_completed_at' => now(),
        'legacy_drain_completed_at' => now(),
        'h3_approved_by_user_id' => $owner->id,
        'h3_evidence_hash' => hash('sha256', 'incomplete H3 evidence'),
        'h3_go_general_at' => now(),
        'h3_direct_removal_authorized_at' => null,
        'direct_retired_at' => now(),
    ]);
    $assertBothTransportsBlocked();

    expect(function () use ($cutover): void {
        $cutover->forceFill([
            'last_evidence_hash' => hash('sha256', 'unaudited model mutation'),
        ])->save();
    })->toThrow(LogicException::class, 'audited control-plane service');
});

it('authorizes Buffer only for an exact shadow validated mapping with exhaustive legacy coverage', function () {
    $owner = User::factory()->create();
    $deliveryApprover = pulseStep3DeliveryApprover();
    $legacy = pulseStep3DirectConnection($owner, 'mapped-policy');
    $replacement = pulseStep3ReplacementConnection($owner, $legacy, 'mapped-policy');
    $service = app(SocialTransportCutoverService::class);
    $mapping = $service->recordOwnerValidatedMapping(
        $owner,
        $owner,
        $legacy,
        $replacement,
        hash('sha256', 'mapped policy owner evidence'),
    );
    pulseStep3ValidateShadow($mapping, 'mapped policy shadow evidence');
    $cutover = pulseStep3ActivateCanary(
        $deliveryApprover,
        SocialTransportCutover::query()->where('user_id', $owner->id)->sole(),
    );
    $policy = app(SocialTransportPolicyService::class);

    expect($cutover->hasCoherentState())->toBeTrue()
        ->and($policy->allowsNewSubmission(
            $owner->id,
            SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
            $replacement->id,
            $replacement->logical_destination_key,
        ))->toBeTrue()
        ->and($policy->allowsExistingRemoteEffect(
            $owner->id,
            SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
            $replacement->id,
            $replacement->logical_destination_key,
        ))->toBeTrue()
        ->and($policy->allowsNewSubmission(
            $owner->id,
            SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
        ))->toBeFalse();

    $held = $service->placeOnRollbackHold(
        $owner,
        $owner,
        hash('sha256', 'candidate rollback hold'),
    );

    expect($held->state)->toBe(SocialTransportCutover::STATE_ROLLBACK_HOLD)
        ->and($held->rollback_resume_state)->toBe(SocialTransportCutover::STATE_CANARY_ACTIVE)
        ->and($held->active_transport_generation)
        ->toBe(SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1)
        ->and($policy->allowsNewSubmission(
            $owner->id,
            SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
            $replacement->id,
            $replacement->logical_destination_key,
        ))->toBeFalse();

    $cutover = $service->resumeAfterRollbackHold(
        $owner,
        $owner,
        hash('sha256', 'candidate rollback release'),
    );

    expect($cutover->state)->toBe(SocialTransportCutover::STATE_CANARY_ACTIVE)
        ->and($cutover->rollback_resume_state)->toBeNull()
        ->and($policy->allowsNewSubmission(
            $owner->id,
            SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
            $replacement->id,
            $replacement->logical_destination_key,
        ))->toBeTrue();

    $unmappedLegacy = pulseStep3DirectConnection($owner, 'unmapped-policy');
    $unmappedReplacement = pulseStep3ReplacementConnection(
        $owner,
        $unmappedLegacy,
        'unmapped-policy',
    );
    expect(fn () => $service->recordOwnerValidatedMapping(
        $owner,
        $owner,
        $unmappedLegacy,
        $unmappedReplacement,
        hash('sha256', 'mapping attempted after H2'),
    ))->toThrow(LogicException::class, 'frozen before H2');
    DB::table('social_account_connections')
        ->where('id', $unmappedLegacy->id)
        ->update([
            'status' => SocialAccountConnection::STATUS_DISCONNECTED,
            'is_active' => false,
        ]);

    expect($policy->allowsNewSubmission(
        $owner->id,
        SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
        $unmappedReplacement->id,
        $unmappedReplacement->logical_destination_key,
    ))->toBeFalse()
        ->and($policy->allowsNewSubmission(
            $owner->id,
            SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
            $replacement->id,
            $replacement->logical_destination_key,
        ))->toBeTrue();

    DB::table('social_account_connections')
        ->where('id', $unmappedLegacy->id)
        ->update([
            'status' => SocialAccountConnection::STATUS_CONNECTED,
            'is_active' => true,
        ]);

    expect($policy->allowsNewSubmission(
        $owner->id,
        SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
        $replacement->id,
        $replacement->logical_destination_key,
    ))->toBeFalse();
});

it('resumes every existing-effect generation allowed by the restored candidate state', function () {
    $owner = User::factory()->create();
    $deliveryApprover = pulseStep3DeliveryApprover();
    $legacy = pulseStep3DirectConnection($owner, 'candidate-resume');
    $replacement = pulseStep3ReplacementConnection($owner, $legacy, 'candidate-resume');
    $service = app(SocialTransportCutoverService::class);
    $mapping = $service->recordOwnerValidatedMapping(
        $owner,
        $owner,
        $legacy,
        $replacement,
        hash('sha256', 'candidate resume owner evidence'),
    );
    pulseStep3ValidateShadow($mapping, 'candidate resume shadow evidence');
    pulseStep3ActivateCanary(
        $deliveryApprover,
        SocialTransportCutover::query()->where('user_id', $owner->id)->sole(),
    );
    $candidateDelivery = pulseStep3QueuedDelivery($owner, $replacement);
    $legacyDelivery = pulseStep3QueuedDelivery($owner, $legacy);

    DB::table('social_delivery_outbox')
        ->whereIn('id', [$candidateDelivery['outbox']->id, $legacyDelivery['outbox']->id])
        ->update([
            'status' => SocialDeliveryOutbox::STATUS_SUSPENDED,
            'request_started_at' => null,
            'processed_at' => null,
            'provider_post_id' => null,
            'last_error_category' => 'control_plane',
            'last_error_code' => 'transport_transition_hold',
            'last_error_message' => 'Pulse delivery is suspended before any remote request.',
        ]);

    $held = $service->placeOnRollbackHold(
        $owner,
        $owner,
        hash('sha256', 'candidate exact generation hold'),
    );
    $releaseEvidence = hash('sha256', 'candidate exact generation release');
    $resumed = $service->resumeAfterRollbackHold($owner, $owner, $releaseEvidence);
    $replayed = $service->resumeAfterRollbackHold($owner, $owner, $releaseEvidence);

    expect($held->rollback_resume_state)->toBe(SocialTransportCutover::STATE_CANARY_ACTIVE)
        ->and($resumed->state)->toBe(SocialTransportCutover::STATE_CANARY_ACTIVE)
        ->and($replayed->id)->toBe($resumed->id)
        ->and($candidateDelivery['outbox']->fresh()->status)
        ->toBe(SocialDeliveryOutbox::STATUS_PENDING)
        ->and($legacyDelivery['outbox']->fresh()->status)
        ->toBe(SocialDeliveryOutbox::STATUS_PENDING)
        ->and(SocialTransportCutoverEvent::query()->where('user_id', $owner->id)->count())
        ->toBe(4);

    DB::table('social_delivery_outbox')
        ->where('id', $candidateDelivery['outbox']->id)
        ->update([
            'status' => SocialDeliveryOutbox::STATUS_UNKNOWN,
            'reconciliation_resolved_at' => null,
        ]);
    DB::table('social_delivery_outbox')
        ->where('id', $legacyDelivery['outbox']->id)
        ->update([
            'status' => SocialDeliveryOutbox::STATUS_SUSPENDED,
            'request_started_at' => null,
            'processed_at' => null,
            'provider_post_id' => null,
            'last_error_category' => 'control_plane',
            'last_error_code' => 'transport_transition_hold',
        ]);

    expect(fn () => $service->resumeAfterRollbackHold(
        $owner,
        $owner,
        $releaseEvidence,
    ))->toThrow(LogicException::class, 'must be reconciled')
        ->and($legacyDelivery['outbox']->fresh()->status)
        ->toBe(SocialDeliveryOutbox::STATUS_SUSPENDED);

    expect(fn () => $service->resumeAfterRollbackHold(
        $owner,
        $owner,
        hash('sha256', 'different candidate release replay'),
    ))->toThrow(LogicException::class, 'replay evidence does not match');
});

it('keeps a candidate rollback hold closed while either transport has an ambiguous effect', function () {
    $owner = User::factory()->create();
    $deliveryApprover = pulseStep3DeliveryApprover();
    $legacy = pulseStep3DirectConnection($owner, 'candidate-ambiguous');
    $replacement = pulseStep3ReplacementConnection($owner, $legacy, 'candidate-ambiguous');
    $service = app(SocialTransportCutoverService::class);
    $mapping = $service->recordOwnerValidatedMapping(
        $owner,
        $owner,
        $legacy,
        $replacement,
        hash('sha256', 'candidate ambiguous owner evidence'),
    );
    pulseStep3ValidateShadow($mapping, 'candidate ambiguous shadow evidence');
    pulseStep3ActivateCanary(
        $deliveryApprover,
        SocialTransportCutover::query()->where('user_id', $owner->id)->sole(),
    );
    $candidateDelivery = pulseStep3QueuedDelivery($owner, $replacement);
    $legacyDelivery = pulseStep3QueuedDelivery($owner, $legacy);
    DB::table('social_delivery_outbox')
        ->where('id', $legacyDelivery['outbox']->id)
        ->update([
            'status' => SocialDeliveryOutbox::STATUS_UNKNOWN,
            'reconciliation_resolved_at' => null,
        ]);

    $service->placeOnRollbackHold(
        $owner,
        $owner,
        hash('sha256', 'candidate ambiguous hold'),
    );

    expect(fn () => $service->resumeAfterRollbackHold(
        $owner,
        $owner,
        hash('sha256', 'candidate ambiguous release'),
    ))->toThrow(LogicException::class, 'must be reconciled')
        ->and(SocialTransportCutover::query()->where('user_id', $owner->id)->value('state'))
        ->toBe(SocialTransportCutover::STATE_ROLLBACK_HOLD)
        ->and($candidateDelivery['outbox']->fresh()->status)
        ->toBe(SocialDeliveryOutbox::STATUS_PENDING);
});

it('keeps H2 and H3 authority immutable at approval time', function () {
    $owner = User::factory()->create();
    $deliveryApprover = pulseStep3DeliveryApprover();
    $h3Approver = pulseStep3DeliveryApprover();
    $legacy = pulseStep3DirectConnection($owner, 'approval-actor');
    $replacement = pulseStep3ReplacementConnection($owner, $legacy, 'approval-actor');
    $mapping = app(SocialTransportCutoverService::class)->recordOwnerValidatedMapping(
        $owner,
        $owner,
        $legacy,
        $replacement,
        hash('sha256', 'approval actor owner evidence'),
    );
    pulseStep3ValidateShadow($mapping, 'approval actor shadow evidence');
    $cutover = pulseStep3ActivateCanary(
        $deliveryApprover,
        SocialTransportCutover::query()->where('user_id', $owner->id)->sole(),
    );
    $policy = app(SocialTransportPolicyService::class);
    $allowsCandidate = fn (): bool => $policy->allowsNewSubmission(
        $owner->id,
        SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
        $replacement->id,
        $replacement->logical_destination_key,
    );

    expect($cutover->hasCoherentState())->toBeTrue()
        ->and($allowsCandidate())->toBeTrue();

    $deliveryApprover->forceFill(['role_id' => $owner->role_id])->save();

    expect($deliveryApprover->fresh()->isSuperadmin())->toBeFalse()
        ->and($allowsCandidate())->toBeTrue();

    DB::table('social_transport_cutovers')
        ->where('id', $cutover->id)
        ->update(['h2_approval_authority' => null]);
    expect($allowsCandidate())->toBeFalse();

    DB::table('social_transport_cutovers')
        ->where('id', $cutover->id)
        ->update(['h2_approval_authority' => 'workspace_owner']);
    expect($allowsCandidate())->toBeFalse();

    DB::table('social_transport_cutovers')
        ->where('id', $cutover->id)
        ->update([
            'h2_approval_authority' => SocialTransportCutover::APPROVAL_AUTHORITY_SUPERADMIN,
        ]);
    expect($allowsCandidate())->toBeTrue();

    $cutover = pulseStep3CompleteCutover(
        $deliveryApprover,
        $h3Approver,
        $cutover,
    );
    expect($cutover->hasCoherentState())->toBeTrue()
        ->and($allowsCandidate())->toBeTrue();

    $h3Approver->forceFill(['role_id' => $owner->role_id])->save();

    expect($h3Approver->fresh()->isSuperadmin())->toBeFalse()
        ->and($allowsCandidate())->toBeTrue();

    DB::table('social_transport_cutovers')
        ->where('id', $cutover->id)
        ->update(['h3_approval_authority' => null]);
    expect($allowsCandidate())->toBeFalse();

    DB::table('social_transport_cutovers')
        ->where('id', $cutover->id)
        ->update(['h3_approval_authority' => 'workspace_owner']);
    expect($allowsCandidate())->toBeFalse();

    DB::table('social_transport_cutovers')
        ->where('id', $cutover->id)
        ->update([
            'h3_approval_authority' => SocialTransportCutover::APPROVAL_AUTHORITY_SUPERADMIN,
        ]);
    expect($allowsCandidate())->toBeTrue();
});

it('rejects subthreshold future or post H2 canary evidence at the runtime policy', function () {
    $owner = User::factory()->create();
    $deliveryApprover = pulseStep3DeliveryApprover();
    $legacy = pulseStep3DirectConnection($owner, 'h2-contract');
    $replacement = pulseStep3ReplacementConnection($owner, $legacy, 'h2-contract');
    $mapping = app(SocialTransportCutoverService::class)->recordOwnerValidatedMapping(
        $owner,
        $owner,
        $legacy,
        $replacement,
        hash('sha256', 'H2 contract owner evidence'),
    );
    pulseStep3ValidateShadow($mapping, 'H2 contract shadow evidence');
    $cutover = pulseStep3ActivateCanary(
        $deliveryApprover,
        SocialTransportCutover::query()->where('user_id', $owner->id)->sole(),
    );
    $policy = app(SocialTransportPolicyService::class);
    $allowsCandidate = fn (): bool => $policy->allowsNewSubmission(
        $owner->id,
        SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
        $replacement->id,
        $replacement->logical_destination_key,
    );

    expect($allowsCandidate())->toBeTrue();

    foreach ([
        ['canary_minimum_deliveries' => SocialTransportCutover::CANARY_MINIMUM_DELIVERIES - 1],
        ['canary_minimum_hours' => SocialTransportCutover::CANARY_MINIMUM_HOURS - 1],
        ['canary_maximum_unknown' => SocialTransportCutover::CANARY_MAXIMUM_UNKNOWN + 1],
        ['rollback_rto_seconds' => SocialTransportCutover::ROLLBACK_MAXIMUM_RTO_SECONDS + 1],
        ['h2_approved_at' => now()->addMinute()],
        ['canary_started_at' => now()->addMinute()],
    ] as $invalidContract) {
        DB::table('social_transport_cutovers')
            ->where('id', $cutover->id)
            ->update($invalidContract);

        expect($cutover->fresh()->hasCoherentState())->toBeFalse()
            ->and($allowsCandidate())->toBeFalse();
        $cutover = pulseStep3ActivateCanary($deliveryApprover, $cutover);
    }

    DB::table('social_transport_cutover_mappings')
        ->where('id', $mapping->id)
        ->update([
            'shadow_validated_at' => $cutover->h2_approved_at,
            'shadow_evidence_hash' => hash('sha256', 'shadow changed after H2 in same second'),
        ]);

    expect($cutover->fresh()->hasCoherentState())->toBeTrue()
        ->and($allowsCandidate())->toBeFalse();

    pulseStep3ValidateShadow($mapping, 'H2 contract shadow evidence');
    $cutover = pulseStep3ActivateCanary($deliveryApprover, $cutover);

    DB::table('social_transport_cutover_mappings')
        ->where('id', $mapping->id)
        ->update([
            'owner_validated_at' => now(),
            'shadow_validated_at' => now(),
        ]);

    expect($cutover->fresh()->hasCoherentState())->toBeTrue()
        ->and($allowsCandidate())->toBeFalse();
});

it('fails closed for a short canary a premature rollback horizon or a reversed timeline', function () {
    $owner = User::factory()->create();
    $deliveryApprover = pulseStep3DeliveryApprover();
    $legacy = pulseStep3DirectConnection($owner, 'timeline');
    $replacement = pulseStep3ReplacementConnection($owner, $legacy, 'timeline');
    $mapping = app(SocialTransportCutoverService::class)->recordOwnerValidatedMapping(
        $owner,
        $owner,
        $legacy,
        $replacement,
        hash('sha256', 'timeline owner evidence'),
    );
    pulseStep3ValidateShadow($mapping, 'timeline shadow evidence');
    $cutover = pulseStep3CompleteCutover(
        $deliveryApprover,
        $deliveryApprover,
        SocialTransportCutover::query()->where('user_id', $owner->id)->sole(),
    );
    $policy = app(SocialTransportPolicyService::class);
    $allowsCandidate = fn (): bool => $policy->allowsExistingRemoteEffect(
        $owner->id,
        SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
        $replacement->id,
        $replacement->logical_destination_key,
    );

    expect($cutover->hasCoherentState())->toBeTrue()
        ->and($allowsCandidate())->toBeTrue();

    DB::table('social_transport_cutovers')
        ->where('id', $cutover->id)
        ->update(['canary_completed_at' => now()->subDays(8)->addMinute()]);

    expect($cutover->fresh()->hasCoherentState())->toBeFalse()
        ->and($allowsCandidate())->toBeFalse();

    $cutover = pulseStep3CompleteCutover(
        $deliveryApprover,
        $deliveryApprover,
        $cutover,
    );
    DB::table('social_transport_cutovers')
        ->where('id', $cutover->id)
        ->update(['rollback_window_ends_at' => now()->subHours(11)]);

    expect($cutover->fresh()->hasCoherentState())->toBeFalse()
        ->and($allowsCandidate())->toBeFalse();

    $cutover = pulseStep3CompleteCutover(
        $deliveryApprover,
        $deliveryApprover,
        $cutover,
    );
    DB::table('social_transport_cutovers')
        ->where('id', $cutover->id)
        ->update(['legacy_drain_completed_at' => now()->subDays(11)]);

    expect($cutover->fresh()->hasCoherentState())->toBeFalse()
        ->and($allowsCandidate())->toBeFalse();

    $cutover = pulseStep3CompleteCutover(
        $deliveryApprover,
        $deliveryApprover,
        $cutover,
    );
    expect($cutover->fresh()->hasCoherentState())->toBeTrue();

    $negativeUnknownEvidence = $cutover->fresh();
    $negativeUnknownEvidence->setRawAttributes([
        ...$negativeUnknownEvidence->getAttributes(),
        'canary_observed_unknown' => -1,
    ]);

    expect($negativeUnknownEvidence->hasCoherentState())->toBeFalse();

    foreach ([
        ['canary_observed_deliveries' => SocialTransportCutover::CANARY_MINIMUM_DELIVERIES - 1],
        ['canary_observed_unknown' => SocialTransportCutover::CANARY_MAXIMUM_UNKNOWN + 1],
        ['canary_observed_rollback_rto_seconds' => SocialTransportCutover::ROLLBACK_MAXIMUM_RTO_SECONDS + 1],
        ['cutover_at' => null],
        ['cutover_at' => now()->subHour()],
        ['legacy_drain_evidence_hash' => null],
        ['rollback_status' => SocialTransportCutover::ROLLBACK_AVAILABLE],
    ] as $invalidOperationalProof) {
        $cutover = pulseStep3CompleteCutover(
            $deliveryApprover,
            $deliveryApprover,
            $cutover,
        );
        DB::table('social_transport_cutovers')
            ->where('id', $cutover->id)
            ->update($invalidOperationalProof);

        expect($cutover->fresh()->hasCoherentState())->toBeFalse()
            ->and($allowsCandidate())->toBeFalse();
    }
});

it('represents a completed drain awaiting H3 without pretending direct retirement', function () {
    $owner = User::factory()->create();
    $deliveryApprover = pulseStep3DeliveryApprover();
    $legacy = pulseStep3DirectConnection($owner, 'awaiting-h3');
    $replacement = pulseStep3ReplacementConnection($owner, $legacy, 'awaiting-h3');
    $mapping = app(SocialTransportCutoverService::class)->recordOwnerValidatedMapping(
        $owner,
        $owner,
        $legacy,
        $replacement,
        hash('sha256', 'awaiting H3 owner evidence'),
    );
    pulseStep3ValidateShadow($mapping, 'awaiting H3 shadow evidence');
    $cutover = pulseStep3CompleteCutover(
        $deliveryApprover,
        $deliveryApprover,
        SocialTransportCutover::query()->where('user_id', $owner->id)->sole(),
    );
    DB::table('social_transport_cutovers')
        ->where('id', $cutover->id)
        ->update([
            'state' => SocialTransportCutover::STATE_AWAITING_H3,
            'rollback_status' => SocialTransportCutover::ROLLBACK_AVAILABLE,
            'h3_approved_by_user_id' => null,
            'h3_approval_authority' => null,
            'h3_evidence_hash' => null,
            'h3_go_general_at' => null,
            'h3_direct_removal_authorized_at' => null,
            'rollback_window_ends_at' => null,
            'direct_retired_at' => null,
        ]);
    $cutover = $cutover->fresh();

    expect($cutover->hasCoherentState())->toBeTrue()
        ->and($cutover->hasCompleteCanaryEvidence())->toBeTrue()
        ->and($cutover->hasCompleteLegacyDrainEvidence())->toBeTrue()
        ->and($cutover->hasCompleteH3Decision())->toBeFalse()
        ->and(app(SocialTransportPolicyService::class)->allowsExistingRemoteEffect(
            $owner->id,
            SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
            $legacy->id,
            $legacy->logical_destination_key,
        ))->toBeFalse()
        ->and(app(SocialTransportPolicyService::class)->allowsExistingRemoteEffect(
            $owner->id,
            SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
            $replacement->id,
            $replacement->logical_destination_key,
        ))->toBeTrue();

    DB::table('social_transport_cutovers')
        ->where('id', $cutover->id)
        ->update(['rollback_window_ends_at' => now()->addDay()]);

    expect($cutover->fresh()->hasCoherentState())->toBeFalse()
        ->and(app(SocialTransportPolicyService::class)->allowsExistingRemoteEffect(
            $owner->id,
            SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
            $replacement->id,
            $replacement->logical_destination_key,
        ))->toBeFalse();
});

it('purges cutover mappings and audit events inside full tenant deletion', function () {
    $owner = User::factory()->create();
    $foreignOwner = User::factory()->create();
    $legacy = pulseStep3DirectConnection($owner, 'tenant-deletion');
    $replacement = pulseStep3ReplacementConnection($owner, $legacy);
    $foreignLegacy = pulseStep3DirectConnection($foreignOwner, 'foreign-tenant-deletion');
    $foreignReplacement = pulseStep3ReplacementConnection($foreignOwner, $foreignLegacy);
    $service = app(SocialTransportCutoverService::class);
    $service->recordOwnerValidatedMapping(
        $owner,
        $owner,
        $legacy,
        $replacement,
        hash('sha256', 'deletion mapping evidence'),
    );
    $service->recordOwnerValidatedMapping(
        $foreignOwner,
        $foreignOwner,
        $foreignLegacy,
        $foreignReplacement,
        hash('sha256', 'foreign deletion mapping evidence'),
    );

    app(AccountDeletionService::class)->deleteAccount($owner);

    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse()
        ->and(DB::table('social_transport_cutover_events')->where('user_id', $owner->id)->count())
        ->toBe(0)
        ->and(DB::table('social_transport_cutover_mappings')->where('user_id', $owner->id)->count())
        ->toBe(0)
        ->and(DB::table('social_transport_cutovers')->where('user_id', $owner->id)->count())
        ->toBe(0)
        ->and(DB::table('social_transport_cutover_events')->where('user_id', $foreignOwner->id)->count())
        ->toBe(2)
        ->and(DB::table('social_transport_cutover_mappings')->where('user_id', $foreignOwner->id)->count())
        ->toBe(1)
        ->and(DB::table('social_transport_cutovers')->where('user_id', $foreignOwner->id)->count())
        ->toBe(1);
});
