<?php

use App\Models\SocialAccountConnection;
use App\Models\SocialApprovalRequest;
use App\Models\SocialPost;
use App\Models\SocialPostRevision;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Services\Social\SocialBackfillBatchLedgerService;
use App\Services\Social\SocialEditorialFoundationBackfillService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

$createLegacyConnection = static function (
    User $owner,
    string $externalAccountId,
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
};

$createLegacyPost = static function (User $owner, array $overrides = []): SocialPost {
    return SocialPost::query()->create(array_merge([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'source_type' => 'service',
        'source_id' => 42,
        'content_payload' => ['text' => 'Legacy Pulse content'],
        'media_payload' => [['url' => 'https://cdn.example.test/legacy.jpg']],
        'link_url' => 'https://example.test/legacy',
        'status' => SocialPost::STATUS_DRAFT,
    ], $overrides));
};

$createLegacyTarget = static function (
    SocialPost $post,
    ?SocialAccountConnection $connection,
    string $status = SocialPostTarget::STATUS_PENDING,
): SocialPostTarget {
    return SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'social_account_connection_id' => $connection?->id,
        'status' => $status,
    ]);
};

it('defaults to an aggregate preview and gates every write mode', function () use ($createLegacyPost) {
    Queue::fake();
    Http::preventStrayRequests();
    $owner = User::factory()->create([
        'company_timezone' => 'America/Toronto',
        'email' => 'private-owner@example.test',
    ]);
    $createLegacyPost($owner, [
        'content_payload' => ['text' => 'secret-editorial-payload-never-output'],
    ]);

    $previewExitCode = Artisan::call('pulse:buffer:backfill-editorial-foundation', [
        '--json' => true,
    ]);
    $previewOutput = Artisan::output();
    $preview = json_decode($previewOutput, true, 512, JSON_THROW_ON_ERROR);

    expect($previewExitCode)->toBe(0)
        ->and($preview['mode'])->toBe('preflight')
        ->and($preview['posts']['backfillable'])->toBe(1)
        ->and(DB::table('social_post_revisions')->count())->toBe(0)
        ->and($previewOutput)->not->toContain('secret-editorial-payload-never-output')
        ->not->toContain('private-owner@example.test');

    expect(Artisan::call('pulse:buffer:backfill-editorial-foundation'))->toBe(0);
    expect(Artisan::output())->toContain('Batch provenance: none');

    expect(Artisan::call('pulse:buffer:backfill-editorial-foundation', [
        '--apply' => true,
        '--rollback' => true,
        '--confirm-all-pulse-writers-stopped' => true,
    ]))->toBe(1);
    expect(Artisan::output())->toContain('never both');

    expect(Artisan::call('pulse:buffer:backfill-editorial-foundation', [
        '--apply' => true,
    ]))->toBe(1);
    expect(Artisan::output())->toContain('writer is stopped');

    expect(Artisan::call('pulse:buffer:backfill-editorial-foundation', [
        '--apply' => true,
        '--confirm-all-pulse-writers-stopped' => true,
        '--json' => true,
    ]))->toBe(0);
    $applyOutput = Artisan::output();
    $applied = json_decode($applyOutput, true, 512, JSON_THROW_ON_ERROR);
    expect($applied['mode'])->toBe('apply')
        ->and($applied['posts']['updated'])->toBe(1)
        ->and($applied['revisions']['created'])->toBe(1)
        ->and($applyOutput)->not->toContain('secret-editorial-payload-never-output')
        ->not->toContain('private-owner@example.test');

    $originalEnvironment = app()->environment();
    $originalConfiguredEnvironment = config('app.env');
    app()->detectEnvironment(fn (): string => 'production');
    config()->set('app.env', 'production');

    try {
        expect(Artisan::call('pulse:buffer:backfill-editorial-foundation', [
            '--apply' => true,
            '--confirm-all-pulse-writers-stopped' => true,
        ]))->toBe(1);
        expect(Artisan::output())->toContain('restricted to local and testing');

        expect(Artisan::call('pulse:buffer:backfill-legacy-transport', [
            '--rollback' => true,
            '--confirm-all-pulse-writers-stopped' => true,
        ]))->toBe(1);
        expect(Artisan::output())->toContain('restricted to local and testing');

        expect(Artisan::call('pulse:buffer:backfill-editorial-foundation', [
            '--json' => true,
        ]))->toBe(0);
        expect(json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)['mode'])
            ->toBe('preflight');
    } finally {
        app()->detectEnvironment(fn (): string => $originalEnvironment);
        config()->set('app.env', $originalConfiguredEnvironment);
    }

    Queue::assertNothingPushed();
});

