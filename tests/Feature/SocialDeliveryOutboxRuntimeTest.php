<?php

use App\Jobs\ProcessSocialDeliveryOutboxJob;
use App\Models\SocialAccountConnection;
use App\Models\SocialDeliveryOutbox;
use App\Models\SocialPost;
use App\Models\SocialPostRevision;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\Social\SocialAccountConnectionService;
use App\Services\Social\SocialConnectionDeliveryMutex;
use App\Services\Social\SocialDeliveryOutboxService;
use App\Services\Social\SocialPostRevisionService;
use App\Services\Social\SocialPublishingService;
use App\Support\QueueWorkload;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/**
 * @return array{owner:User,connection:SocialAccountConnection,post:SocialPost,target:SocialPostTarget,revision:SocialPostRevision,payload:array<string,mixed>}
 */
function pulseOutboxRuntimeFixture(string $content = 'Approved Pulse outbox payload'): array
{
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_timezone' => 'America/Toronto',
    ]);
    $externalAccountId = 'outbox-page-'.$owner->id;
    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Outbox Facebook page',
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
    $post = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'source_type' => 'service',
        'source_id' => $owner->id,
        'content_payload' => ['text' => $content],
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

    return [
        'owner' => $owner,
        'connection' => $connection,
        'post' => $post->fresh(),
        'target' => $target->fresh(),
        'revision' => $revision->fresh(),
        'payload' => [
            'post_id' => $post->id,
            'target_id' => $target->id,
            'revision_id' => $revision->id,
            'platform' => $connection->platform,
            'text' => $content,
            'metadata' => ['target_type' => 'page'],
        ],
    ];
}

/**
 * @param  array{owner:User,connection:SocialAccountConnection,post:SocialPost,target:SocialPostTarget,revision:SocialPostRevision,payload:array<string,mixed>}  $fixture
 */
function createPulseOutboxRuntimeEntry(
    array $fixture,
    ?Carbon $availableAt = null,
): SocialDeliveryOutbox {
    return DB::transaction(fn (): SocialDeliveryOutbox => app(SocialDeliveryOutboxService::class)
        ->createForTarget(
            $fixture['owner'],
            $fixture['target'],
            $fixture['revision'],
            $fixture['connection'],
            $fixture['payload'],
            $availableAt ?? now(),
        ));
}

it('creates one encrypted outbox operation for repeated publication intents', function () {
    $this->travelTo(Carbon::parse('2026-08-28 12:00:00', 'UTC'));
    $fixture = pulseOutboxRuntimeFixture();

    $first = createPulseOutboxRuntimeEntry($fixture);
    $second = createPulseOutboxRuntimeEntry($fixture);
    $rawPayload = DB::table('social_delivery_outbox')
        ->where('id', $first->id)
        ->value('payload');

    expect($second->id)->toBe($first->id)
        ->and(SocialDeliveryOutbox::query()->count())->toBe(1)
        ->and($first->status)->toBe(SocialDeliveryOutbox::STATUS_PENDING)
        ->and($first->attempts)->toBe(0)
        ->and($first->user_id)->toBe($fixture['owner']->id)
        ->and($first->social_post_revision_id)->toBe($fixture['revision']->id)
        ->and($first->payload)->toEqual($fixture['payload'])
        ->and((string) $rawPayload)->not->toContain('Approved Pulse outbox payload');
});

