<?php

use App\Data\Social\ReadSocialDeliveryStatusData;
use App\Data\Social\SocialDeliveryStatusResultData;
use App\Jobs\ProcessSocialDeliveryOutboxJob;
use App\Models\SocialAccountConnection;
use App\Models\SocialDeliveryOutbox;
use App\Models\SocialPost;
use App\Models\SocialPostRevision;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Notifications\SocialPublicationCompletedNotification;
use App\Services\Social\Contracts\SocialDeliveryStatusGatewayInterface;
use App\Services\Social\SocialConnectionDeliveryMutex;
use App\Services\Social\SocialDeliveryAggregateService;
use App\Services\Social\SocialDeliveryHealthService;
use App\Services\Social\SocialDeliveryOutboxService;
use App\Services\Social\SocialDeliveryReconciler;
use App\Services\Social\SocialOperationalMessageSanitizer;
use App\Services\Social\SocialPostRevisionService;
use App\Services\Social\SocialPostService;
use App\Services\Social\SocialPublishingService;
use App\Services\Social\SocialReconciliationCadence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeSocialDeliveryStatusGateway;

/**
 * @return array{owner:User,connection:SocialAccountConnection,post:SocialPost,target:SocialPostTarget,revision:SocialPostRevision,outbox:SocialDeliveryOutbox}
 */
function pulseReconciliationFixture(bool $withProviderPostId = true): array
{
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_timezone' => 'America/Toronto',
    ]);
    $externalAccountId = 'reconciliation-account-'.$owner->id;
    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Reconciliation account',
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
        'content_payload' => ['text' => 'Approved reconciliation payload'],
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
        'provider_post_id' => $withProviderPostId ? 'remote-post-'.$target->id : null,
        'submitted_at' => now(),
        'delivery_status' => SocialPost::DELIVERY_STATUS_UNKNOWN,
        'sync_status' => SocialPost::SYNC_STATUS_ERROR,
        'status' => SocialPostTarget::STATUS_FAILED,
        'next_reconcile_at' => now(),
    ])->save();

    $fixture = [
        'owner' => $owner,
        'connection' => $connection,
        'post' => $post->fresh(),
        'target' => $target->fresh(),
        'revision' => $revision->fresh(),
    ];
    $outbox = pulseReconciliationOutbox($fixture);
    $outbox->forceFill([
        'status' => SocialDeliveryOutbox::STATUS_UNKNOWN,
        'request_started_at' => now()->subMinute(),
        'processed_at' => now(),
        'provider_post_id' => $fixture['target']->provider_post_id,
        'aggregate_repaired_at' => now(),
    ])->save();

    return [...$fixture, 'outbox' => $outbox->fresh()];
}

function pulseReconciler(
    SocialDeliveryStatusGatewayInterface $gateway,
): SocialDeliveryReconciler {
    return new SocialDeliveryReconciler(
        $gateway,
        app(SocialReconciliationCadence::class),
        app(SocialOperationalMessageSanitizer::class),
        app(SocialDeliveryAggregateService::class),
        app(SocialConnectionDeliveryMutex::class),
    );
}

/**
 * @param  array{owner:User,connection:SocialAccountConnection,post:SocialPost,target:SocialPostTarget,revision:SocialPostRevision}  $fixture
 */
function pulseReconciliationOutbox(
    array $fixture,
    int $recoveryGeneration = 0,
    ?SocialDeliveryOutbox $supersedes = null,
): SocialDeliveryOutbox {
    return DB::transaction(fn (): SocialDeliveryOutbox => app(SocialDeliveryOutboxService::class)
        ->createForTarget(
            $fixture['owner'],
            $fixture['target']->fresh(),
            $fixture['revision'],
            $fixture['connection'],
            [
                'post_id' => $fixture['post']->id,
                'target_id' => $fixture['target']->id,
                'revision_id' => $fixture['revision']->id,
                'platform' => $fixture['connection']->platform,
                'text' => 'Approved reconciliation payload',
            ],
            now(),
            recoveryGeneration: $recoveryGeneration,
            supersedes: $supersedes,
        ));
}

it('claims and reads a target only inside its tenant boundary', function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-08-28 12:00:00', 'UTC'));
    $fixture = pulseReconciliationFixture();
    $otherOwner = User::factory()->create();
    $fake = new FakeSocialDeliveryStatusGateway(
        SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_SENT,
            CarbonImmutable::now('UTC'),
        ),
    );
    $reconciler = pulseReconciler($fake);

    expect($reconciler->claim(
        $otherOwner->id,
        $fixture['target']->id,
        'wrong-tenant-worker',
    ))->toBeNull()
        ->and($reconciler->synchronizeManually(
            $otherOwner->id,
            $fixture['target']->id,
            'wrong-tenant-manual',
        ))->toBeFalse()
        ->and($fake->reads)->toBeEmpty();

    $claim = $reconciler->claim(
        $fixture['owner']->id,
        $fixture['target']->id,
        'tenant-worker',
    );

    expect($claim)->not->toBeNull()
        ->and($reconciler->reconcile($claim))->toBeTrue()
        ->and($fake->reads)->toHaveCount(1)
        ->and($fake->reads[0]->tenantId)->toBe($fixture['owner']->id)
        ->and($fake->reads[0]->targetId)->toBe($fixture['target']->id);

    Notification::assertSentToTimes($fixture['owner'], SocialPublicationCompletedNotification::class, 1);
    Notification::assertSentTo($fixture['owner'], SocialPublicationCompletedNotification::class,
        fn ($notification): bool => $notification->snapshot['outcome'] === 'success');
    Notification::assertNotSentTo($otherOwner, SocialPublicationCompletedNotification::class);
});

it('fails before reading when a connection or target snapshot crosses tenants', function () {
    $fixture = pulseReconciliationFixture();
    $otherOwner = User::factory()->create();
    $fake = new FakeSocialDeliveryStatusGateway(
        SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_SENT,
            CarbonImmutable::now('UTC'),
        ),
    );
    $reconciler = pulseReconciler($fake);
    $claim = $reconciler->claim(
        $fixture['owner']->id,
        $fixture['target']->id,
        'identity-worker',
    );

    expect($claim)->not->toBeNull();

    DB::table('social_account_connections')
        ->where('id', $fixture['connection']->id)
        ->update(['user_id' => $otherOwner->id]);

    expect($reconciler->reconcile($claim))->toBeFalse()
        ->and($fake->reads)->toBeEmpty()
        ->and($fixture['target']->fresh()->next_reconcile_at)->toBeNull()
        ->and($fixture['target']->fresh()->provider_error_code)
        ->toBe('connection_identity_mismatch')
        ->and($fixture['outbox']->fresh()->aggregate_repaired_at)->toBeNull();

    $secondFixture = pulseReconciliationFixture();
    DB::table('social_post_targets')
        ->where('id', $secondFixture['target']->id)
        ->update([
            'social_account_connection_id' => $fixture['connection']->id,
        ]);

    expect($reconciler->claim(
        $secondFixture['owner']->id,
        $secondFixture['target']->id,
        'corrupted-target-worker',
        true,
    ))->toBeNull()
        ->and($fake->reads)->toBeEmpty();
});

