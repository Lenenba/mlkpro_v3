<?php

use App\Jobs\PublishSocialPostTargetJob;
use App\Models\SocialAccountConnection;
use App\Models\SocialAutomationRule;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\SocialPostTemplate;
use App\Models\User;
use App\Support\QueueWorkload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('inventories legacy pulse routing without exposing credentials or remote identifiers', function () {
    config()->set('queue.default', 'database');

    $owner = User::factory()->create(['company_type' => 'services']);
    $otherOwner = User::factory()->create(['company_type' => 'services']);

    $facebook = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Main page',
        'external_account_id' => 'secret-facebook-page-id',
        'credentials' => ['access_token' => 'secret-buffer-token'],
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
    ]);
    $otherConnection = SocialAccountConnection::query()->create([
        'user_id' => $otherOwner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Other page',
        'external_account_id' => 'secret-other-page-id',
        'status' => SocialAccountConnection::STATUS_DRAFT,
        'is_active' => false,
    ]);
    $deletedConnection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_LINKEDIN,
        'label' => 'Deleted page',
        'external_account_id' => 'deleted-page-id',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
    ]);

    $immediatePost = SocialPost::query()->create([
        'user_id' => $owner->id,
        'status' => SocialPost::STATUS_PUBLISHING,
    ]);
    $scheduledPost = SocialPost::query()->create([
        'user_id' => $owner->id,
        'status' => SocialPost::STATUS_SCHEDULED,
        'scheduled_for' => now()->addHour(),
    ]);

    SocialPostTarget::query()->create([
        'social_post_id' => $immediatePost->id,
        'social_account_connection_id' => $facebook->id,
        'status' => SocialPostTarget::STATUS_PENDING,
    ]);
    SocialPostTarget::query()->create([
        'social_post_id' => $immediatePost->id,
        'social_account_connection_id' => $otherConnection->id,
        'status' => SocialPostTarget::STATUS_PENDING,
    ]);
    $orphanedTarget = SocialPostTarget::query()->create([
        'social_post_id' => $immediatePost->id,
        'social_account_connection_id' => $deletedConnection->id,
        'status' => SocialPostTarget::STATUS_FAILED,
    ]);
    SocialPostTarget::query()->create([
        'social_post_id' => $scheduledPost->id,
        'social_account_connection_id' => $facebook->id,
        'status' => SocialPostTarget::STATUS_SCHEDULED,
    ]);
    $deletedConnection->delete();

    SocialAutomationRule::query()->create([
        'user_id' => $owner->id,
        'name' => 'Legacy routing rule',
        'target_connection_ids' => [
            $facebook->id,
            $otherConnection->id,
            999999,
            'broken-reference',
            $facebook->id,
        ],
    ]);
    SocialAutomationRule::query()->create([
        'user_id' => $owner->id,
        'name' => 'Associative legacy routing rule',
        'target_connection_ids' => ['unexpected' => $facebook->id],
    ]);
    SocialPostTemplate::query()->create([
        'user_id' => $owner->id,
        'name' => 'Legacy routing template',
        'metadata' => [
            'selected_target_connection_ids' => [$facebook->id, $otherConnection->id, 888888],
        ],
    ]);
    SocialPostTemplate::query()->create([
        'user_id' => $owner->id,
        'name' => 'Malformed legacy routing template',
        'metadata' => [
            'selected_target_connection_ids' => 'not-an-array',
        ],
    ]);

    $nowTimestamp = now()->timestamp;
    $socialPublishQueue = QueueWorkload::queue('social_publish');
    config()->set('queue.connections.database.retry_after', 90);
    DB::table('jobs')->insert([
        [
            'queue' => $socialPublishQueue,
            'payload' => json_encode(['displayName' => PublishSocialPostTargetJob::class]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $nowTimestamp,
            'created_at' => $nowTimestamp,
        ],
        [
            'queue' => $socialPublishQueue,
            'payload' => json_encode(['displayName' => PublishSocialPostTargetJob::class]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $nowTimestamp + 300,
            'created_at' => $nowTimestamp,
        ],
        [
            'queue' => $socialPublishQueue,
            'payload' => json_encode(['displayName' => PublishSocialPostTargetJob::class]),
            'attempts' => 1,
            'reserved_at' => $nowTimestamp,
            'available_at' => $nowTimestamp,
            'created_at' => $nowTimestamp,
        ],
        [
            'queue' => $socialPublishQueue,
            'payload' => json_encode(['displayName' => PublishSocialPostTargetJob::class]),
            'attempts' => 1,
            'reserved_at' => $nowTimestamp - 120,
            'available_at' => $nowTimestamp - 120,
            'created_at' => $nowTimestamp - 120,
        ],
        [
            'queue' => $socialPublishQueue,
            'payload' => json_encode([
                'displayName' => 'UnrelatedJob',
                'data' => PublishSocialPostTargetJob::class,
            ]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $nowTimestamp,
            'created_at' => $nowTimestamp,
        ],
        [
            'queue' => $socialPublishQueue,
            'payload' => '{"displayName":"PublishSocialPostTargetJob"',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $nowTimestamp,
            'created_at' => $nowTimestamp,
        ],
        [
            'queue' => 'wrong-social-queue',
            'payload' => json_encode(['displayName' => PublishSocialPostTargetJob::class]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $nowTimestamp,
            'created_at' => $nowTimestamp,
        ],
    ]);

    Artisan::call('pulse:buffer:inventory-legacy', [
        '--json' => true,
        '--confirm-read-only-scan' => true,
    ]);
    $output = Artisan::output();
    $inventory = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    expect($inventory)
        ->schema_version->toBe('pulse_legacy_inventory_v1')
        ->scope->toBe('all_tenants_aggregate')
        ->read_only->toBeTrue()
        ->sensitive_fields->toBe('excluded')
        ->capture->toBe([
            'domain' => 'transactional',
            'queued_publications' => 'independent_single_pass',
            'cross_source_atomic' => false,
        ])
        ->connections->toMatchArray([
            'total' => 2,
            'active' => 1,
            'connected' => 1,
            'by_platform' => ['facebook' => 2],
            'by_status' => ['connected' => 1, 'draft' => 1],
        ])
        ->targets->toMatchArray([
            'total' => 4,
            'with_connection' => 3,
            'without_connection' => 1,
            'cross_tenant' => 1,
            'future_scheduled' => 1,
            'by_status' => ['failed' => 1, 'pending' => 2, 'scheduled' => 1],
        ])
        ->references->automation_rules->toMatchArray([
            'records' => 2,
            'records_with_references' => 1,
            'references' => 3,
            'missing_references' => 1,
            'cross_tenant_references' => 1,
            'malformed_records' => 1,
            'invalid_references' => 2,
            'duplicate_references' => 1,
        ])
        ->references->post_templates->toMatchArray([
            'records' => 2,
            'records_with_references' => 1,
            'references' => 3,
            'missing_references' => 1,
            'cross_tenant_references' => 1,
            'malformed_records' => 1,
            'invalid_references' => 1,
            'duplicate_references' => 0,
        ])
        ->queued_publications->toMatchArray([
            'measurable' => true,
            'queue_connection' => 'database',
            'driver' => 'database',
            'queue' => 'social-publish',
            'reason' => null,
            'total' => 4,
            'ready' => 2,
            'delayed' => 1,
            'active_reserved' => 1,
            'expired_reserved' => 1,
            'unparseable_candidates' => 1,
        ]);

    expect($orphanedTarget->fresh()?->social_account_connection_id)->toBeNull()
        ->and($output)
        ->not->toContain('secret-buffer-token')
        ->not->toContain('secret-facebook-page-id')
        ->not->toContain('secret-other-page-id');

    Artisan::call('pulse:buffer:inventory-legacy', ['--confirm-read-only-scan' => true]);
    $humanOutput = Artisan::output();

    expect($humanOutput)
        ->toContain('1 malformed record; 2 invalid; 1 duplicate')
        ->toContain('1 malformed record; 1 invalid; 0 duplicate')
        ->toContain('2 ready; 1 delayed; 1 active reservation; 1 expired reservation; 1 unparseable candidate');
});

it('reports an unmeasurable queue without inspecting non-database payloads', function () {
    config()->set('queue.default', 'sync');

    Artisan::call('pulse:buffer:inventory-legacy', [
        '--json' => true,
        '--confirm-read-only-scan' => true,
    ]);
    $output = Artisan::output();
    $inventory = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    expect($inventory['queued_publications'])->toBe([
        'measurable' => false,
        'queue_connection' => 'sync',
        'driver' => 'sync',
        'queue' => 'social-publish',
        'reason' => 'queue_driver_not_database',
        'total' => null,
        'ready' => null,
        'delayed' => null,
        'active_reserved' => null,
        'expired_reserved' => null,
        'unparseable_candidates' => null,
    ]);
});

it('fails closed when the configured database queue table is unavailable', function () {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.table', 'missing_pulse_jobs');

    Artisan::call('pulse:buffer:inventory-legacy', [
        '--json' => true,
        '--confirm-read-only-scan' => true,
    ]);
    $inventory = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($inventory['queued_publications'])->toBe([
        'measurable' => false,
        'queue_connection' => 'database',
        'driver' => 'database',
        'queue' => 'social-publish',
        'reason' => 'queue_table_unavailable',
        'total' => null,
        'ready' => null,
        'delayed' => null,
        'active_reserved' => null,
        'expired_reserved' => null,
        'unparseable_candidates' => null,
    ]);
});

it('refuses inventory without explicit operator confirmation', function () {
    $exitCode = Artisan::call('pulse:buffer:inventory-legacy', ['--json' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)
        ->toContain('requires explicit operator confirmation')
        ->toContain('--confirm-read-only-scan');
});

it('does not treat an environment override as inventory confirmation', function () {
    $exitCode = Artisan::call('pulse:buffer:inventory-legacy', [
        '--json' => true,
        '--env' => 'local',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)
        ->toContain('requires explicit operator confirmation')
        ->toContain('--confirm-read-only-scan');
});

it('allows an explicitly authorized inventory outside local environments', function () {
    $originalEnvironment = app()->environment();
    $originalConfiguredEnvironment = config('app.env');
    app()->detectEnvironment(fn (): string => 'production');
    config()->set('app.env', 'production');

    try {
        $exitCode = Artisan::call('pulse:buffer:inventory-legacy', [
            '--json' => true,
            '--confirm-read-only-scan' => true,
        ]);
        $inventory = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    } finally {
        app()->detectEnvironment(fn (): string => $originalEnvironment);
        config()->set('app.env', $originalConfiguredEnvironment);
    }

    expect($exitCode)->toBe(0)
        ->and($inventory['schema_version'])->toBe('pulse_legacy_inventory_v1');
});