it('rejects cross-tenant and secret-bearing publication intents without an outbox write', function () {
    $fixture = pulseOutboxRuntimeFixture();
    $otherOwner = User::factory()->create();
    $service = app(SocialDeliveryOutboxService::class);

    expect(fn () => DB::transaction(fn (): SocialDeliveryOutbox => $service->createForTarget(
        $otherOwner,
        $fixture['target'],
        $fixture['revision'],
        $fixture['connection'],
        $fixture['payload'],
        now(),
    )))->toThrow(LogicException::class, 'crosses a workspace boundary');

    foreach ([
        'access_token',
        'session_token',
        'client_secret',
        'cookie',
        'code_verifier',
        'x_amz_signature',
    ] as $secretKey) {
        $payloadWithSecret = $fixture['payload'];
        $payloadWithSecret['metadata'][$secretKey] = 'must-never-persist';

        expect(fn () => DB::transaction(fn (): SocialDeliveryOutbox => $service->createForTarget(
            $fixture['owner'],
            $fixture['target'],
            $fixture['revision'],
            $fixture['connection'],
            $payloadWithSecret,
            now(),
        )))->toThrow(InvalidArgumentException::class, 'forbidden secret field');
    }

    expect(fn () => DB::transaction(fn (): SocialDeliveryOutbox => $service->createForTarget(
        $fixture['owner'],
        $fixture['target'],
        $fixture['revision'],
        $fixture['connection'],
        $fixture['payload'],
        now(),
        SocialDeliveryOutbox::OPERATION_UPDATE,
    )))->toThrow(InvalidArgumentException::class, 'accepts create operations only');

    expect(SocialDeliveryOutbox::query()->count())->toBe(0);
});

it('refuses to supersede an unknown create without a reconciliation decision', function () {
    $fixture = pulseOutboxRuntimeFixture('Unknown recovery boundary');
    $outbox = createPulseOutboxRuntimeEntry($fixture);
    $outbox->forceFill([
        'status' => SocialDeliveryOutbox::STATUS_UNKNOWN,
        'processed_at' => now(),
    ])->save();

    expect(fn () => DB::transaction(fn (): SocialDeliveryOutbox => app(SocialDeliveryOutboxService::class)
        ->createForTarget(
            $fixture['owner'],
            $fixture['target'],
            $fixture['revision'],
            $fixture['connection'],
            $fixture['payload'],
            now(),
            recoveryGeneration: 1,
            supersedes: $outbox->fresh(),
        )))->toThrow(LogicException::class, 'superseded Pulse delivery outbox entry is invalid');

    expect(SocialDeliveryOutbox::query()->count())->toBe(1);
});

it('quarantines a corrupted cross-tenant outbox before mutating local delivery state', function () {
    Http::fake();
    $fixture = pulseOutboxRuntimeFixture('Cross-tenant runtime guard');
    $outbox = createPulseOutboxRuntimeEntry($fixture);
    $otherOwner = User::factory()->create();
    $postBefore = $fixture['post']->fresh()->getAttributes();
    $targetBefore = $fixture['target']->fresh()->getAttributes();

    DB::table('social_delivery_outbox')
        ->where('id', $outbox->id)
        ->update(['user_id' => $otherOwner->id]);

    app(SocialPublishingService::class)->handleOutboxPublication($outbox->id);

    expect($outbox->fresh()->status)->toBe(SocialDeliveryOutbox::STATUS_DEAD)
        ->and($outbox->fresh()->last_error_code)->toBe('tenant_boundary_mismatch')
        ->and($fixture['post']->fresh()->getAttributes())->toBe($postBefore)
        ->and($fixture['target']->fresh()->getAttributes())->toBe($targetBefore);

    Http::assertNothingSent();
});