it('fails closed before reads without an exact current tenant outbox', function () {
    $missingFixture = pulseReconciliationFixture();
    $missingFixture['outbox']->delete();

    $crossTenantFixture = pulseReconciliationFixture();
    $foreignFixture = pulseReconciliationFixture();
    $crossTenantFixture['outbox']->delete();
    DB::table('social_delivery_outbox')
        ->where('id', $foreignFixture['outbox']->id)
        ->update([
            'social_post_target_id' => $crossTenantFixture['target']->id,
            'social_post_revision_id' => $crossTenantFixture['revision']->id,
            'social_provider_connection_id' => $crossTenantFixture['connection']->id,
            'delivery_provider' => $crossTenantFixture['target']->delivery_provider,
            'transport_generation' => $crossTenantFixture['target']->transport_generation,
            'logical_destination_key' => $crossTenantFixture['target']->logical_destination_key,
            'provider_post_id' => $crossTenantFixture['target']->provider_post_id,
        ]);

    $historicalFixture = pulseReconciliationFixture();
    $historicalFixture['outbox']->forceFill([
        'status' => SocialDeliveryOutbox::STATUS_DEAD,
    ])->save();
    $currentRecovery = pulseReconciliationOutbox(
        $historicalFixture,
        1,
        $historicalFixture['outbox'],
    );
    $historicalFixture['outbox']->forceFill([
        'status' => SocialDeliveryOutbox::STATUS_UNKNOWN,
    ])->save();
    $fake = new FakeSocialDeliveryStatusGateway(
        SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_SENT,
            CarbonImmutable::now('UTC'),
        ),
    );
    $reconciler = pulseReconciler($fake);

    expect($reconciler->reconcileDueForTenant(
        $missingFixture['owner']->id,
        'missing-outbox-poll-worker',
    ))->toBe(['claimed' => 0, 'reconciled' => 0, 'not_applied' => 0])
        ->and($reconciler->synchronizeManually(
            $missingFixture['owner']->id,
            $missingFixture['target']->id,
            'missing-outbox-manual-worker',
        ))->toBeFalse()
        ->and($reconciler->synchronizeManually(
            $crossTenantFixture['owner']->id,
            $crossTenantFixture['target']->id,
            'cross-tenant-outbox-manual-worker',
        ))->toBeFalse()
        ->and($reconciler->synchronizeManually(
            $historicalFixture['owner']->id,
            $historicalFixture['target']->id,
            'historical-outbox-manual-worker',
        ))->toBeFalse()
        ->and($fake->reads)->toBeEmpty()
        ->and($currentRecovery->fresh()->status)->toBe(SocialDeliveryOutbox::STATUS_PENDING);

    foreach ([$missingFixture, $crossTenantFixture, $historicalFixture] as $fixture) {
        expect($fixture['target']->fresh()->delivery_status)
            ->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN)
            ->and($fixture['target']->fresh()->provider_error_code)
            ->toBe('missing_delivery_outbox')
            ->and($fixture['target']->fresh()->next_reconcile_at)->toBeNull();
    }
});

it('does not apply an observation when the current outbox disappears after the read', function () {
    $fixture = pulseReconciliationFixture();
    $sent = SocialDeliveryStatusResultData::observed(
        SocialDeliveryStatusResultData::STATUS_SENT,
        CarbonImmutable::now('UTC')->addSecond(),
    );
    $gateway = new class($fixture['outbox'], $sent) implements SocialDeliveryStatusGatewayInterface
    {
        public int $reads = 0;

        public function __construct(
            private readonly SocialDeliveryOutbox $outbox,
            private readonly SocialDeliveryStatusResultData $result,
        ) {}

        public function readStatus(ReadSocialDeliveryStatusData $delivery): SocialDeliveryStatusResultData
        {
            $this->reads++;
            $this->outbox->delete();

            return $this->result;
        }
    };

    expect(pulseReconciler($gateway)->synchronizeManually(
        $fixture['owner']->id,
        $fixture['target']->id,
        'outbox-race-operator',
    ))->toBeFalse()
        ->and($gateway->reads)->toBe(1)
        ->and($fixture['target']->fresh()->delivery_status)
        ->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN)
        ->and($fixture['target']->fresh()->provider_status)->toBeNull()
        ->and($fixture['target']->fresh()->last_synced_at)->toBeNull()
        ->and($fixture['target']->fresh()->provider_error_code)
        ->toBe('missing_delivery_outbox')
        ->and($fixture['target']->fresh()->next_reconcile_at)->toBeNull();
});

it('rolls back a target transition when the current outbox update fails', function () {
    $fixture = pulseReconciliationFixture();
    $rejectOutboxUpdate = true;
    SocialDeliveryOutbox::saving(function (SocialDeliveryOutbox $outbox) use (
        &$rejectOutboxUpdate,
        $fixture,
    ): ?bool {
        if ($rejectOutboxUpdate
            && (int) $outbox->id === (int) $fixture['outbox']->id
            && $outbox->isDirty('aggregate_repaired_at')) {
            return false;
        }

        return null;
    });
    $fake = new FakeSocialDeliveryStatusGateway(
        SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_SENT,
            CarbonImmutable::now('UTC')->addSecond(),
        ),
    );

    expect(fn () => pulseReconciler($fake)->synchronizeManually(
        $fixture['owner']->id,
        $fixture['target']->id,
        'outbox-update-failure-operator',
    ))->toThrow(
        LogicException::class,
        'current delivery outbox could not be updated with its observation',
    );

    $rejectOutboxUpdate = false;

    expect($fake->reads)->toHaveCount(1)
        ->and($fixture['target']->fresh()->delivery_status)
        ->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN)
        ->and($fixture['target']->fresh()->provider_status)->toBeNull()
        ->and($fixture['target']->fresh()->last_synced_at)->toBeNull()
        ->and($fixture['outbox']->fresh()->reconciliation_resolution)->toBeNull()
        ->and($fixture['outbox']->fresh()->aggregate_repaired_at)->not->toBeNull();
});

it('moves an inactive or disconnected connection to operator review without reading', function () {
    $fixture = pulseReconciliationFixture();
    $fixture['connection']->forceFill([
        'status' => SocialAccountConnection::STATUS_DISCONNECTED,
        'is_active' => false,
    ])->save();
    $fake = new FakeSocialDeliveryStatusGateway(
        SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_SENT,
            CarbonImmutable::now('UTC'),
        ),
    );
    $reconciler = pulseReconciler($fake);

    expect($reconciler->reconcileDueForTenant(
        $fixture['owner']->id,
        'disconnected-poll-worker',
    ))->toBe(['claimed' => 0, 'reconciled' => 0, 'not_applied' => 0])
        ->and($reconciler->synchronizeManually(
            $fixture['owner']->id,
            $fixture['target']->id,
            'disconnected-manual-worker',
        ))->toBeFalse()
        ->and($fake->reads)->toBeEmpty()
        ->and($fixture['target']->fresh()->reconcile_attempts)->toBe(0)
        ->and($fixture['target']->fresh()->next_reconcile_at)->toBeNull()
        ->and($fixture['target']->fresh()->provider_error_code)
        ->toBe('connection_unavailable_for_reconciliation')
        ->and(app(SocialDeliveryHealthService::class)
            ->summaryForTenant($fixture['owner']->id)['reconciliation']['operator_review'])
        ->toBe(1);
});