it('backfills tenants deterministically and maps ambiguous legacy delivery without retrying', function () use (
    $createLegacyConnection,
    $createLegacyPost,
    $createLegacyTarget,
) {
    Queue::fake();
    Http::preventStrayRequests();
    $firstOwner = User::factory()->create(['company_timezone' => 'America/Toronto']);
    $secondOwner = User::factory()->create(['company_timezone' => 'Europe/Paris']);
    $publishingPost = $createLegacyPost($firstOwner, [
        'content_payload' => ['text' => 'Tenant one publishing content'],
        'status' => SocialPost::STATUS_PUBLISHING,
    ]);
    $failedPost = $createLegacyPost($secondOwner, [
        'content_payload' => ['text' => 'Tenant two failed content'],
        'status' => SocialPost::STATUS_FAILED,
    ]);
    $publishingTarget = $createLegacyTarget(
        $publishingPost,
        $createLegacyConnection($firstOwner, 'first-page'),
        SocialPostTarget::STATUS_PUBLISHING,
    );
    $failedTarget = $createLegacyTarget(
        $failedPost,
        $createLegacyConnection($secondOwner, 'second-page'),
        SocialPostTarget::STATUS_FAILED,
    );
    $service = app(SocialEditorialFoundationBackfillService::class);

    $preview = $service->preview();
    $applied = $service->execute();
    $publishingRevision = SocialPostRevision::query()
        ->where('social_post_id', $publishingPost->id)
        ->sole();
    $failedRevision = SocialPostRevision::query()
        ->where('social_post_id', $failedPost->id)
        ->sole();

    expect($preview['ready'])->toBeTrue()
        ->and($preview['posts']['backfillable'])->toBe(2)
        ->and($applied['revisions']['created'])->toBe(2)
        ->and($applied['batch_id'])->toBeInt()
        ->and(DB::table('social_backfill_batches')->count())->toBe(1)
        ->and(DB::table('social_backfill_batch_entries')->count())->toBe(6)
        ->and($publishingRevision->user_id)->toBe($firstOwner->id)
        ->and($failedRevision->user_id)->toBe($secondOwner->id)
        ->and(data_get($publishingRevision->base_content, 'content_payload.text'))
        ->toBe('Tenant one publishing content')
        ->and(data_get($failedRevision->base_content, 'content_payload.text'))
        ->toBe('Tenant two failed content')
        ->and($publishingRevision->payload_hash)->not->toBe($failedRevision->payload_hash)
        ->and($publishingPost->fresh()->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN)
        ->and($failedPost->fresh()->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN)
        ->and($publishingPost->fresh()->sync_status)->toBe(SocialPost::SYNC_STATUS_ERROR)
        ->and($failedPost->fresh()->sync_status)->toBe(SocialPost::SYNC_STATUS_ERROR)
        ->and($publishingTarget->fresh()->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN)
        ->and($failedTarget->fresh()->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN)
        ->and($publishingTarget->fresh()->sync_status)->toBe(SocialPost::SYNC_STATUS_ERROR)
        ->and($failedTarget->fresh()->sync_status)->toBe(SocialPost::SYNC_STATUS_ERROR)
        ->and($publishingTarget->fresh()->last_submitted_revision_id)->toBe($publishingRevision->id)
        ->and($failedTarget->fresh()->last_submitted_revision_id)->toBe($failedRevision->id);

    $afterFirstApply = [
        'posts' => DB::table('social_posts')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'revisions' => DB::table('social_post_revisions')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'targets' => DB::table('social_post_targets')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
    ];
    $replayed = $service->execute();
    $afterReplay = [
        'posts' => DB::table('social_posts')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'revisions' => DB::table('social_post_revisions')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'targets' => DB::table('social_post_targets')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
    ];

    expect($replayed['revisions']['created'])->toBe(0)
        ->and($replayed['posts']['updated'])->toBe(0)
        ->and($replayed['approvals']['updated'])->toBe(0)
        ->and($replayed['targets']['updated'])->toBe(0)
        ->and($replayed['batch_id'])->toBeNull()
        ->and(DB::table('social_backfill_batches')->count())->toBe(1)
        ->and($afterReplay)->toBe($afterFirstApply);

    Queue::assertNothingPushed();
});