it('repairs a terminal target aggregate durably when the first worker stopped after commit', function () {
    Http::fake();
    $fixture = pulseOutboxRuntimeFixture('Durable aggregate repair');
    $outbox = createPulseOutboxRuntimeEntry($fixture);
    $outboxes = app(SocialDeliveryOutboxService::class);
    $claim = $outboxes->claim($outbox->id, 'aggregate-crash-worker');

    $fixture['post']->forceFill([
        'status' => SocialPost::STATUS_PUBLISHING,
        'delivery_status' => SocialPost::DELIVERY_STATUS_QUEUED,
    ])->save();

    expect($outboxes->startSubmitting(
        $outbox->id,
        $claim['claim_token'],
        $claim['claim_version'],
        function () use ($fixture): void {
            $fixture['target']->forceFill([
                'status' => SocialPostTarget::STATUS_PUBLISHING,
                'delivery_status' => 'sending',
            ])->save();
        },
    ))->toBeTrue();

    expect($outboxes->markCompleted(
        $outbox->id,
        $claim['claim_token'],
        $claim['claim_version'],
        'remote-post-before-local-crash',
        afterTransition: function () use ($fixture): void {
            $fixture['target']->forceFill([
                'status' => SocialPostTarget::STATUS_PUBLISHED,
                'delivery_status' => SocialPost::DELIVERY_STATUS_PUBLISHED,
                'sync_status' => SocialPost::SYNC_STATUS_SYNCED,
                'published_at' => now(),
            ])->save();
        },
    ))->toBeTrue()
        ->and($fixture['post']->fresh()->status)->toBe(SocialPost::STATUS_PUBLISHING)
        ->and($outbox->fresh()->aggregate_repaired_at)->toBeNull();

    app(SocialPublishingService::class)->handleOutboxPublication($outbox->id);

    expect($fixture['post']->fresh()->status)->toBe(SocialPost::STATUS_PUBLISHED)
        ->and($fixture['post']->fresh()->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_PUBLISHED)
        ->and($outbox->fresh()->aggregate_repaired_at)->not->toBeNull();

    Http::assertNothingSent();
});

it('keeps a cross-tenant expired lease from repairing another workspace post', function () {
    $this->travelTo(Carbon::parse('2026-08-28 12:00:00', 'UTC'));
    Queue::fake([ProcessSocialDeliveryOutboxJob::class]);
    $fixture = pulseOutboxRuntimeFixture('Cross-tenant sweeper guard');
    $outbox = createPulseOutboxRuntimeEntry($fixture);
    $otherOwner = User::factory()->create();
    $outboxes = app(SocialDeliveryOutboxService::class);
    $claim = $outboxes->claim($outbox->id, 'cross-tenant-expired-worker', 60);
    $outboxes->startSubmitting(
        $outbox->id,
        $claim['claim_token'],
        $claim['claim_version'],
    );
    DB::table('social_delivery_outbox')
        ->where('id', $outbox->id)
        ->update(['user_id' => $otherOwner->id]);
    $postBefore = $fixture['post']->fresh()->getAttributes();
    $targetBefore = $fixture['target']->fresh()->getAttributes();

    $this->travel(61)->seconds();
    $summary = app(SocialPublishingService::class)->maintainDeliveryOutbox();

    expect($summary)->toBe([
        'pending_recovered' => 0,
        'unknown_quarantined' => 1,
        'aggregates_repaired' => 1,
        'dispatched' => 0,
    ])->and($outbox->fresh()->status)->toBe(SocialDeliveryOutbox::STATUS_UNKNOWN)
        ->and($outbox->fresh()->aggregate_repaired_at)->not->toBeNull()
        ->and($fixture['post']->fresh()->getAttributes())->toBe($postBefore)
        ->and($fixture['target']->fresh()->getAttributes())->toBe($targetBefore);
});