it('serializes automatic and manual status reads with the connection mutex', function () {
    $this->travelTo(Carbon::parse('2026-08-28 12:00:00', 'UTC'));
    $automaticFixture = pulseReconciliationFixture();
    $manualFixture = pulseReconciliationFixture();
    $fake = new FakeSocialDeliveryStatusGateway(
        SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_UNKNOWN,
            CarbonImmutable::parse('2026-08-28 12:01:00', 'UTC'),
            'raw-after-mutex-a',
        ),
        SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_UNKNOWN,
            CarbonImmutable::parse('2026-08-28 12:06:00', 'UTC'),
            'raw-after-mutex-b',
        ),
        SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_UNKNOWN,
            CarbonImmutable::parse('2026-08-28 12:11:00', 'UTC'),
            'raw-after-mutex-c',
        ),
    );
    $reconciler = pulseReconciler($fake);
    $mutex = app(SocialConnectionDeliveryMutex::class);
    $automaticClaim = $reconciler->claim(
        $automaticFixture['owner']->id,
        $automaticFixture['target']->id,
        'automatic-mutex-worker',
    );
    $automaticLock = $mutex->acquire($automaticFixture['connection']->id);
    $manualLock = $mutex->acquire($manualFixture['connection']->id);

    expect($automaticClaim)->not->toBeNull()
        ->and($automaticLock)->not->toBeNull()
        ->and($manualLock)->not->toBeNull();

    try {
        expect($reconciler->reconcile($automaticClaim))->toBeFalse()
            ->and($reconciler->synchronizeManually(
                $manualFixture['owner']->id,
                $manualFixture['target']->id,
                'manual-mutex-worker',
            ))->toBeFalse()
            ->and($fake->reads)->toBeEmpty();
    } finally {
        $automaticLock?->release();
        $manualLock?->release();
    }

    expect($automaticFixture['target']->fresh()->next_reconcile_at?->toDateTimeString())
        ->toBe('2026-08-28 12:01:00')
        ->and($automaticFixture['target']->fresh()->reconcile_attempts)->toBe(0)
        ->and($manualFixture['target']->fresh()->next_reconcile_at?->toDateTimeString())
        ->toBe('2026-08-28 12:01:00')
        ->and($manualFixture['target']->fresh()->reconcile_attempts)->toBe(0);

    foreach ([1, 5, 5] as $minutes) {
        $this->travel($minutes)->minutes();
        $reconciler->reconcileDueForTenant(
            $automaticFixture['owner']->id,
            'post-mutex-poll-worker',
        );
    }

    expect($fake->reads)->toHaveCount(3)
        ->and($automaticFixture['target']->fresh()->reconcile_attempts)->toBe(3)
        ->and($automaticFixture['target']->fresh()->next_reconcile_at)->toBeNull();
});

it('never reopens a capped target when a manual read meets mutex contention', function () {
    $fixture = pulseReconciliationFixture();
    $fixture['target']->fresh()->forceFill([
        'reconcile_attempts' => 3,
        'next_reconcile_at' => null,
    ])->save();
    $fake = new FakeSocialDeliveryStatusGateway(
        SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_SENT,
            CarbonImmutable::now('UTC'),
        ),
    );
    $reconciler = pulseReconciler($fake);
    $mutex = app(SocialConnectionDeliveryMutex::class);
    $lock = $mutex->acquire($fixture['connection']->id);

    expect($lock)->not->toBeNull();

    try {
        expect($reconciler->synchronizeManually(
            $fixture['owner']->id,
            $fixture['target']->id,
            'capped-mutex-operator',
        ))->toBeFalse();
    } finally {
        $lock?->release();
    }

    expect($fixture['target']->fresh()->next_reconcile_at)->toBeNull()
        ->and($fixture['target']->fresh()->reconcile_attempts)->toBe(3)
        ->and($reconciler->synchronizeManually(
            $fixture['owner']->id,
            $fixture['target']->id,
            'capped-after-mutex-operator',
        ))->toBeFalse()
        ->and($fixture['target']->fresh()->next_reconcile_at)->toBeNull()
        ->and($fixture['target']->fresh()->reconcile_attempts)->toBe(3)
        ->and($fake->reads)->toBeEmpty();
});

it('uses lease fencing so repeated and stale workers never read or apply twice', function () {
    $this->travelTo(Carbon::parse('2026-08-28 12:00:00', 'UTC'));
    $fixture = pulseReconciliationFixture();
    $fake = new FakeSocialDeliveryStatusGateway(
        SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_SENT,
            CarbonImmutable::now('UTC'),
        ),
    );
    $reconciler = pulseReconciler($fake);
    $staleClaim = $reconciler->claim(
        $fixture['owner']->id,
        $fixture['target']->id,
        'worker-a',
        false,
        1,
    );

    expect($staleClaim)->not->toBeNull()
        ->and($reconciler->claim(
            $fixture['owner']->id,
            $fixture['target']->id,
            'worker-b',
        ))->toBeNull();

    $this->travel(2)->seconds();
    $freshClaim = $reconciler->claim(
        $fixture['owner']->id,
        $fixture['target']->id,
        'worker-b',
    );

    expect($freshClaim)->not->toBeNull()
        ->and($freshClaim->claimVersion)->toBe($staleClaim->claimVersion + 1)
        ->and($reconciler->reconcile($staleClaim))->toBeFalse()
        ->and($fake->reads)->toBeEmpty()
        ->and($reconciler->reconcile($freshClaim))->toBeTrue()
        ->and($reconciler->reconcile($freshClaim))->toBeFalse()
        ->and($fake->reads)->toHaveCount(1)
        ->and($fixture['target']->fresh()->delivery_status)
        ->toBe(SocialPost::DELIVERY_STATUS_PUBLISHED)
        ->and($fixture['target']->fresh()->next_reconcile_at)->toBeNull();
});

it('counts a real read before the lease can expire and never bypasses its ceiling', function () {
    $this->travelTo(Carbon::parse('2026-08-28 12:00:00', 'UTC'));
    $fixture = pulseReconciliationFixture();
    $fixture['target']->fresh()->forceFill([
        'reconcile_attempts' => 2,
        'next_reconcile_at' => now(),
    ])->save();
    $fake = (new FakeSocialDeliveryStatusGateway(
        SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_UNKNOWN,
            CarbonImmutable::parse('2026-08-28 12:00:00', 'UTC'),
            'raw-after-expired-lease',
        ),
    ))->beforeEachRead(fn () => $this->travel(2)->seconds());
    $reconciler = pulseReconciler($fake);
    $claim = $reconciler->claim(
        $fixture['owner']->id,
        $fixture['target']->id,
        'slow-status-worker',
        false,
        1,
    );

    expect($claim)->not->toBeNull()
        ->and($reconciler->reconcile($claim))->toBeFalse()
        ->and($fake->reads)->toHaveCount(1)
        ->and($fixture['target']->fresh()->reconcile_attempts)->toBe(3)
        ->and($fixture['target']->fresh()->next_reconcile_at)->toBeNull()
        ->and($fixture['target']->fresh()->last_synced_at)->toBeNull()
        ->and($reconciler->synchronizeManually(
            $fixture['owner']->id,
            $fixture['target']->id,
            'after-slow-status-operator',
        ))->toBeFalse()
        ->and($fake->reads)->toHaveCount(1)
        ->and($fixture['target']->fresh()->reconcile_attempts)->toBe(3);
});

it('ignores an out of order observation without regressing local delivery state', function () {
    $this->travelTo(Carbon::parse('2026-08-28 12:00:00', 'UTC'));
    $fixture = pulseReconciliationFixture();
    $target = $fixture['target']->fresh();
    $target->forceFill([
        'provider_status' => 'newer-status',
        'last_synced_at' => Carbon::parse('2026-08-28 11:59:00', 'UTC'),
        'next_reconcile_at' => Carbon::parse('2026-08-28 12:05:00', 'UTC'),
    ])->save();
    $fake = new FakeSocialDeliveryStatusGateway(...collect([
        '2026-08-28 11:59:00',
        '2026-08-28 11:58:59',
        '2026-08-28 11:58:00',
    ])->map(fn (string $observedAt): SocialDeliveryStatusResultData => SocialDeliveryStatusResultData::observed(
        SocialDeliveryStatusResultData::STATUS_SENT,
        CarbonImmutable::parse($observedAt, 'UTC'),
        'older-status',
    ))->all());
    $reconciler = pulseReconciler($fake);
    $claim = $reconciler->claim(
        $fixture['owner']->id,
        $fixture['target']->id,
        'out-of-order-worker',
        true,
    );

    expect($claim)->not->toBeNull()
        ->and($reconciler->reconcile($claim))->toBeFalse()
        ->and($fake->reads)->toHaveCount(1);

    $target->refresh();

    expect($target->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN)
        ->and($target->sync_status)->toBe(SocialPost::SYNC_STATUS_ERROR)
        ->and($target->provider_status)->toBe('newer-status')
        ->and($target->last_synced_at?->toDateTimeString())->toBe('2026-08-28 11:59:00')
        ->and($target->next_reconcile_at?->toDateTimeString())->toBe('2026-08-28 12:05:00')
        ->and($target->reconcile_claim_token)->toBeNull()
        ->and($fixture['outbox']->fresh()->aggregate_repaired_at)->toBeNull();

    $this->travel(5)->minutes();
    expect($reconciler->reconcileDueForTenant(
        $fixture['owner']->id,
        'out-of-order-worker',
    ))->toBe(['claimed' => 1, 'reconciled' => 0, 'not_applied' => 1])
        ->and($target->fresh()->next_reconcile_at?->toDateTimeString())
        ->toBe('2026-08-28 12:10:00');

    $this->travel(5)->minutes();
    expect($reconciler->reconcileDueForTenant(
        $fixture['owner']->id,
        'out-of-order-worker',
    ))->toBe(['claimed' => 1, 'reconciled' => 0, 'not_applied' => 1])
        ->and($target->fresh()->next_reconcile_at)->toBeNull()
        ->and($fake->reads)->toHaveCount(3);
});