it('derives post delivery and sync axes from target truth instead of the legacy post status', function () use (
    $createLegacyConnection,
    $createLegacyPost,
    $createLegacyTarget,
) {
    Queue::fake();
    Http::preventStrayRequests();
    $owner = User::factory()->create(['company_timezone' => 'America/Toronto']);
    $legacyPublishedPost = $createLegacyPost($owner, [
        'status' => SocialPost::STATUS_PUBLISHED,
        'content_payload' => ['text' => 'Legacy post says published'],
    ]);
    $ambiguousFailedTarget = $createLegacyTarget(
        $legacyPublishedPost,
        $createLegacyConnection($owner, 'published-post-failed-target'),
        SocialPostTarget::STATUS_FAILED,
    );
    $legacyFailedPost = $createLegacyPost($owner, [
        'status' => SocialPost::STATUS_FAILED,
        'content_payload' => ['text' => 'Legacy post says failed'],
    ]);
    $provenPublishedTarget = $createLegacyTarget(
        $legacyFailedPost,
        $createLegacyConnection($owner, 'failed-post-published-target'),
        SocialPostTarget::STATUS_PUBLISHED,
    );

    app(SocialEditorialFoundationBackfillService::class)->execute();

    expect($ambiguousFailedTarget->fresh()->delivery_status)
        ->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN)
        ->and($ambiguousFailedTarget->fresh()->sync_status)
        ->toBe(SocialPost::SYNC_STATUS_ERROR)
        ->and($legacyPublishedPost->fresh()->delivery_status)
        ->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN)
        ->and($legacyPublishedPost->fresh()->sync_status)
        ->toBe(SocialPost::SYNC_STATUS_ERROR)
        ->and($legacyPublishedPost->fresh()->delivery_status_source)
        ->toBe(SocialPost::STATUS_SOURCE_DERIVED)
        ->and($provenPublishedTarget->fresh()->delivery_status)
        ->toBe(SocialPost::DELIVERY_STATUS_PUBLISHED)
        ->and($provenPublishedTarget->fresh()->sync_status)
        ->toBe(SocialPost::SYNC_STATUS_SYNCED)
        ->and($legacyFailedPost->fresh()->delivery_status)
        ->toBe(SocialPost::DELIVERY_STATUS_PUBLISHED)
        ->and($legacyFailedPost->fresh()->sync_status)
        ->toBe(SocialPost::SYNC_STATUS_SYNCED)
        ->and($legacyFailedPost->fresh()->sync_status_source)
        ->toBe(SocialPost::STATUS_SOURCE_DERIVED);

    Queue::assertNothingPushed();
});

it('infers an auditable legacy approval when a draft target proves submission', function () use (
    $createLegacyConnection,
    $createLegacyPost,
    $createLegacyTarget,
) {
    Queue::fake();
    Http::preventStrayRequests();
    $owner = User::factory()->create(['company_timezone' => 'America/Toronto']);
    $post = $createLegacyPost($owner, ['status' => SocialPost::STATUS_DRAFT]);
    $target = $createLegacyTarget(
        $post,
        $createLegacyConnection($owner, 'draft-published-page'),
        SocialPostTarget::STATUS_PUBLISHED,
    );

    $result = app(SocialEditorialFoundationBackfillService::class)->execute();
    $revision = SocialPostRevision::query()->where('social_post_id', $post->id)->sole();
    $post->refresh();
    $target->refresh();

    expect($result['revisions']['created'])->toBe(1)
        ->and($revision->approved_at)->not->toBeNull()
        ->and($revision->approval_provenance)->toBe(SocialPostRevision::APPROVAL_TYPE_LEGACY_INFERRED)
        ->and($post->editorial_status)->toBe(SocialPost::EDITORIAL_STATUS_APPROVED)
        ->and($post->approved_revision_id)->toBe($revision->id)
        ->and($post->payload_hash)->toBe($revision->payload_hash)
        ->and($target->last_submitted_revision_id)->toBe($revision->id)
        ->and($target->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_PUBLISHED);

    Queue::assertNothingPushed();
});

