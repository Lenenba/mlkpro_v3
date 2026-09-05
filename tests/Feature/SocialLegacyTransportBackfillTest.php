<?php

use App\Models\SocialAccountConnection;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Services\Social\SocialBackfillBatchLedgerService;
use App\Services\Social\SocialLegacyTransportBackfillService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function createWp2bLegacyConnection(
    User $owner,
    ?string $externalAccountId = 'facebook-page-001',
    array $overrides = [],
): SocialAccountConnection {
    return SocialAccountConnection::query()->create(array_merge([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Legacy Facebook page',
        'external_account_id' => $externalAccountId,
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
    ], $overrides));
}

function createWp2bLegacyPost(User $owner, array $overrides = []): SocialPost
{
    return SocialPost::query()->create(array_merge([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'content_payload' => ['text' => 'Legacy Pulse post'],
        'status' => SocialPost::STATUS_DRAFT,
    ], $overrides));
}

function createWp2bLegacyTarget(
    SocialPost $post,
    ?SocialAccountConnection $connection,
    string $status = SocialPostTarget::STATUS_PENDING,
): SocialPostTarget {
    return SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'social_account_connection_id' => $connection?->id,
        'status' => $status,
    ]);
}

/**
 * @return array<string, array<int, array<string, mixed>>>
 */
function wp2bTransportSnapshot(): array
{
    $columns = [
        'id',
        'delivery_provider',
        'transport_generation',
        'logical_destination_key',
        'updated_at',
    ];

    return [
        'connections' => DB::table('social_account_connections')
            ->orderBy('id')
            ->get($columns)
            ->map(fn (object $row): array => (array) $row)
            ->all(),
        'targets' => DB::table('social_post_targets')
            ->orderBy('id')
            ->get($columns)
            ->map(fn (object $row): array => (array) $row)
            ->all(),
    ];
}

it('backfills every tenant atomically, replays without touching timestamps, and rolls back safely', function () {
    Queue::fake();
    $firstOwner = User::factory()->create();
    $secondOwner = User::factory()->create();
    $firstConnection = createWp2bLegacyConnection($firstOwner, 'shared-native-page');
    $secondConnection = createWp2bLegacyConnection($secondOwner, 'shared-native-page');
    $firstTarget = createWp2bLegacyTarget(createWp2bLegacyPost($firstOwner), $firstConnection);
    $secondTarget = createWp2bLegacyTarget(createWp2bLegacyPost($secondOwner), $secondConnection);
    $service = app(SocialLegacyTransportBackfillService::class);

    $preview = $service->preview();

    expect($preview['ready'])->toBeTrue()
        ->and($preview['connections']['backfillable'])->toBe(2)
        ->and($preview['targets']['backfillable'])->toBe(2);

    $applied = $service->execute();
    $firstConnection->refresh();
    $secondConnection->refresh();
    $firstTarget->refresh();
    $secondTarget->refresh();

    expect($applied['connections']['updated'])->toBe(2)
        ->and($applied['targets']['updated'])->toBe(2)
        ->and($applied['batch_id'])->toBeInt()
        ->and(DB::table('social_backfill_batches')->count())->toBe(1)
        ->and(DB::table('social_backfill_batch_entries')->count())->toBe(4)
        ->and($firstConnection->delivery_provider)->toBe(SocialAccountConnection::DELIVERY_PROVIDER_DIRECT)
        ->and($firstConnection->transport_generation)->toBe(SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1)
        ->and($firstConnection->logical_destination_key)->toMatch('/\Aldk:v1:[0-9a-f]{64}\z/')
        ->and($firstTarget->logical_destination_key)->toBe($firstConnection->logical_destination_key)
        ->and($secondTarget->logical_destination_key)->toBe($secondConnection->logical_destination_key)
        ->and($secondConnection->logical_destination_key)->not->toBe($firstConnection->logical_destination_key);

    $afterFirstApply = wp2bTransportSnapshot();
    $replayed = $service->execute();

    expect($replayed['connections']['updated'])->toBe(0)
        ->and($replayed['targets']['updated'])->toBe(0)
        ->and($replayed['batch_id'])->toBeNull()
        ->and(DB::table('social_backfill_batches')->count())->toBe(1)
        ->and(wp2bTransportSnapshot())->toBe($afterFirstApply);

    $rolledBack = $service->rollback();

    expect($rolledBack['connections']['cleared'])->toBe(2)
        ->and($rolledBack['targets']['cleared'])->toBe(2)
        ->and($rolledBack['batch_id'])->toBe($applied['batch_id'])
        ->and(DB::table('social_backfill_batches')->where('state', 'rolled_back')->count())->toBe(1)
        ->and(DB::table('social_account_connections')->whereNotNull('logical_destination_key')->count())->toBe(0)
        ->and(DB::table('social_post_targets')->whereNotNull('logical_destination_key')->count())->toBe(0)
        ->and($service->rollback()['connections']['cleared'])->toBe(0)
        ->and($service->execute()['connections']['updated'])->toBe(2);

    Queue::assertNothingPushed();
});