it('never reads an unknown target without a remote identifier', function () {
    $fixture = pulseReconciliationFixture(false);
    $fake = new FakeSocialDeliveryStatusGateway(
        SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_SENT,
            CarbonImmutable::now('UTC'),
        ),
    );
    $reconciler = pulseReconciler($fake);

    expect($reconciler->reconcileDueForTenant(
        $fixture['owner']->id,
        'missing-id-poll-worker',
    ))->toBe(['claimed' => 0, 'reconciled' => 0, 'not_applied' => 0])
        ->and($reconciler->claim(
            $fixture['owner']->id,
            $fixture['target']->id,
            'missing-id-worker',
        ))->toBeNull()
        ->and($reconciler->synchronizeManually(
            $fixture['owner']->id,
            $fixture['target']->id,
            'missing-id-manual',
        ))->toBeFalse()
        ->and($fake->reads)->toBeEmpty()
        ->and($fixture['target']->fresh()->delivery_status)
        ->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN)
        ->and($fixture['target']->fresh()->next_reconcile_at)->toBeNull()
        ->and($fixture['target']->fresh()->provider_error_code)
        ->toBe('remote_identifier_missing');
});

it('allows assigning a remote identity once and never remaps it', function () {
    $fixture = pulseReconciliationFixture(false);
    $target = $fixture['target']->fresh();

    $target->provider_post_id = 'first-remote-identity';
    $target->save();

    expect($target->fresh()->provider_post_id)->toBe('first-remote-identity');

    $target->provider_post_id = 'different-remote-identity';
    expect(fn () => $target->save())
        ->toThrow(LogicException::class, 'cannot be changed after it is assigned');

    $target->refresh()->provider_post_id = null;
    expect(fn () => $target->save())
        ->toThrow(LogicException::class, 'cannot be changed after it is assigned');
});

it('stops unknown polling after three deterministic observations', function () {
    $this->travelTo(Carbon::parse('2026-08-28 12:00:00', 'UTC'));
    $fixture = pulseReconciliationFixture();
    $fake = new FakeSocialDeliveryStatusGateway(
        SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_UNKNOWN,
            CarbonImmutable::parse('2026-08-28 12:00:00', 'UTC'),
            'raw-unknown-a',
        ),
        SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_UNKNOWN,
            CarbonImmutable::parse('2026-08-28 12:05:00', 'UTC'),
            'raw-unknown-b',
        ),
        SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_UNKNOWN,
            CarbonImmutable::parse('2026-08-28 12:10:00', 'UTC'),
            'raw-unknown-c',
        ),
    );
    $reconciler = pulseReconciler($fake);

    expect($reconciler->synchronizeManually(
        $fixture['owner']->id,
        $fixture['target']->id,
        'operator-sync',
    ))->toBeTrue()
        ->and($fixture['target']->fresh()->reconcile_attempts)->toBe(1)
        ->and($fixture['target']->fresh()->next_reconcile_at?->toDateTimeString())
        ->toBe('2026-08-28 12:05:00');

    $this->travel(5)->minutes();
    expect($reconciler->reconcileDueForTenant(
        $fixture['owner']->id,
        'poll-worker',
    ))->toBe(['claimed' => 1, 'reconciled' => 1, 'not_applied' => 0])
        ->and($fixture['target']->fresh()->reconcile_attempts)->toBe(2)
        ->and($fixture['target']->fresh()->next_reconcile_at?->toDateTimeString())
        ->toBe('2026-08-28 12:10:00');

    $this->travel(5)->minutes();
    expect($reconciler->reconcileDueForTenant(
        $fixture['owner']->id,
        'poll-worker',
    ))->toBe(['claimed' => 1, 'reconciled' => 1, 'not_applied' => 0])
        ->and($fixture['target']->fresh()->reconcile_attempts)->toBe(3)
        ->and($fixture['target']->fresh()->next_reconcile_at)->toBeNull()
        ->and($reconciler->synchronizeManually(
            $fixture['owner']->id,
            $fixture['target']->id,
            'capped-unknown-operator',
        ))->toBeFalse()
        ->and($fake->reads)->toHaveCount(3);
});

it('counts normalized sending observations despite changing raw statuses and stops at five', function () {
    $this->travelTo(Carbon::parse('2026-08-28 12:00:00', 'UTC'));
    $fixture = pulseReconciliationFixture();
    $results = collect(range(0, 4))->map(
        fn (int $index): SocialDeliveryStatusResultData => SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_SENDING,
            CarbonImmutable::parse('2026-08-28 12:00:00', 'UTC')->addMinutes($index * 2),
            'raw-sending-'.$index,
        ),
    )->all();
    $fake = new FakeSocialDeliveryStatusGateway(...$results);
    $reconciler = pulseReconciler($fake);

    expect($reconciler->synchronizeManually(
        $fixture['owner']->id,
        $fixture['target']->id,
        'sending-operator',
    ))->toBeTrue()
        ->and($fixture['target']->fresh()->reconcile_attempts)->toBe(1)
        ->and($fixture['target']->fresh()->next_reconcile_at?->toDateTimeString())
        ->toBe('2026-08-28 12:02:00');

    foreach (range(2, 5) as $attempt) {
        $this->travel(2)->minutes();

        expect($reconciler->reconcileDueForTenant(
            $fixture['owner']->id,
            'sending-poll-worker',
        ))->toBe(['claimed' => 1, 'reconciled' => 1, 'not_applied' => 0])
            ->and($fixture['target']->fresh()->reconcile_attempts)->toBe($attempt);
    }

    expect($fixture['target']->fresh()->next_reconcile_at)->toBeNull()
        ->and($fixture['target']->fresh()->delivery_status)->toBe('sending')
        ->and($reconciler->synchronizeManually(
            $fixture['owner']->id,
            $fixture['target']->id,
            'capped-sending-operator',
        ))->toBeFalse()
        ->and($fake->reads)->toHaveCount(5);
});

it('rejects non monotone sending regressions without changing remote truth', function (
    string $observedStatus,
) {
    $fixture = pulseReconciliationFixture();
    $target = $fixture['target']->fresh();
    $target->forceFill([
        'status' => SocialPostTarget::STATUS_PUBLISHING,
        'delivery_status' => 'sending',
        'sync_status' => SocialPost::SYNC_STATUS_SYNCED,
        'provider_status' => 'raw-sending-current',
        'next_reconcile_at' => now(),
    ])->save();
    $fake = new FakeSocialDeliveryStatusGateway(
        SocialDeliveryStatusResultData::observed(
            $observedStatus,
            CarbonImmutable::now('UTC')->addSecond(),
            'raw-regression',
            $observedStatus === SocialDeliveryStatusResultData::STATUS_SCHEDULED
                ? CarbonImmutable::now('UTC')->addHour()
                : null,
        ),
    );
    $reconciler = pulseReconciler($fake);

    expect($reconciler->synchronizeManually(
        $fixture['owner']->id,
        $fixture['target']->id,
        'monotonicity-operator',
    ))->toBeFalse()
        ->and($fake->reads)->toHaveCount(1);

    $target->refresh();

    expect($target->delivery_status)->toBe('sending')
        ->and($target->provider_status)->toBe('raw-sending-current')
        ->and($target->sync_status)->toBe(SocialPost::SYNC_STATUS_ERROR)
        ->and($target->next_reconcile_at)->toBeNull()
        ->and($target->provider_error_code)->toBe('non_monotone_remote_status')
        ->and($fixture['outbox']->fresh()->aggregate_repaired_at)->toBeNull();
})->with([
    'scheduled regression' => [SocialDeliveryStatusResultData::STATUS_SCHEDULED],
    'draft regression' => [SocialDeliveryStatusResultData::STATUS_DRAFT],
]);