it('blocks a proven submission that contradicts an explicit pending approval', function () use (
    $createLegacyConnection,
    $createLegacyPost,
    $createLegacyTarget,
) {
    Queue::fake();
    Http::preventStrayRequests();
    $owner = User::factory()->create(['company_timezone' => 'America/Toronto']);
    $post = $createLegacyPost($owner, ['status' => SocialPost::STATUS_DRAFT]);
    $target = $createLegacyTarget(
        $post,
        $createLegacyConnection($owner, 'pending-published-page'),
        SocialPostTarget::STATUS_PUBLISHED,
    );
    $approval = SocialApprovalRequest::query()->create([
        'social_post_id' => $post->id,
        'requested_by_user_id' => $owner->id,
        'status' => SocialApprovalRequest::STATUS_PENDING,
        'requested_at' => now()->subDay(),
    ]);
    $service = app(SocialEditorialFoundationBackfillService::class);
    $preview = $service->preview();

    expect($preview['ready'])->toBeFalse()
        ->and($preview['anomalies']['by_reason'])->toBe([
            'submitted_delivery_without_approval' => 1,
        ]);
    expect(fn () => $service->execute())
        ->toThrow(LogicException::class, 'submitted_delivery_without_approval=1');
    expect(DB::table('social_post_revisions')->count())->toBe(0)
        ->and(DB::table('social_backfill_batches')->count())->toBe(0)
        ->and($post->fresh()->current_editorial_revision)->toBeNull()
        ->and($target->fresh()->current_revision_id)->toBeNull()
        ->and($approval->fresh()->social_post_revision_id)->toBeNull();

    Queue::assertNothingPushed();
});

it('attaches legacy approvals and targets then rolls back only its synthetic foundation', function () use (
    $createLegacyConnection,
    $createLegacyPost,
    $createLegacyTarget,
) {
    $owner = User::factory()->create(['company_timezone' => 'America/Toronto']);
    $post = $createLegacyPost($owner, ['status' => SocialPost::STATUS_PENDING_APPROVAL]);
    $target = $createLegacyTarget(
        $post,
        $createLegacyConnection($owner, 'approval-page'),
    );
    $approval = SocialApprovalRequest::query()->create([
        'social_post_id' => $post->id,
        'requested_by_user_id' => $owner->id,
        'status' => SocialApprovalRequest::STATUS_PENDING,
        'requested_at' => now()->subDay(),
    ]);
    $service = app(SocialEditorialFoundationBackfillService::class);

    $applied = $service->execute();
    $revision = SocialPostRevision::query()->where('social_post_id', $post->id)->sole();

    expect($applied['approvals']['updated'])->toBe(1)
        ->and($approval->fresh()->social_post_revision_id)->toBe($revision->id)
        ->and($target->fresh()->current_revision_id)->toBe($revision->id)
        ->and($post->fresh()->approved_revision_id)->toBeNull();

    $rolledBack = $service->rollback();

    expect($rolledBack['revisions']['deleted'])->toBe(1)
        ->and($rolledBack['posts']['cleared'])->toBe(1)
        ->and($rolledBack['approvals']['cleared'])->toBe(1)
        ->and($rolledBack['targets']['cleared'])->toBe(1)
        ->and($rolledBack['batch_id'])->toBe($applied['batch_id'])
        ->and(DB::table('social_backfill_batches')->where('state', 'rolled_back')->count())->toBe(1)
        ->and(SocialPostRevision::query()->count())->toBe(0)
        ->and($approval->fresh()->social_post_revision_id)->toBeNull()
        ->and($approval->fresh()->status)->toBe(SocialApprovalRequest::STATUS_PENDING)
        ->and($target->fresh()->current_revision_id)->toBeNull()
        ->and($target->fresh()->status)->toBe(SocialPostTarget::STATUS_PENDING)
        ->and($post->fresh()->current_editorial_revision)->toBeNull()
        ->and($post->fresh()->status)->toBe(SocialPost::STATUS_PENDING_APPROVAL);
});