it('serializes publication with disconnect and never reuses stale credentials', function () {
    $this->travelTo(Carbon::parse('2026-08-28 12:00:00', 'UTC'));
    Http::fake();
    $fixture = pulseOutboxRuntimeFixture('Disconnect serialization');
    $outbox = createPulseOutboxRuntimeEntry($fixture);
    $mutex = app(SocialConnectionDeliveryMutex::class);
    $connectionLock = $mutex->acquire((int) $fixture['connection']->id);

    expect($connectionLock)->not->toBeNull();

    try {
        $connections = app(SocialAccountConnectionService::class);

        expect(fn () => $connections->disconnect(
            $fixture['owner'],
            $fixture['connection'],
        ))->toThrow(ValidationException::class, 'Retry this change shortly')
            ->and(fn () => $connections->update(
                $fixture['owner'],
                $fixture['connection'],
                ['is_active' => false],
            ))->toThrow(ValidationException::class, 'Retry this change shortly')
            ->and(fn () => $connections->authorize(
                $fixture['owner'],
                $fixture['connection'],
            ))->toThrow(ValidationException::class, 'Retry this change shortly')
            ->and(fn () => $connections->refresh(
                $fixture['owner'],
                $fixture['connection'],
            ))->toThrow(ValidationException::class, 'Retry this change shortly')
            ->and(fn () => $connections->test(
                $fixture['owner'],
                $fixture['connection'],
            ))->toThrow(ValidationException::class, 'Retry this change shortly')
            ->and(fn () => $connections->createTestConnection(
                $fixture['owner'],
                [
                    'platform' => $fixture['connection']->platform,
                    'external_account_id' => $fixture['connection']->external_account_id,
                ],
            ))->toThrow(ValidationException::class, 'Retry this change shortly');

        app(SocialPublishingService::class)->handleOutboxPublication($outbox->id);
    } finally {
        $connectionLock?->release();
    }

    expect($outbox->fresh()->status)->toBe(SocialDeliveryOutbox::STATUS_RETRYABLE)
        ->and($fixture['connection']->fresh()->status)->toBe(SocialAccountConnection::STATUS_CONNECTED);

    app(SocialAccountConnectionService::class)->disconnect(
        $fixture['owner'],
        $fixture['connection']->fresh(),
    );

    $this->travel(6)->seconds();
    app(SocialPublishingService::class)->handleOutboxPublication($outbox->id);

    expect($fixture['connection']->fresh()->status)->toBe(SocialAccountConnection::STATUS_DISCONNECTED)
        ->and($outbox->fresh()->status)->toBe(SocialDeliveryOutbox::STATUS_DEAD)
        ->and($outbox->fresh()->last_error_code)->toBe('connection_unavailable');

    Http::assertNothingSent();
});

it('serializes an oauth callback with an active connection delivery', function () {
    $fixture = pulseOutboxRuntimeFixture('OAuth callback serialization');
    $state = str_repeat('s', 64);
    $fixture['connection']->forceFill([
        'status' => SocialAccountConnection::STATUS_PENDING,
        'is_active' => false,
        'oauth_state' => $state,
        'oauth_state_expires_at' => now()->addMinutes(15),
    ])->save();
    $connectionLock = app(SocialConnectionDeliveryMutex::class)
        ->acquire((int) $fixture['connection']->id);

    expect($connectionLock)->not->toBeNull();

    try {
        expect(fn () => app(SocialAccountConnectionService::class)->completeAuthorization(
            SocialAccountConnection::PLATFORM_FACEBOOK,
            ['state' => $state, 'code' => 'oauth-code'],
        ))->toThrow(ValidationException::class, 'Retry this change shortly');
    } finally {
        $connectionLock?->release();
    }

    expect($fixture['connection']->fresh()->status)->toBe(SocialAccountConnection::STATUS_PENDING)
        ->and($fixture['connection']->fresh()->oauth_state)->toBe($state);
});

it('retains connection history on ordinary deletion and purges it on full account deletion', function () {
    $fixture = pulseOutboxRuntimeFixture('Account deletion outbox history');
    $outbox = createPulseOutboxRuntimeEntry($fixture);

    expect(fn () => app(SocialAccountConnectionService::class)->destroy(
        $fixture['owner'],
        $fixture['connection'],
    ))->toThrow(ValidationException::class, 'must be disconnected instead of deleted')
        ->and($fixture['connection']->fresh())->not->toBeNull()
        ->and($outbox->fresh())->not->toBeNull();

    $mutex = app(SocialConnectionDeliveryMutex::class);
    $tenantLock = $mutex->acquireTenant((int) $fixture['owner']->id);

    expect($tenantLock)->not->toBeNull();

    try {
        expect(fn () => app(SocialAccountConnectionService::class)->create(
            $fixture['owner'],
            ['platform' => SocialAccountConnection::PLATFORM_FACEBOOK],
        ))->toThrow(ValidationException::class, 'Retry shortly')
            ->and(fn () => app(AccountDeletionService::class)->deleteAccount($fixture['owner']))
            ->toThrow(LogicException::class, 'Retry account deletion shortly');
    } finally {
        $tenantLock?->release();
    }

    $connectionLock = $mutex->acquire((int) $fixture['connection']->id);

    expect($connectionLock)->not->toBeNull();

    try {
        expect(fn () => app(AccountDeletionService::class)->deleteAccount($fixture['owner']))
            ->toThrow(LogicException::class, 'Retry account deletion shortly');
    } finally {
        $connectionLock?->release();
    }

    expect(User::query()->whereKey($fixture['owner']->id)->exists())->toBeTrue()
        ->and(SocialDeliveryOutbox::query()->whereKey($outbox->id)->exists())->toBeTrue();

    app(AccountDeletionService::class)->deleteAccount($fixture['owner']);

    expect(User::query()->whereKey($fixture['owner']->id)->exists())->toBeFalse()
        ->and(SocialDeliveryOutbox::query()->whereKey($outbox->id)->exists())->toBeFalse();
});