it('preserves sending certainty across unknown then scheduled observations', function () {
    $fixture = pulseReconciliationFixture();
    $fixture['target']->fresh()->forceFill([
        'status' => SocialPostTarget::STATUS_PUBLISHING,
        'delivery_status' => 'sending',
        'sync_status' => SocialPost::SYNC_STATUS_SYNCED,
        'provider_status' => 'raw-sending-certain',
        'next_reconcile_at' => now(),
    ])->save();
    $fake = new FakeSocialDeliveryStatusGateway(
        SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_UNKNOWN,
            CarbonImmutable::now('UTC')->addSecond(),
            'raw-unknown-after-sending',
        ),
        SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_SCHEDULED,
            CarbonImmutable::now('UTC')->addSeconds(2),
            'raw-scheduled-after-unknown',
            CarbonImmutable::now('UTC')->addHour(),
        ),
    );
    $reconciler = pulseReconciler($fake);

    expect($reconciler->synchronizeManually(
        $fixture['owner']->id,
        $fixture['target']->id,
        'sending-unknown-operator',
    ))->toBeFalse()
        ->and($fixture['target']->fresh()->delivery_status)->toBe('sending')
        ->and($fixture['target']->fresh()->provider_status)->toBe('raw-sending-certain')
        ->and($fixture['target']->fresh()->next_reconcile_at)->toBeNull()
        ->and($reconciler->synchronizeManually(
            $fixture['owner']->id,
            $fixture['target']->id,
            'sending-scheduled-operator',
        ))->toBeFalse()
        ->and($fixture['target']->fresh()->delivery_status)->toBe('sending')
        ->and($fixture['target']->fresh()->provider_status)->toBe('raw-sending-certain')
        ->and($fake->reads)->toHaveCount(2);
});

it('moves an overdue scheduled delivery to operator review after the grace window', function () {
    $this->travelTo(Carbon::parse('2026-08-28 12:00:00', 'UTC'));
    $fixture = pulseReconciliationFixture();
    $fake = new FakeSocialDeliveryStatusGateway(
        SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_SCHEDULED,
            CarbonImmutable::now('UTC'),
            'raw-scheduled',
            CarbonImmutable::now('UTC')->subMinutes(31),
        ),
    );
    $reconciler = pulseReconciler($fake);

    expect($reconciler->synchronizeManually(
        $fixture['owner']->id,
        $fixture['target']->id,
        'overdue-operator',
    ))->toBeTrue();

    $target = $fixture['target']->fresh();

    expect($target->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_SCHEDULED)
        ->and($target->sync_status)->toBe(SocialPost::SYNC_STATUS_ERROR)
        ->and($target->next_reconcile_at)->toBeNull()
        ->and($target->provider_error_code)->toBe('scheduled_delivery_overdue')
        ->and(app(SocialDeliveryHealthService::class)
            ->summaryForTenant($fixture['owner']->id)['reconciliation']['operator_review'])
        ->toBe(1);
});

it('moves a remote schedule without a delivery time to operator review', function () {
    $fixture = pulseReconciliationFixture();
    $fake = new FakeSocialDeliveryStatusGateway(
        SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_SCHEDULED,
            CarbonImmutable::now('UTC'),
            'raw-scheduled-without-time',
        ),
    );

    expect(pulseReconciler($fake)->synchronizeManually(
        $fixture['owner']->id,
        $fixture['target']->id,
        'missing-schedule-operator',
    ))->toBeTrue()
        ->and($fixture['target']->fresh()->provider_error_code)
        ->toBe('remote_schedule_missing')
        ->and($fixture['target']->fresh()->next_reconcile_at)->toBeNull()
        ->and(app(SocialDeliveryHealthService::class)
            ->summaryForTenant($fixture['owner']->id)['reconciliation']['operator_review'])
        ->toBe(1);
});

it('stops repeated status read failures on the cadence ceiling', function () {
    $this->travelTo(Carbon::parse('2026-08-28 12:00:00', 'UTC'));
    $fixture = pulseReconciliationFixture();
    $fake = new FakeSocialDeliveryStatusGateway;
    $reconciler = pulseReconciler($fake);

    foreach ([0, 5, 5] as $minutes) {
        $this->travel($minutes)->minutes();
        $reconciler->reconcileDueForTenant(
            $fixture['owner']->id,
            'failing-status-read-worker',
        );
    }

    expect($fake->reads)->toHaveCount(3)
        ->and($fixture['target']->fresh()->reconcile_attempts)->toBe(3)
        ->and($fixture['target']->fresh()->provider_error_code)->toBe('status_read_failed')
        ->and($fixture['target']->fresh()->next_reconcile_at)->toBeNull()
        ->and($fixture['outbox']->fresh()->aggregate_repaired_at)->toBeNull()
        ->and(app(SocialDeliveryHealthService::class)
            ->summaryForTenant($fixture['owner']->id)['reconciliation']['operator_review'])
        ->toBe(1);
});

it('rejects a remote result when a local decision changes during the read', function () {
    $fixture = pulseReconciliationFixture();
    $sent = SocialDeliveryStatusResultData::observed(
        SocialDeliveryStatusResultData::STATUS_SENT,
        CarbonImmutable::now('UTC'),
    );
    $gateway = new class($fixture['target'], $sent) implements SocialDeliveryStatusGatewayInterface
    {
        public int $reads = 0;

        public function __construct(
            private readonly SocialPostTarget $target,
            private readonly SocialDeliveryStatusResultData $result,
        ) {}

        public function readStatus(ReadSocialDeliveryStatusData $delivery): SocialDeliveryStatusResultData
        {
            $this->reads++;
            $this->target->fresh()->forceFill([
                'status' => SocialPostTarget::STATUS_CANCELED,
                'delivery_status' => SocialPost::DELIVERY_STATUS_CANCELED,
                'sync_status' => SocialPost::SYNC_STATUS_SYNCED,
                'next_reconcile_at' => null,
            ])->save();

            return $this->result;
        }
    };
    $reconciler = pulseReconciler($gateway);
    $claim = $reconciler->claim(
        $fixture['owner']->id,
        $fixture['target']->id,
        'race-worker',
    );

    expect($claim)->not->toBeNull()
        ->and($reconciler->reconcile($claim))->toBeFalse()
        ->and($gateway->reads)->toBe(1)
        ->and($fixture['target']->fresh()->status)->toBe(SocialPostTarget::STATUS_CANCELED)
        ->and($fixture['target']->fresh()->delivery_status)
        ->toBe(SocialPost::DELIVERY_STATUS_CANCELED);
});