it('rolls back only the latest applied editorial batch in LIFO order', function () use (
    $createLegacyPost,
) {
    $owner = User::factory()->create(['company_timezone' => 'America/Toronto']);
    $firstPost = $createLegacyPost($owner, [
        'content_payload' => ['text' => 'First historical batch'],
    ]);
    $service = app(SocialEditorialFoundationBackfillService::class);
    $firstBatch = $service->execute();
    $firstRevision = SocialPostRevision::query()->where('social_post_id', $firstPost->id)->sole();

    $secondPost = $createLegacyPost($owner, [
        'content_payload' => ['text' => 'Second historical batch'],
    ]);
    $secondBatch = $service->execute();
    $secondRevision = SocialPostRevision::query()->where('social_post_id', $secondPost->id)->sole();

    expect($firstBatch['batch_id'])->toBeInt()
        ->and($secondBatch['batch_id'])->toBeInt()
        ->and($secondBatch['batch_id'])->toBeGreaterThan($firstBatch['batch_id']);

    $latestRollback = $service->rollback();

    expect($latestRollback['batch_id'])->toBe($secondBatch['batch_id'])
        ->and($firstPost->fresh()->current_editorial_revision)->toBe(1)
        ->and(SocialPostRevision::query()->whereKey($firstRevision->id)->exists())->toBeTrue()
        ->and($secondPost->fresh()->current_editorial_revision)->toBeNull()
        ->and(SocialPostRevision::query()->whereKey($secondRevision->id)->exists())->toBeFalse()
        ->and(DB::table('social_backfill_batches')->where('id', $firstBatch['batch_id'])->value('state'))
        ->toBe('applied')
        ->and(DB::table('social_backfill_batches')->where('id', $secondBatch['batch_id'])->value('state'))
        ->toBe('rolled_back');

    $firstRollback = $service->rollback();

    expect($firstRollback['batch_id'])->toBe($firstBatch['batch_id'])
        ->and($firstPost->fresh()->current_editorial_revision)->toBeNull()
        ->and(SocialPostRevision::query()->whereKey($firstRevision->id)->exists())->toBeFalse()
        ->and(DB::table('social_backfill_batches')->where('state', 'applied')->count())->toBe(0);
});

it('never rolls back a historical legacy revision without ledger provenance', function () use (
    $createLegacyPost,
) {
    $owner = User::factory()->create(['company_timezone' => 'America/Toronto']);
    $post = $createLegacyPost($owner);
    $service = app(SocialEditorialFoundationBackfillService::class);
    $applied = $service->execute();
    $revision = SocialPostRevision::query()->where('social_post_id', $post->id)->sole();

    DB::table('social_backfill_batch_entries')
        ->where('social_backfill_batch_id', $applied['batch_id'])
        ->delete();
    DB::table('social_backfill_batches')->where('id', $applied['batch_id'])->delete();

    $rolledBack = $service->rollback();

    expect($revision->origin)->toBe(SocialPostRevision::ORIGIN_LEGACY_BACKFILL_V1)
        ->and($rolledBack['batch_id'])->toBeNull()
        ->and($rolledBack['revisions']['deleted'])->toBe(0)
        ->and($post->fresh()->current_editorial_revision)->toBe(1)
        ->and(SocialPostRevision::query()->whereKey($revision->id)->exists())->toBeTrue();
});