it('fails closed when an encrypted payload or its integrity hash is altered', function () {
    $firstFixture = pulseOutboxRuntimeFixture('First integrity payload');
    $secondFixture = pulseOutboxRuntimeFixture('Second integrity payload');
    $first = createPulseOutboxRuntimeEntry($firstFixture);
    $second = createPulseOutboxRuntimeEntry($secondFixture);
    $service = app(SocialDeliveryOutboxService::class);
    $firstRawPayload = DB::table('social_delivery_outbox')
        ->where('id', $first->id)
        ->value('payload');
    $secondRawPayload = DB::table('social_delivery_outbox')
        ->where('id', $second->id)
        ->value('payload');

    expect($service->verifiedPayload($first))->toEqual($firstFixture['payload']);

    DB::table('social_delivery_outbox')
        ->where('id', $first->id)
        ->update(['payload' => $secondRawPayload]);

    expect(fn () => $service->verifiedPayload($first))
        ->toThrow(LogicException::class, 'failed its integrity check');

    DB::table('social_delivery_outbox')
        ->where('id', $first->id)
        ->update([
            'payload' => $firstRawPayload,
            'payload_hash' => str_repeat('0', 64),
        ]);

    expect(fn () => $service->verifiedPayload($first))
        ->toThrow(LogicException::class, 'failed its integrity check');
});

it('fences stale workers and rolls back a failed transition callback', function () {
    $this->travelTo(Carbon::parse('2026-08-28 12:00:00', 'UTC'));
    $outbox = createPulseOutboxRuntimeEntry(pulseOutboxRuntimeFixture());
    $service = app(SocialDeliveryOutboxService::class);
    $claim = $service->claim($outbox->id, 'worker-a');

    expect($claim)->not->toBeNull()
        ->and($service->claim($outbox->id, 'worker-b'))->toBeNull();

    expect(fn () => $service->startSubmitting(
        $outbox->id,
        $claim['claim_token'],
        $claim['claim_version'],
        function (): void {
            throw new RuntimeException('Transition callback failed.');
        },
    ))->toThrow(RuntimeException::class, 'Transition callback failed');

    expect($outbox->fresh()->status)->toBe(SocialDeliveryOutbox::STATUS_CLAIMED)
        ->and($outbox->fresh()->request_started_at)->toBeNull()
        ->and($service->startSubmitting(
            $outbox->id,
            $claim['claim_token'],
            $claim['claim_version'],
        ))->toBeTrue()
        ->and($service->markCompleted(
            $outbox->id,
            $claim['claim_token'],
            $claim['claim_version'] + 1,
            'remote-post-stale',
        ))->toBeFalse()
        ->and($service->markCompleted(
            $outbox->id,
            $claim['claim_token'],
            $claim['claim_version'],
            'remote-post-001',
        ))->toBeTrue()
        ->and($service->markCompleted(
            $outbox->id,
            $claim['claim_token'],
            $claim['claim_version'],
            'remote-post-duplicate',
        ))->toBeFalse();

    $completed = $outbox->fresh();

    expect($completed->status)->toBe(SocialDeliveryOutbox::STATUS_COMPLETED)
        ->and($completed->provider_post_id)->toBe('remote-post-001')
        ->and($completed->claim_token)->toBeNull()
        ->and($completed->processed_at)->not->toBeNull();
});