it('rolls back only the latest applied transport batch in LIFO order', function () {
    $owner = User::factory()->create();
    $firstConnection = createWp2bLegacyConnection($owner, 'first-batch-page');
    $firstTarget = createWp2bLegacyTarget(createWp2bLegacyPost($owner), $firstConnection);
    $service = app(SocialLegacyTransportBackfillService::class);
    $firstBatch = $service->execute();

    $secondConnection = createWp2bLegacyConnection($owner, 'second-batch-page');
    $secondTarget = createWp2bLegacyTarget(createWp2bLegacyPost($owner), $secondConnection);
    $secondBatch = $service->execute();

    expect($firstBatch['batch_id'])->toBeInt()
        ->and($secondBatch['batch_id'])->toBeInt()
        ->and($secondBatch['batch_id'])->toBeGreaterThan($firstBatch['batch_id']);

    $latestRollback = $service->rollback();

    expect($latestRollback['batch_id'])->toBe($secondBatch['batch_id'])
        ->and($firstConnection->fresh()->logical_destination_key)->not->toBeNull()
        ->and($firstTarget->fresh()->logical_destination_key)->not->toBeNull()
        ->and($secondConnection->fresh()->logical_destination_key)->toBeNull()
        ->and($secondTarget->fresh()->logical_destination_key)->toBeNull()
        ->and(DB::table('social_backfill_batches')->where('id', $firstBatch['batch_id'])->value('state'))
        ->toBe('applied')
        ->and(DB::table('social_backfill_batches')->where('id', $secondBatch['batch_id'])->value('state'))
        ->toBe('rolled_back');

    $firstRollback = $service->rollback();

    expect($firstRollback['batch_id'])->toBe($firstBatch['batch_id'])
        ->and($firstConnection->fresh()->logical_destination_key)->toBeNull()
        ->and($firstTarget->fresh()->logical_destination_key)->toBeNull()
        ->and(DB::table('social_backfill_batches')->where('state', 'applied')->count())->toBe(0);
});

it('keeps a published orphan untouched because no destination identity may be invented', function () {
    $owner = User::factory()->create();
    $target = createWp2bLegacyTarget(
        createWp2bLegacyPost($owner, ['status' => SocialPost::STATUS_PUBLISHED]),
        null,
        SocialPostTarget::STATUS_PUBLISHED,
    );
    $service = app(SocialLegacyTransportBackfillService::class);

    $preview = $service->preview();
    $result = $service->execute();

    expect($preview['ready'])->toBeTrue()
        ->and($preview['targets']['terminal_orphans_ignored'])->toBe(1)
        ->and($result['targets']['updated'])->toBe(0)
        ->and($target->fresh()->logical_destination_key)->toBeNull();
});

it('keeps a canceled orphan untouched because it cannot be replayed', function () {
    $owner = User::factory()->create();
    $target = createWp2bLegacyTarget(
        createWp2bLegacyPost($owner),
        null,
        SocialPostTarget::STATUS_CANCELED,
    );
    $service = app(SocialLegacyTransportBackfillService::class);

    $preview = $service->preview();
    $result = $service->execute();

    expect($preview['ready'])->toBeTrue()
        ->and($preview['targets']['terminal_orphans_ignored'])->toBe(1)
        ->and($result['targets']['updated'])->toBe(0)
        ->and($target->fresh()->logical_destination_key)->toBeNull();
});