it('uses the manual read path and exposes only normalized delivery axes', function () {
    $fixture = pulseReconciliationFixture();
    $fake = new FakeSocialDeliveryStatusGateway(
        SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_SENT,
            CarbonImmutable::now('UTC'),
            'complete',
        ),
    );
    $reconciler = pulseReconciler($fake);

    expect($reconciler->synchronizeManually(
        $fixture['owner']->id,
        $fixture['target']->id,
        'operator-sync',
    ))->toBeTrue()
        ->and(method_exists($reconciler, 'createPost'))->toBeFalse()
        ->and(method_exists($fake, 'createPost'))->toBeFalse();

    $payload = app(SocialPostService::class)->payload($fixture['post']->fresh());
    $targetPayload = $payload['targets'][0];

    expect($payload)->toMatchArray([
        'editorial_status' => SocialPost::EDITORIAL_STATUS_APPROVED,
        'delivery_status' => SocialPost::DELIVERY_STATUS_PUBLISHED,
        'sync_status' => SocialPost::SYNC_STATUS_SYNCED,
    ])->and($targetPayload)->toMatchArray([
        'editorial_status' => SocialPost::EDITORIAL_STATUS_APPROVED,
        'delivery_status' => SocialPost::DELIVERY_STATUS_PUBLISHED,
        'sync_status' => SocialPost::SYNC_STATUS_SYNCED,
    ])->and($targetPayload)->not->toHaveKeys([
        'provider_post_id',
        'provider_status',
        'provider_error_code',
        'provider_error_message',
    ]);
});

it('resolves an ambiguous outbox without erasing its historical status', function () {
    $this->travelTo(Carbon::parse('2026-08-28 12:00:00', 'UTC'));
    $fixture = pulseReconciliationFixture();
    $outbox = pulseReconciliationOutbox($fixture);
    $outbox->forceFill([
        'status' => SocialDeliveryOutbox::STATUS_DEAD,
        'request_started_at' => now()->subMinute(),
        'processed_at' => now(),
    ])->save();
    $recovery = pulseReconciliationOutbox($fixture, 1, $outbox);
    $recovery->forceFill([
        'status' => SocialDeliveryOutbox::STATUS_UNKNOWN,
        'request_started_at' => now()->subMinute(),
        'processed_at' => now(),
        'provider_post_id' => $fixture['target']->provider_post_id,
    ])->save();
    $health = app(SocialDeliveryHealthService::class);

    expect($health->summaryForTenant(
        $fixture['owner']->id,
    )['active_status_counts'])->toBe([
        SocialDeliveryOutbox::STATUS_UNKNOWN => 1,
        SocialDeliveryOutbox::STATUS_DEAD => 0,
        SocialDeliveryOutbox::STATUS_SUSPENDED => 0,
    ]);

    $fake = new FakeSocialDeliveryStatusGateway(
        SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_SENT,
            CarbonImmutable::now('UTC'),
            'raw-complete',
        ),
    );

    expect(pulseReconciler($fake)->synchronizeManually(
        $fixture['owner']->id,
        $fixture['target']->id,
        'outbox-resolution-operator',
    ))->toBeTrue();

    $outbox->refresh();
    $recovery->refresh();

    expect($outbox->status)->toBe(SocialDeliveryOutbox::STATUS_DEAD)
        ->and($outbox->reconciliation_resolution)->toBeNull()
        ->and($recovery->status)->toBe(SocialDeliveryOutbox::STATUS_UNKNOWN)
        ->and($recovery->reconciliation_resolution)
        ->toBe(SocialDeliveryOutbox::RECONCILIATION_RESOLUTION_SENT)
        ->and($recovery->reconciliation_resolution_source)
        ->toBe(SocialDeliveryOutbox::RECONCILIATION_SOURCE_STATUS_READ)
        ->and($recovery->reconciliation_resolved_at)->not->toBeNull()
        ->and($recovery->reconciliation_observed_at)->not->toBeNull()
        ->and($health->summaryForTenant(
            $fixture['owner']->id,
        )['status_counts'][SocialDeliveryOutbox::STATUS_UNKNOWN])->toBe(1)
        ->and($health->summaryForTenant(
            $fixture['owner']->id,
        )['active_status_counts'][SocialDeliveryOutbox::STATUS_UNKNOWN])->toBe(0);

    $recovery->reconciliation_resolution = SocialDeliveryOutbox::RECONCILIATION_RESOLUTION_ERROR;

    expect(fn () => $recovery->save())
        ->toThrow(LogicException::class, 'cannot be changed after it is recorded')
        ->and(fn () => pulseReconciliationOutbox($fixture, 2, $recovery->fresh()))
        ->toThrow(LogicException::class, 'superseded Pulse delivery outbox entry is invalid');
});

it('keeps a certain remote error active and forbids remapping the same target', function () {
    Queue::fake();
    $fixture = pulseReconciliationFixture();
    $outbox = pulseReconciliationOutbox($fixture);
    $outbox->forceFill([
        'status' => SocialDeliveryOutbox::STATUS_UNKNOWN,
        'request_started_at' => now()->subMinute(),
        'processed_at' => now(),
        'provider_post_id' => $fixture['target']->provider_post_id,
    ])->save();

    expect(fn () => pulseReconciliationOutbox($fixture, 1, $outbox))
        ->toThrow(LogicException::class, 'superseded Pulse delivery outbox entry is invalid');

    $fake = new FakeSocialDeliveryStatusGateway(
        SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_ERROR,
            CarbonImmutable::now('UTC')->addSecond(),
            'raw-certain-error',
            errorCode: 'remote_delivery_failed',
            errorMessage: 'The remote delivery is definitively failed.',
        ),
    );

    expect(pulseReconciler($fake)->synchronizeManually(
        $fixture['owner']->id,
        $fixture['target']->id,
        'certain-error-operator',
    ))->toBeTrue();

    $outbox->refresh();
    $health = app(SocialDeliveryHealthService::class);

    expect($outbox->reconciliation_resolution)
        ->toBe(SocialDeliveryOutbox::RECONCILIATION_RESOLUTION_ERROR)
        ->and($health->summaryForTenant(
            $fixture['owner']->id,
        )['active_status_counts'])->toBe([
            SocialDeliveryOutbox::STATUS_UNKNOWN => 0,
            SocialDeliveryOutbox::STATUS_DEAD => 1,
            SocialDeliveryOutbox::STATUS_SUSPENDED => 0,
        ]);

    expect(fn () => pulseReconciliationOutbox($fixture, 1, $outbox))
        ->toThrow(LogicException::class, 'superseded Pulse delivery outbox entry is invalid')
        ->and(fn () => app(SocialPublishingService::class)->publishNow(
            $fixture['owner'],
            $fixture['owner'],
            $fixture['post']->fresh(),
        ))->toThrow(
            ValidationException::class,
            'This Pulse publication has no failed target that can be retried safely.',
        )
        ->and(SocialDeliveryOutbox::query()
            ->where('supersedes_outbox_id', $outbox->id)
            ->count())->toBe(0)
        ->and($health->summaryForTenant(
            $fixture['owner']->id,
        )['active_status_counts'][SocialDeliveryOutbox::STATUS_DEAD])->toBe(1);

    Queue::assertNotPushed(ProcessSocialDeliveryOutboxJob::class);
});