it('recovers only pre-request expired leases and quarantines possible remote effects', function () {
    $this->travelTo(Carbon::parse('2026-08-28 12:00:00', 'UTC'));
    $safeOutbox = createPulseOutboxRuntimeEntry(pulseOutboxRuntimeFixture('Safe lease'));
    $ambiguousOutbox = createPulseOutboxRuntimeEntry(pulseOutboxRuntimeFixture('Ambiguous lease'));
    $service = app(SocialDeliveryOutboxService::class);
    $safeClaim = $service->claim($safeOutbox->id, 'worker-safe', 60);
    $ambiguousClaim = $service->claim($ambiguousOutbox->id, 'worker-ambiguous', 60);
    $service->startSubmitting(
        $ambiguousOutbox->id,
        $ambiguousClaim['claim_token'],
        $ambiguousClaim['claim_version'],
    );

    $this->travel(61)->seconds();
    $recovered = $service->recoverExpiredLeases();

    expect($recovered)->toBe(['pending' => 1, 'unknown' => 1])
        ->and($safeOutbox->fresh()->status)->toBe(SocialDeliveryOutbox::STATUS_PENDING)
        ->and($safeOutbox->fresh()->claim_version)->toBe(2)
        ->and($ambiguousOutbox->fresh()->status)->toBe(SocialDeliveryOutbox::STATUS_UNKNOWN)
        ->and($ambiguousOutbox->fresh()->last_error_category)->toBe('ambiguous')
        ->and($service->markCompleted(
            $ambiguousOutbox->id,
            $ambiguousClaim['claim_token'],
            $ambiguousClaim['claim_version'],
            'late-remote-post',
        ))->toBeFalse();
});

it('bounds proven-safe retries and expurgates terminal error details', function () {
    $this->travelTo(Carbon::parse('2026-08-28 12:00:00', 'UTC'));
    $outbox = createPulseOutboxRuntimeEntry(pulseOutboxRuntimeFixture());
    $service = app(SocialDeliveryOutboxService::class);
    $firstClaim = $service->claim($outbox->id, 'worker-retry-1');
    $service->startSubmitting(
        $outbox->id,
        $firstClaim['claim_token'],
        $firstClaim['claim_version'],
    );

    expect($service->markRetryable(
        $outbox->id,
        $firstClaim['claim_token'],
        $firstClaim['claim_version'],
        now()->addMinute(),
        'rate_limit',
        'too_many_requests',
        'Bearer first-secret access_token=second-secret',
        2,
    ))->toBe(SocialDeliveryOutbox::STATUS_RETRYABLE)
        ->and($outbox->fresh()->request_started_at)->toBeNull()
        ->and($service->claim($outbox->id, 'worker-too-early'))->toBeNull();

    $this->travel(61)->seconds();
    $secondClaim = $service->claim($outbox->id, 'worker-retry-2');

    expect($service->markRetryable(
        $outbox->id,
        $secondClaim['claim_token'],
        $secondClaim['claim_version'],
        now()->addMinutes(5),
        'rate_limit',
        'too_many_requests',
        'Authorization: Bearer third-secret; access_token=fourth-secret',
        2,
    ))->toBe(SocialDeliveryOutbox::STATUS_DEAD);

    $dead = $outbox->fresh();

    expect($dead->status)->toBe(SocialDeliveryOutbox::STATUS_DEAD)
        ->and($dead->attempts)->toBe(2)
        ->and($dead->last_error_message)->toContain('[redacted]')
        ->and($dead->last_error_message)->not->toContain('third-secret')
        ->and($dead->last_error_message)->not->toContain('fourth-secret');
});