it('blocks a canonical terminal orphan colliding with a live target before any write', function () {
    $owner = User::factory()->create();
    $post = createWp2bLegacyPost($owner);
    $orphanedConnection = createWp2bLegacyConnection($owner, 'shared-orphan-page');
    $orphanedTarget = createWp2bLegacyTarget(
        $post,
        $orphanedConnection,
        SocialPostTarget::STATUS_PUBLISHED,
    );
    $identity = pulseDirectTransportIdentity(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'shared-orphan-page',
    );
    DB::table('social_post_targets')->where('id', $orphanedTarget->id)->update($identity);
    $orphanedConnection->delete();
    $liveConnection = createWp2bLegacyConnection($owner, 'shared-orphan-page');
    $liveTarget = createWp2bLegacyTarget($post, $liveConnection);
    $before = wp2bTransportSnapshot();
    $service = app(SocialLegacyTransportBackfillService::class);

    $preview = $service->preview();

    expect($preview['ready'])->toBeFalse()
        ->and($preview['anomalies']['by_reason'])->toHaveKey(
            'target_logical_destination_duplicate_or_collision',
            1,
        );
    expect(fn () => $service->execute())
        ->toThrow(LogicException::class, 'target_logical_destination_duplicate_or_collision=1');
    expect(wp2bTransportSnapshot())->toBe($before)
        ->and($orphanedTarget->fresh()->logical_destination_key)->toBe($identity['logical_destination_key'])
        ->and($liveTarget->fresh()->logical_destination_key)->toBeNull()
        ->and($liveConnection->fresh()->logical_destination_key)->toBeNull()
        ->and(DB::table('social_backfill_batches')->count())->toBe(0);
});

it('replays safely after a canonical published target loses its connection', function () {
    $owner = User::factory()->create();
    $connection = createWp2bLegacyConnection($owner);
    $target = createWp2bLegacyTarget(
        createWp2bLegacyPost($owner, ['status' => SocialPost::STATUS_PUBLISHED]),
        $connection,
        SocialPostTarget::STATUS_PUBLISHED,
    );
    $service = app(SocialLegacyTransportBackfillService::class);

    $service->execute();
    $canonicalKey = (string) $target->fresh()->logical_destination_key;
    $connection->delete();

    $preview = $service->preview();
    $replayed = $service->execute();
    expect(fn () => $service->rollback())
        ->toThrow(LogicException::class, 'ledger tenant cannot be resolved');

    expect($target->fresh()->social_account_connection_id)->toBeNull()
        ->and($canonicalKey)->toMatch('/\Aldk:v1:[0-9a-f]{64}\z/')
        ->and($preview['ready'])->toBeTrue()
        ->and($preview['targets']['terminal_orphans_ignored'])->toBe(1)
        ->and($replayed['targets']['updated'])->toBe(0)
        ->and($target->fresh()->logical_destination_key)->toBe($canonicalKey);
});

it('never rolls back canonical transport identities without batch provenance', function () {
    $owner = User::factory()->create();
    $connection = createWp2bLegacyConnection($owner, 'native-page');
    $post = createWp2bLegacyPost($owner);
    $target = createWp2bLegacyTarget($post, $connection);
    $identity = pulseDirectTransportIdentity(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'native-page',
    );
    DB::table('social_account_connections')->where('id', $connection->id)->update($identity);
    DB::table('social_post_targets')->where('id', $target->id)->update($identity);

    $rolledBack = app(SocialLegacyTransportBackfillService::class)->rollback();

    expect($rolledBack['batch_id'])->toBeNull()
        ->and($rolledBack['connections']['cleared'])->toBe(0)
        ->and($rolledBack['targets']['cleared'])->toBe(0)
        ->and($connection->fresh()->logical_destination_key)->toBe($identity['logical_destination_key'])
        ->and($target->fresh()->logical_destination_key)->toBe($identity['logical_destination_key']);
});