it('never retries a resolved dead outbox', function (string $resolution, int $activeDead) {
    Queue::fake();
    $fixture = pulseReconciliationFixture();
    $outbox = pulseReconciliationOutbox($fixture);
    $observedAt = now();
    $outbox->forceFill([
        'status' => SocialDeliveryOutbox::STATUS_DEAD,
        'processed_at' => $observedAt,
        'provider_post_id' => $fixture['target']->provider_post_id,
        'reconciliation_resolved_at' => $observedAt,
        'reconciliation_observed_at' => $observedAt,
        'reconciliation_resolution' => $resolution,
        'reconciliation_resolution_source' => SocialDeliveryOutbox::RECONCILIATION_SOURCE_STATUS_READ,
    ])->save();
    $fixture['target']->fresh()->forceFill([
        'status' => SocialPostTarget::STATUS_FAILED,
        'delivery_status' => SocialPost::DELIVERY_STATUS_FAILED,
        'sync_status' => SocialPost::SYNC_STATUS_SYNCED,
        'last_synced_at' => $observedAt,
        'next_reconcile_at' => null,
    ])->save();
    $fixture['post']->fresh()->forceFill([
        'status' => SocialPost::STATUS_FAILED,
        'delivery_status' => SocialPost::DELIVERY_STATUS_FAILED,
        'sync_status' => SocialPost::SYNC_STATUS_SYNCED,
    ])->save();

    expect(fn () => pulseReconciliationOutbox($fixture, 1, $outbox))
        ->toThrow(LogicException::class, 'superseded Pulse delivery outbox entry is invalid')
        ->and(fn () => app(SocialPublishingService::class)->publishNow(
            $fixture['owner'],
            $fixture['owner'],
            $fixture['post']->fresh(),
        ))->toThrow(
            ValidationException::class,
            'This Pulse publication has no failed target that can be retried safely.',
        )
        ->and(SocialDeliveryOutbox::query()
            ->where('supersedes_outbox_id', $outbox->id)
            ->count())->toBe(0)
        ->and(app(SocialDeliveryHealthService::class)
            ->summaryForTenant($fixture['owner']->id)['active_status_counts'][SocialDeliveryOutbox::STATUS_DEAD])
        ->toBe($activeDead);

    Queue::assertNotPushed(ProcessSocialDeliveryOutboxJob::class);
})->with([
    'remote sent' => [SocialDeliveryOutbox::RECONCILIATION_RESOLUTION_SENT, 0],
    'remote error on historical dead' => [SocialDeliveryOutbox::RECONCILIATION_RESOLUTION_ERROR, 1],
]);

it('fails closed when a dead outbox has a corrupted source-only resolution', function () {
    Queue::fake();
    $fixture = pulseReconciliationFixture();
    $outbox = pulseReconciliationOutbox($fixture);
    $outbox->forceFill([
        'status' => SocialDeliveryOutbox::STATUS_DEAD,
        'processed_at' => now(),
    ])->save();
    DB::table('social_delivery_outbox')
        ->where('id', $outbox->id)
        ->update([
            'reconciliation_resolution_source' => SocialDeliveryOutbox::RECONCILIATION_SOURCE_STATUS_READ,
        ]);
    $fixture['target']->fresh()->forceFill([
        'status' => SocialPostTarget::STATUS_FAILED,
        'delivery_status' => SocialPost::DELIVERY_STATUS_FAILED,
        'sync_status' => SocialPost::SYNC_STATUS_SYNCED,
        'next_reconcile_at' => null,
    ])->save();
    $fixture['post']->fresh()->forceFill([
        'status' => SocialPost::STATUS_FAILED,
        'delivery_status' => SocialPost::DELIVERY_STATUS_FAILED,
        'sync_status' => SocialPost::SYNC_STATUS_SYNCED,
    ])->save();

    expect(fn () => pulseReconciliationOutbox($fixture, 1, $outbox))
        ->toThrow(LogicException::class, 'superseded Pulse delivery outbox entry is invalid')
        ->and(fn () => app(SocialPublishingService::class)->publishNow(
            $fixture['owner'],
            $fixture['owner'],
            $fixture['post']->fresh(),
        ))->toThrow(
            ValidationException::class,
            'This Pulse publication has no failed target that can be retried safely.',
        )
        ->and(SocialDeliveryOutbox::query()
            ->where('supersedes_outbox_id', $outbox->id)
            ->count())->toBe(0);

    Queue::assertNotPushed(ProcessSocialDeliveryOutboxJob::class);
});

it('rearms an exact preflight outbox before a failed aggregate refresh can lose repair', function () {
    $fixture = pulseReconciliationFixture(false);
    $failAggregateRefresh = true;
    SocialPost::saving(function (SocialPost $post) use (&$failAggregateRefresh, $fixture): void {
        if ($failAggregateRefresh && (int) $post->id === (int) $fixture['post']->id) {
            throw new RuntimeException('Simulated preflight aggregate refresh crash.');
        }
    });
    $fake = new FakeSocialDeliveryStatusGateway;

    expect(fn () => pulseReconciler($fake)->synchronizeManually(
        $fixture['owner']->id,
        $fixture['target']->id,
        'missing-identity-crash-operator',
    ))->toThrow(RuntimeException::class, 'Simulated preflight aggregate refresh crash');

    $failAggregateRefresh = false;

    expect($fake->reads)->toBeEmpty()
        ->and($fixture['target']->fresh()->provider_error_code)
        ->toBe('remote_identifier_missing')
        ->and($fixture['outbox']->fresh()->aggregate_repaired_at)->toBeNull();

    $summary = app(SocialPublishingService::class)->maintainDeliveryOutbox();

    expect($summary['aggregates_repaired'])->toBe(1)
        ->and($fixture['outbox']->fresh()->aggregate_repaired_at)->not->toBeNull()
        ->and($fixture['post']->fresh()->delivery_status)
        ->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN)
        ->and($fixture['post']->fresh()->sync_status)->toBe(SocialPost::SYNC_STATUS_ERROR);
});

it('rolls back an outboxless target mutation when its aggregate cannot commit atomically', function () {
    $fixture = pulseReconciliationFixture();
    $fixture['outbox']->delete();
    $fixture['target']->fresh()->forceFill([
        'status' => SocialPostTarget::STATUS_SCHEDULED,
        'delivery_status' => SocialPost::DELIVERY_STATUS_SCHEDULED,
        'sync_status' => SocialPost::SYNC_STATUS_SYNCED,
        'remote_scheduled_for' => now()->addHour(),
        'next_reconcile_at' => now(),
        'provider_error_code' => null,
        'provider_error_message' => null,
    ])->save();
    $fixture['post']->fresh()->forceFill([
        'status' => SocialPost::STATUS_SCHEDULED,
        'delivery_status' => SocialPost::DELIVERY_STATUS_SCHEDULED,
        'sync_status' => SocialPost::SYNC_STATUS_SYNCED,
    ])->save();
    $failAggregateRefresh = true;
    SocialPost::saving(function (SocialPost $post) use (&$failAggregateRefresh, $fixture): void {
        if ($failAggregateRefresh && (int) $post->id === (int) $fixture['post']->id) {
            throw new RuntimeException('Simulated outboxless aggregate crash.');
        }
    });
    $fake = new FakeSocialDeliveryStatusGateway;
    $reconciler = pulseReconciler($fake);

    expect(fn () => $reconciler->synchronizeManually(
        $fixture['owner']->id,
        $fixture['target']->id,
        'outboxless-crash-operator',
    ))->toThrow(RuntimeException::class, 'Simulated outboxless aggregate crash');

    expect($fixture['target']->fresh()->sync_status)->toBe(SocialPost::SYNC_STATUS_SYNCED)
        ->and($fixture['target']->fresh()->next_reconcile_at)->not->toBeNull()
        ->and($fixture['target']->fresh()->provider_error_code)->toBeNull()
        ->and($fixture['post']->fresh()->sync_status)->toBe(SocialPost::SYNC_STATUS_SYNCED);

    $failAggregateRefresh = false;

    expect($reconciler->synchronizeManually(
        $fixture['owner']->id,
        $fixture['target']->id,
        'outboxless-retry-operator',
    ))->toBeFalse()
        ->and($fake->reads)->toBeEmpty()
        ->and($fixture['target']->fresh()->sync_status)->toBe(SocialPost::SYNC_STATUS_ERROR)
        ->and($fixture['target']->fresh()->next_reconcile_at)->toBeNull()
        ->and($fixture['target']->fresh()->provider_error_code)->toBe('missing_delivery_outbox')
        ->and($fixture['post']->fresh()->delivery_status)
        ->toBe(SocialPost::DELIVERY_STATUS_SCHEDULED)
        ->and($fixture['post']->fresh()->sync_status)->toBe(SocialPost::SYNC_STATUS_ERROR);
});