it('dispatches only due outbox rows on the dedicated serialized job', function () {
    $this->travelTo(Carbon::parse('2026-08-28 12:00:00', 'UTC'));
    Queue::fake([ProcessSocialDeliveryOutboxJob::class]);
    $due = createPulseOutboxRuntimeEntry(pulseOutboxRuntimeFixture('Due delivery'));
    createPulseOutboxRuntimeEntry(
        pulseOutboxRuntimeFixture('Future delivery'),
        now()->addHour(),
    );
    $service = app(SocialDeliveryOutboxService::class);

    expect($service->dispatchDue())->toBe(1);

    Queue::assertPushed(
        ProcessSocialDeliveryOutboxJob::class,
        fn (ProcessSocialDeliveryOutboxJob $job): bool => $job->outboxId === $due->id,
    );

    $job = new ProcessSocialDeliveryOutboxJob($due->id);
    $middleware = $job->middleware();

    expect($job)->toBeInstanceOf(ShouldQueueAfterCommit::class)
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->queue)->toBe(QueueWorkload::queue('social_publish'))
        ->and($job->uniqueId())->toBe((string) $due->id)
        ->and($job->timeout)->toBe(QueueWorkload::timeout('social_publish'))
        ->and($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe('social-delivery-outbox:'.$due->id)
        ->and($middleware[0]->shareKey)->toBeTrue();
});

it('repairs expired leases through the domain sweeper without retrying an ambiguous create', function () {
    $this->travelTo(Carbon::parse('2026-08-28 12:00:00', 'UTC'));
    Queue::fake([ProcessSocialDeliveryOutboxJob::class]);
    $safeFixture = pulseOutboxRuntimeFixture('Safe domain recovery');
    $ambiguousFixture = pulseOutboxRuntimeFixture('Ambiguous domain recovery');
    $safeOutbox = createPulseOutboxRuntimeEntry($safeFixture);
    $ambiguousOutbox = createPulseOutboxRuntimeEntry($ambiguousFixture);
    $outboxes = app(SocialDeliveryOutboxService::class);
    $safeClaim = $outboxes->claim($safeOutbox->id, 'safe-domain-worker', 60);
    $ambiguousClaim = $outboxes->claim($ambiguousOutbox->id, 'ambiguous-domain-worker', 60);
    $outboxes->startSubmitting(
        $ambiguousOutbox->id,
        $ambiguousClaim['claim_token'],
        $ambiguousClaim['claim_version'],
    );

    $this->travel(61)->seconds();
    $summary = app(SocialPublishingService::class)->maintainDeliveryOutbox();

    expect($summary)->toBe([
        'pending_recovered' => 1,
        'unknown_quarantined' => 1,
        'aggregates_repaired' => 1,
        'dispatched' => 1,
    ])->and($safeOutbox->fresh()->status)->toBe(SocialDeliveryOutbox::STATUS_PENDING)
        ->and($ambiguousOutbox->fresh()->status)->toBe(SocialDeliveryOutbox::STATUS_UNKNOWN)
        ->and($ambiguousFixture['target']->fresh()->delivery_status)
        ->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN)
        ->and($ambiguousFixture['post']->fresh()->delivery_status)
        ->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN);

    Queue::assertPushed(
        ProcessSocialDeliveryOutboxJob::class,
        fn (ProcessSocialDeliveryOutboxJob $job): bool => $job->outboxId === $safeOutbox->id,
    );
    Queue::assertNotPushed(
        ProcessSocialDeliveryOutboxJob::class,
        fn (ProcessSocialDeliveryOutboxJob $job): bool => $job->outboxId === $ambiguousOutbox->id,
    );
});

it('exposes a bounded scheduled command for durable outbox dispatch', function () {
    Queue::fake([ProcessSocialDeliveryOutboxJob::class]);
    $due = createPulseOutboxRuntimeEntry(pulseOutboxRuntimeFixture('Command dispatch'));

    $this->artisan('social:dispatch-outbox', ['--limit' => 25])
        ->expectsOutputToContain('dispatched 1 due operation(s)')
        ->assertExitCode(0);

    Queue::assertPushed(
        ProcessSocialDeliveryOutboxJob::class,
        fn (ProcessSocialDeliveryOutboxJob $job): bool => $job->outboxId === $due->id,
    );

    $this->artisan('social:dispatch-outbox', ['--limit' => 0])
        ->expectsOutput('The Pulse outbox limit must be an integer between 1 and 1000.')
        ->assertExitCode(1);
});