it('refuses rollback after a native editorial revision exists', function () use ($createLegacyPost) {
    $owner = User::factory()->create(['company_timezone' => 'America/Toronto']);
    $post = $createLegacyPost($owner);
    $service = app(SocialEditorialFoundationBackfillService::class);
    $service->execute();
    $syntheticRevision = SocialPostRevision::query()->where('social_post_id', $post->id)->sole();

    DB::table('social_post_revisions')->insert([
        'user_id' => $owner->id,
        'social_post_id' => $post->id,
        'revision_number' => 2,
        'base_content' => json_encode($syntheticRevision->base_content, JSON_THROW_ON_ERROR),
        'source_snapshot' => json_encode($syntheticRevision->source_snapshot, JSON_THROW_ON_ERROR),
        'media_snapshot' => json_encode($syntheticRevision->media_snapshot, JSON_THROW_ON_ERROR),
        'scheduled_for' => $syntheticRevision->scheduled_for,
        'scheduled_timezone' => $syntheticRevision->scheduled_timezone,
        'scheduled_local_time' => $syntheticRevision->scheduled_local_time,
        'payload_hash' => $syntheticRevision->payload_hash,
        'created_by_user_id' => $owner->id,
        'origin' => SocialPostRevision::ORIGIN_COMPOSER,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $before = [
        'post' => (array) DB::table('social_posts')->find($post->id),
        'revisions' => DB::table('social_post_revisions')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
    ];

    expect(fn () => $service->rollback())
        ->toThrow(LogicException::class, 'cannot be rolled back after new consumers exist');

    expect([
        'post' => (array) DB::table('social_posts')->find($post->id),
        'revisions' => DB::table('social_post_revisions')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
    ])->toBe($before);
});

it('refuses an editorial rollback after a batch row changes', function () use ($createLegacyPost) {
    $owner = User::factory()->create(['company_timezone' => 'America/Toronto']);
    $post = $createLegacyPost($owner);
    $service = app(SocialEditorialFoundationBackfillService::class);
    $service->execute();
    DB::table('social_posts')->where('id', $post->id)->update([
        'failure_reason' => 'A later operator mutation',
    ]);

    expect(fn () => $service->rollback())
        ->toThrow(LogicException::class, 'row changed after its batch was applied');
    expect($post->fresh()->current_editorial_revision)->toBe(1)
        ->and(SocialPostRevision::query()->where('social_post_id', $post->id)->count())->toBe(1)
        ->and(DB::table('social_backfill_batches')->where('state', 'applied')->count())->toBe(1);
});

it('refuses editorial rollback when ledger provenance names a different tenant', function () use (
    $createLegacyPost,
) {
    $owner = User::factory()->create(['company_timezone' => 'America/Toronto']);
    $foreignOwner = User::factory()->create();
    $post = $createLegacyPost($owner);
    $service = app(SocialEditorialFoundationBackfillService::class);
    $applied = $service->execute();
    DB::table('social_backfill_batch_entries')
        ->where('social_backfill_batch_id', $applied['batch_id'])
        ->where('entity_type', 'social_post')
        ->where('entity_id', $post->id)
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
        'operation' => SocialBackfillBatchLedgerService::OPERATION_EDITORIAL_FOUNDATION,
        'entries' => $entries,
    ]);
    DB::table('social_backfill_batches')->where('id', $applied['batch_id'])->update([
        'manifest_hash' => $manifestHash,
    ]);

    expect(fn () => $service->rollback())
        ->toThrow(LogicException::class, 'does not match the row tenant');
    expect($post->fresh()->current_editorial_revision)->toBe(1)
        ->and(SocialPostRevision::query()->where('social_post_id', $post->id)->exists())->toBeTrue()
        ->and(DB::table('social_backfill_batches')->where('state', 'applied')->count())->toBe(1);
});

it('refuses an editorial rollback after a new approval consumes its revision', function () use (
    $createLegacyPost,
) {
    $owner = User::factory()->create(['company_timezone' => 'America/Toronto']);
    $post = $createLegacyPost($owner);
    $service = app(SocialEditorialFoundationBackfillService::class);
    $service->execute();
    $revision = SocialPostRevision::query()->where('social_post_id', $post->id)->sole();
    $consumer = SocialApprovalRequest::query()->create([
        'social_post_id' => $post->id,
        'social_post_revision_id' => $revision->id,
        'requested_by_user_id' => $owner->id,
        'status' => SocialApprovalRequest::STATUS_PENDING,
        'requested_at' => now(),
    ]);

    expect(fn () => $service->rollback())
        ->toThrow(LogicException::class, 'cannot be rolled back after new consumers exist');
    expect($consumer->fresh()->social_post_revision_id)->toBe($revision->id)
        ->and($post->fresh()->current_editorial_revision)->toBe(1)
        ->and(DB::table('social_backfill_batches')->where('state', 'applied')->count())->toBe(1);
});