it('rearms aggregate repair before a resolved outbox commit survives refresh failure', function () {
    $fixture = pulseReconciliationFixture();
    $outbox = pulseReconciliationOutbox($fixture);
    $outbox->forceFill([
        'status' => SocialDeliveryOutbox::STATUS_UNKNOWN,
        'request_started_at' => now()->subMinute(),
        'processed_at' => now(),
        'provider_post_id' => $fixture['target']->provider_post_id,
        'aggregate_repaired_at' => now(),
    ])->save();
    $fixture['post']->fresh()->forceFill([
        'status' => SocialPost::STATUS_FAILED,
        'delivery_status' => SocialPost::DELIVERY_STATUS_UNKNOWN,
        'sync_status' => SocialPost::SYNC_STATUS_ERROR,
    ])->save();
    $failAggregateRefresh = true;
    SocialPost::saving(function (SocialPost $post) use (&$failAggregateRefresh, $fixture): void {
        if ($failAggregateRefresh && (int) $post->id === (int) $fixture['post']->id) {
            throw new RuntimeException('Simulated aggregate refresh crash.');
        }
    });
    $fake = new FakeSocialDeliveryStatusGateway(
        SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_SENT,
            CarbonImmutable::now('UTC')->addSecond(),
            'raw-sent-before-crash',
        ),
    );

    expect(fn () => pulseReconciler($fake)->synchronizeManually(
        $fixture['owner']->id,
        $fixture['target']->id,
        'crash-window-operator',
    ))->toThrow(RuntimeException::class, 'Simulated aggregate refresh crash');

    $failAggregateRefresh = false;
    $resolvedOutbox = $outbox->fresh();

    expect($resolvedOutbox->reconciliation_resolution)
        ->toBe(SocialDeliveryOutbox::RECONCILIATION_RESOLUTION_SENT)
        ->and($resolvedOutbox->aggregate_repaired_at)->toBeNull()
        ->and($fixture['target']->fresh()->delivery_status)
        ->toBe(SocialPost::DELIVERY_STATUS_PUBLISHED)
        ->and($fixture['post']->fresh()->delivery_status)
        ->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN);

    $summary = app(SocialPublishingService::class)->maintainDeliveryOutbox();

    expect($summary['aggregates_repaired'])->toBe(1)
        ->and($outbox->fresh()->aggregate_repaired_at)->not->toBeNull()
        ->and($fixture['post']->fresh()->delivery_status)
        ->toBe(SocialPost::DELIVERY_STATUS_PUBLISHED);
});

it('rearms the current outbox for every applied transition before aggregate refresh', function (
    string $outboxStatus,
    string $initialLegacyStatus,
    string $initialDeliveryStatus,
    string $initialSyncStatus,
    string $observedStatus,
    string $expectedDeliveryStatus,
) {
    $fixture = pulseReconciliationFixture();
    $outbox = pulseReconciliationOutbox($fixture);
    $outbox->forceFill([
        'status' => $outboxStatus,
        'processed_at' => now(),
        'provider_post_id' => $fixture['target']->provider_post_id,
        'aggregate_repaired_at' => now(),
    ])->save();
    $fixture['target']->fresh()->forceFill([
        'status' => $initialLegacyStatus,
        'delivery_status' => $initialDeliveryStatus,
        'sync_status' => $initialSyncStatus,
        'remote_scheduled_for' => $initialDeliveryStatus === SocialPost::DELIVERY_STATUS_SCHEDULED
            ? now()->addHour()
            : null,
        'next_reconcile_at' => now(),
    ])->save();
    $fixture['post']->fresh()->forceFill([
        'status' => $initialLegacyStatus,
        'delivery_status' => $initialDeliveryStatus,
        'sync_status' => $initialSyncStatus,
    ])->save();
    $failAggregateRefresh = true;
    SocialPost::saving(function (SocialPost $post) use (&$failAggregateRefresh, $fixture): void {
        if ($failAggregateRefresh && (int) $post->id === (int) $fixture['post']->id) {
            throw new RuntimeException('Simulated transition aggregate refresh crash.');
        }
    });
    $fake = new FakeSocialDeliveryStatusGateway(
        SocialDeliveryStatusResultData::observed(
            $observedStatus,
            CarbonImmutable::now('UTC')->addSecond(),
            'raw-transition-before-crash',
            $observedStatus === SocialDeliveryStatusResultData::STATUS_SCHEDULED
                ? CarbonImmutable::now('UTC')->addHour()
                : null,
        ),
    );

    expect(fn () => pulseReconciler($fake)->synchronizeManually(
        $fixture['owner']->id,
        $fixture['target']->id,
        'transition-crash-window-operator',
    ))->toThrow(RuntimeException::class, 'Simulated transition aggregate refresh crash');

    $failAggregateRefresh = false;
    $rearmedOutbox = $outbox->fresh();

    expect($rearmedOutbox->status)->toBe($outboxStatus)
        ->and($rearmedOutbox->aggregate_repaired_at)->toBeNull()
        ->and($rearmedOutbox->reconciliation_resolution)->toBeNull()
        ->and($fixture['target']->fresh()->delivery_status)->toBe($expectedDeliveryStatus)
        ->and($fixture['post']->fresh()->delivery_status)->toBe($initialDeliveryStatus);

    $summary = app(SocialPublishingService::class)->maintainDeliveryOutbox();

    expect($summary['aggregates_repaired'])->toBe(1)
        ->and($outbox->fresh()->aggregate_repaired_at)->not->toBeNull()
        ->and($fixture['post']->fresh()->delivery_status)->toBe($expectedDeliveryStatus);
})->with([
    'completed delivery becomes sent' => [
        SocialDeliveryOutbox::STATUS_COMPLETED,
        SocialPostTarget::STATUS_SCHEDULED,
        SocialPost::DELIVERY_STATUS_SCHEDULED,
        SocialPost::SYNC_STATUS_SYNCED,
        SocialDeliveryStatusResultData::STATUS_SENT,
        SocialPost::DELIVERY_STATUS_PUBLISHED,
    ],
    'unknown delivery becomes scheduled without being resolved' => [
        SocialDeliveryOutbox::STATUS_UNKNOWN,
        SocialPostTarget::STATUS_FAILED,
        SocialPost::DELIVERY_STATUS_UNKNOWN,
        SocialPost::SYNC_STATUS_ERROR,
        SocialDeliveryStatusResultData::STATUS_SCHEDULED,
        SocialPost::DELIVERY_STATUS_SCHEDULED,
    ],
]);

it('sanitizes operational errors and remote statuses before persisting messages', function () {
    $fixture = pulseReconciliationFixture();
    $fake = new FakeSocialDeliveryStatusGateway(
        SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_ERROR,
            CarbonImmutable::now('UTC'),
            errorCode: 'remote_rejected',
            errorMessage: 'Authorization: Bearer private-value password=hunter2',
        ),
    );
    $reconciler = pulseReconciler($fake);

    expect($reconciler->synchronizeManually(
        $fixture['owner']->id,
        $fixture['target']->id,
        'operator-sync',
    ))->toBeTrue();

    $target = $fixture['target']->fresh();

    expect($target->provider_error_message)->toContain('[redacted]')
        ->not->toContain('private-value')
        ->not->toContain('hunter2')
        ->and($target->next_reconcile_at)->toBeNull()
        ->and($target->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_FAILED);

    $approvalFixture = pulseReconciliationFixture();
    $approvalFake = new FakeSocialDeliveryStatusGateway(
        SocialDeliveryStatusResultData::observed(
            SocialDeliveryStatusResultData::STATUS_APPROVAL_REQUIRED,
            CarbonImmutable::now('UTC')->addSecond(),
            'Authorization: Bearer private-value password=hunter2',
        ),
    );

    expect(pulseReconciler($approvalFake)->synchronizeManually(
        $approvalFixture['owner']->id,
        $approvalFixture['target']->id,
        'approval-sanitizer-operator',
    ))->toBeTrue();

    $approvalMessage = $approvalFixture['target']->fresh()->provider_error_message;

    expect($approvalMessage)->toContain('[redacted]')
        ->not->toContain('private-value')
        ->not->toContain('hunter2');
});