it('refuses a transport rollback after a batch row changes', function () {
    $owner = User::factory()->create();
    $connection = createWp2bLegacyConnection($owner, 'mutated-page');
    $target = createWp2bLegacyTarget(createWp2bLegacyPost($owner), $connection);
    $service = app(SocialLegacyTransportBackfillService::class);
    $service->execute();
    DB::table('social_post_targets')->where('id', $target->id)->update([
        'status' => SocialPostTarget::STATUS_SCHEDULED,
    ]);

    expect(fn () => $service->rollback())
        ->toThrow(LogicException::class, 'row changed after its batch was applied');
    expect($connection->fresh()->logical_destination_key)->not->toBeNull()
        ->and($target->fresh()->logical_destination_key)->not->toBeNull()
        ->and(DB::table('social_backfill_batches')->where('state', 'applied')->count())->toBe(1);
});

it('refuses rollback when ledger provenance names a different tenant', function () {
    $owner = User::factory()->create();
    $foreignOwner = User::factory()->create();
    $connection = createWp2bLegacyConnection($owner, 'tenant-ledger-page');
    $target = createWp2bLegacyTarget(createWp2bLegacyPost($owner), $connection);
    $service = app(SocialLegacyTransportBackfillService::class);
    $applied = $service->execute();
    DB::table('social_backfill_batch_entries')
        ->where('social_backfill_batch_id', $applied['batch_id'])
        ->where('entity_type', 'social_post_target')
        ->where('entity_id', $target->id)
        ->update(['workspace_id' => $foreignOwner->id]);
    $entries = DB::table('social_backfill_batch_entries')
        ->where('social_backfill_batch_id', $applied['batch_id'])
        ->orderBy('entity_type')
        ->orderBy('entity_id')
        ->get()
        ->map(fn (object $entry): array => [
            'workspace_id' => (int) $entry->workspace_id,
            'entity_type' => (string) $entry->entity_type,
            'entity_id' => (int) $entry->entity_id,
            'mutation' => (string) $entry->mutation,
            'before_fingerprint' => $entry->before_fingerprint === null
                ? null
                : (string) $entry->before_fingerprint,
            'after_fingerprint' => (string) $entry->after_fingerprint,
        ])
        ->all();
    $manifestHash = app(SocialBackfillBatchLedgerService::class)->fingerprint([
        'operation' => SocialBackfillBatchLedgerService::OPERATION_LEGACY_TRANSPORT,
        'entries' => $entries,
    ]);
    DB::table('social_backfill_batches')->where('id', $applied['batch_id'])->update([
        'manifest_hash' => $manifestHash,
    ]);

    expect(fn () => $service->rollback())
        ->toThrow(LogicException::class, 'does not match the row tenant');
    expect($connection->fresh()->logical_destination_key)->not->toBeNull()
        ->and($target->fresh()->logical_destination_key)->not->toBeNull()
        ->and(DB::table('social_backfill_batches')->where('state', 'applied')->count())->toBe(1);
});

it('refuses a transport rollback after a new target consumes a batch connection', function () {
    $owner = User::factory()->create();
    $connection = createWp2bLegacyConnection($owner, 'consumed-page');
    createWp2bLegacyTarget(createWp2bLegacyPost($owner), $connection);
    $service = app(SocialLegacyTransportBackfillService::class);
    $service->execute();
    $newTarget = createWp2bLegacyTarget(createWp2bLegacyPost($owner), $connection);

    expect(fn () => $service->rollback())
        ->toThrow(LogicException::class, 'cannot be rolled back after new consumers exist');
    expect($connection->fresh()->logical_destination_key)->not->toBeNull()
        ->and($newTarget->fresh()->social_account_connection_id)->toBe($connection->id)
        ->and(DB::table('social_backfill_batches')->where('state', 'applied')->count())->toBe(1);
});