it('fails closed on cross tenant approval and destination references', function () use (
    $createLegacyConnection,
    $createLegacyPost,
) {
    Queue::fake();
    Http::preventStrayRequests();
    $postOwner = User::factory()->create(['company_timezone' => 'America/Toronto']);
    $foreignOwner = User::factory()->create(['company_timezone' => 'Europe/Paris']);
    $post = $createLegacyPost($postOwner, [
        'content_payload' => ['text' => 'Tenant boundary payload'],
    ]);
    $foreignConnection = $createLegacyConnection($foreignOwner, 'foreign-page');
    SocialPostTarget::withoutEvents(fn (): SocialPostTarget => SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'social_account_connection_id' => $foreignConnection->id,
        'status' => SocialPostTarget::STATUS_PENDING,
    ]));
    SocialApprovalRequest::query()->create([
        'social_post_id' => $post->id,
        'requested_by_user_id' => $foreignOwner->id,
        'status' => SocialApprovalRequest::STATUS_PENDING,
    ]);
    $before = [
        'post' => (array) DB::table('social_posts')->find($post->id),
        'targets' => DB::table('social_post_targets')->get()->map(fn (object $row): array => (array) $row)->all(),
        'approvals' => DB::table('social_approval_requests')->get()->map(fn (object $row): array => (array) $row)->all(),
    ];
    $service = app(SocialEditorialFoundationBackfillService::class);
    $preview = $service->preview();

    expect($preview['ready'])->toBeFalse()
        ->and($preview['posts']['backfillable'])->toBe(0)
        ->and($preview['anomalies']['by_reason'])->toMatchArray([
            'approval_actor_cross_tenant' => 1,
            'target_cross_tenant' => 1,
        ]);
    expect(fn () => $service->execute())
        ->toThrow(LogicException::class, 'approval_actor_cross_tenant=1, target_cross_tenant=1');
    expect([
        'post' => (array) DB::table('social_posts')->find($post->id),
        'targets' => DB::table('social_post_targets')->get()->map(fn (object $row): array => (array) $row)->all(),
        'approvals' => DB::table('social_approval_requests')->get()->map(fn (object $row): array => (array) $row)->all(),
    ])->toBe($before)
        ->and(DB::table('social_post_revisions')->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('refuses ambiguous legacy approval cycles before writing any foundation data', function () use (
    $createLegacyConnection,
    $createLegacyPost,
    $createLegacyTarget,
) {
    Queue::fake();
    $owner = User::factory()->create(['company_timezone' => 'America/Toronto']);
    $post = $createLegacyPost($owner, ['status' => SocialPost::STATUS_PENDING_APPROVAL]);
    $target = $createLegacyTarget(
        $post,
        $createLegacyConnection($owner, 'ambiguous-approval-page'),
    );
    SocialApprovalRequest::query()->create([
        'social_post_id' => $post->id,
        'requested_by_user_id' => $owner->id,
        'resolved_by_user_id' => $owner->id,
        'status' => SocialApprovalRequest::STATUS_REJECTED,
        'requested_at' => now()->subDays(2),
        'rejected_at' => now()->subDay(),
    ]);
    SocialApprovalRequest::query()->create([
        'social_post_id' => $post->id,
        'requested_by_user_id' => $owner->id,
        'status' => SocialApprovalRequest::STATUS_PENDING,
        'requested_at' => now(),
    ]);
    $before = [
        'post' => (array) DB::table('social_posts')->find($post->id),
        'target' => (array) DB::table('social_post_targets')->find($target->id),
        'approvals' => DB::table('social_approval_requests')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
    ];
    $service = app(SocialEditorialFoundationBackfillService::class);
    $preview = $service->preview();

    expect($preview['ready'])->toBeFalse()
        ->and($preview['posts']['backfillable'])->toBe(0)
        ->and($preview['approvals']['backfillable'])->toBe(0)
        ->and($preview['anomalies']['by_reason'])->toBe([
            'approval_history_not_reconstructable' => 1,
        ]);
    expect(fn () => $service->execute())
        ->toThrow(LogicException::class, 'approval_history_not_reconstructable=1');
    expect([
        'post' => (array) DB::table('social_posts')->find($post->id),
        'target' => (array) DB::table('social_post_targets')->find($target->id),
        'approvals' => DB::table('social_approval_requests')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
    ])->toBe($before)
        ->and(DB::table('social_post_revisions')->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('serializes competing editorial foundation writes with one application lock', function () use (
    $createLegacyPost,
) {
    $createLegacyPost(User::factory()->create(['company_timezone' => 'America/Toronto']));
    $lock = Cache::lock('pulse:wp2b-editorial-foundation-backfill', 300);

    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => app(SocialEditorialFoundationBackfillService::class)->execute())
            ->toThrow(LogicException::class, 'already running');
        expect(DB::table('social_post_revisions')->count())->toBe(0);
    } finally {
        $lock->release();
    }
});