it('fails closed before any write for legacy transport anomalies', function (Closure $arrange, string $reason) {
    $arrange();
    $before = wp2bTransportSnapshot();
    $service = app(SocialLegacyTransportBackfillService::class);
    $preview = $service->preview();

    expect($preview['ready'])->toBeFalse()
        ->and($preview['anomalies']['by_reason'])->toHaveKey($reason);

    expect(fn () => $service->execute())->toThrow(LogicException::class, $reason);
    expect(wp2bTransportSnapshot())->toBe($before);
})->with([
    'connection identity cannot be derived' => [
        function (): void {
            createWp2bLegacyConnection(User::factory()->create(), null);
        },
        'connection_identity_not_derivable',
    ],
    'normalized connection destination is duplicated' => [
        function (): void {
            $owner = User::factory()->create();
            createWp2bLegacyConnection($owner, 'same-page');
            createWp2bLegacyConnection($owner, ' same-page ');
        },
        'connection_logical_destination_duplicate_or_collision',
    ],
    'target crosses tenants' => [
        function (): void {
            $postOwner = User::factory()->create();
            $connectionOwner = User::factory()->create();
            SocialPostTarget::withoutEvents(fn (): SocialPostTarget => createWp2bLegacyTarget(
                createWp2bLegacyPost($postOwner),
                createWp2bLegacyConnection($connectionOwner),
            ));
        },
        'target_cross_tenant',
    ],
    'replayable target is orphaned' => [
        function (): void {
            $owner = User::factory()->create();
            createWp2bLegacyTarget(createWp2bLegacyPost($owner), null);
        },
        'target_connection_missing_and_replayable',
    ],
    'connection triplet is partial' => [
        function (): void {
            $connection = createWp2bLegacyConnection(User::factory()->create());
            DB::table('social_account_connections')->where('id', $connection->id)->update([
                'delivery_provider' => SocialAccountConnection::DELIVERY_PROVIDER_DIRECT,
            ]);
        },
        'connection_transport_identity_partial',
    ],
    'target triplet is partial' => [
        function (): void {
            $owner = User::factory()->create();
            $connection = createWp2bLegacyConnection($owner);
            $target = createWp2bLegacyTarget(createWp2bLegacyPost($owner), $connection);
            DB::table('social_post_targets')->where('id', $target->id)->update([
                'delivery_provider' => SocialAccountConnection::DELIVERY_PROVIDER_DIRECT,
            ]);
        },
        'target_transport_identity_partial',
    ],
    'target triplet conflicts with its connection' => [
        function (): void {
            $owner = User::factory()->create();
            $connection = createWp2bLegacyConnection($owner);
            $target = createWp2bLegacyTarget(createWp2bLegacyPost($owner), $connection);
            DB::table('social_post_targets')->where('id', $target->id)->update([
                'delivery_provider' => SocialAccountConnection::DELIVERY_PROVIDER_DIRECT,
                'transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
                'logical_destination_key' => 'ldk:v1:'.str_repeat('a', 64),
            ]);
        },
        'target_transport_identity_conflict',
    ],
    'post has two replayable targets for one logical destination' => [
        function (): void {
            $owner = User::factory()->create();
            $post = createWp2bLegacyPost($owner);
            createWp2bLegacyTarget($post, createWp2bLegacyConnection($owner, 'same-page'));
            createWp2bLegacyTarget($post, createWp2bLegacyConnection($owner, ' same-page '));
        },
        'target_logical_destination_duplicate_or_collision',
    ],
]);

it('requires a stopped-writer confirmation for writes and exposes only aggregate command output', function () {
    $owner = User::factory()->create();
    $connection = createWp2bLegacyConnection($owner, 'private-native-destination');
    createWp2bLegacyTarget(createWp2bLegacyPost($owner), $connection);

    expect(Artisan::call('pulse:buffer:backfill-legacy-transport'))->toBe(0);
    expect(Artisan::output())->toContain('Batch provenance: none');
    expect(Artisan::call('pulse:buffer:backfill-legacy-transport', ['--apply' => true]))->toBe(1);

    $exitCode = Artisan::call('pulse:buffer:backfill-legacy-transport', [
        '--apply' => true,
        '--confirm-all-pulse-writers-stopped' => true,
        '--json' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('pulse_legacy_transport_backfill_v1')
        ->not->toContain('private-native-destination')
        ->not->toContain((string) $connection->fresh()->logical_destination_key);
});

it('serializes competing transport operations with a global application lock', function () {
    createWp2bLegacyConnection(User::factory()->create());
    $lock = Cache::lock('pulse:legacy-direct-transport-backfill', 300);

    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => app(SocialLegacyTransportBackfillService::class)->execute())
            ->toThrow(LogicException::class, 'already running');
    } finally {
        $lock->release();
    }
});
