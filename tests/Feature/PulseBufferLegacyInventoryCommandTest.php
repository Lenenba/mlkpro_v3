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
    DB::table('failed_jobs')->insert([
        [
            'uuid' => '00000000-0000-0000-0000-000000000001',
            'connection' => 'database',
            'queue' => $socialPublishQueue,
            'payload' => json_encode(['displayName' => PublishSocialPostTargetJob::class]),
            'exception' => 'Expected test failure.',
            'failed_at' => now(),
        ],
        [
            'uuid' => '00000000-0000-0000-0000-000000000002',
            'connection' => 'database',
            'queue' => $socialPublishQueue,
            'payload' => '{"displayName":"PublishSocialPostTargetJob"',
            'exception' => 'Expected malformed test payload.',
            'failed_at' => now(),
        ],
        [
            'uuid' => '00000000-0000-0000-0000-000000000003',
            'connection' => 'database',
            'queue' => $socialPublishQueue,
            'payload' => json_encode([
                'displayName' => 'UnrelatedJob',
                'data' => PublishSocialPostTargetJob::class,
            ]),
            'exception' => 'Expected unrelated test failure.',
            'failed_at' => now(),
        ],
    ]);

    Artisan::call('pulse:buffer:inventory-legacy', [
        '--json' => true,
        '--confirm-read-only-scan' => true,
    ]);
    $output = Artisan::output();
    $inventory = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    expect($inventory)
        ->schema_version->toBe('pulse_legacy_inventory_v2')
        ->scope->toBe('all_tenants_aggregate')
        ->read_only->toBeTrue()
        ->sensitive_fields->toBe('excluded')
        ->capture->toMatchArray([
            'operator_declared_source_context' => 'unspecified',
            'domain' => 'transactional',
            'queue_scopes' => 'sequential_independent_passes',
            'failed_publications' => 'independent_single_pass',
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
        ->failed_publications->toBe([
            'measurable' => true,
            'driver' => 'database-uuids',
            'reason' => null,
            'total' => 1,
            'unparseable_candidates' => 1,
        ])
        ->queue_scope_manifest->toMatchArray([
            'operator_attested_complete_scope_list' => false,
            'scope_count' => 1,
            'measurable_scope_count' => 1,
            'unmeasurable_scope_count' => 0,
        ]);

    expect($inventory['queue_scope_manifest']['scopes'])->toBe([[
        'measurable' => true,
        'queue_connection' => 'database',
        'driver' => 'database',
        'queue_label' => 'social-publish',
        'reason' => null,
        'total' => 4,
        'ready' => 2,
        'delayed' => 1,
        'active_reserved' => 1,
        'expired_reserved' => 1,
        'unparseable_candidates' => 1,
    ]])
        ->and($inventory['capture']['started_at'])->toBeString()->toEndWith('+00:00')
        ->and($inventory['capture']['completed_at'])->toBeString()->toEndWith('+00:00')
        ->and($inventory['capture']['completed_at'])->toBeGreaterThanOrEqual(
            $inventory['capture']['started_at']
        );

    expect($orphanedTarget->fresh()?->social_account_connection_id)->toBeNull()
        ->and(DB::table('failed_jobs')->count())->toBe(3)
        ->and($output)
        ->not->toContain('secret-buffer-token')
        ->not->toContain('secret-facebook-page-id')
        ->not->toContain('secret-other-page-id');

    Artisan::call('pulse:buffer:inventory-legacy', ['--confirm-read-only-scan' => true]);
    $humanOutput = Artisan::output();

    expect($humanOutput)
        ->toContain('Queued publications (database:social-publish)')
        ->toContain('Failed publication jobs')
        ->toContain('retry qualification required')
        ->toContain('Queue scope manifest: 1 inspected; 1 measurable; 0 unmeasurable; scope list completeness not attested; all inspected scopes measurable; failed-job retry qualification required.')
        ->toContain('1 malformed record; 2 invalid; 1 duplicate')
        ->toContain('1 malformed record; 1 invalid; 0 duplicate')
        ->toContain('2 ready; 1 delayed; 1 active reservation; 1 expired reservation; 1 unparseable candidate');
});

it('captures every declared queue scope in one evidence manifest', function () {
    config()->set('queue.default', 'database');
    $nowTimestamp = now()->timestamp;
    DB::table('jobs')->insert([
        'queue' => 'legacy-social-publish',
        'payload' => json_encode(['displayName' => PublishSocialPostTargetJob::class]),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => $nowTimestamp + 300,
        'created_at' => $nowTimestamp,
    ]);

    $exitCode = Artisan::call('pulse:buffer:inventory-legacy', [
        '--json' => true,
        '--source-context' => 'representative-clone',
        '--queue-scope' => [
            'database:social-publish',
            'database:legacy-social-publish',
        ],
        '--confirm-queue-scope-list-complete' => true,
        '--confirm-read-only-scan' => true,
    ]);
    $inventory = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($inventory['capture']['operator_declared_source_context'])->toBe('representative-clone')
        ->and($inventory['queue_scope_manifest'])->toMatchArray([
            'operator_attested_complete_scope_list' => true,
            'scope_count' => 2,
            'measurable_scope_count' => 2,
            'unmeasurable_scope_count' => 0,
        ])
        ->and($inventory['queue_scope_manifest']['scopes'])->toHaveCount(2)
        ->and($inventory['queue_scope_manifest']['scopes'][0])->toMatchArray([
            'measurable' => true,
            'queue_connection' => 'database',
            'driver' => 'database',
            'queue_label' => 'legacy-social-publish',
            'total' => 1,
            'ready' => 0,
            'delayed' => 1,
            'unparseable_candidates' => 0,
        ])
        ->and($inventory['queue_scope_manifest']['scopes'][1])->toMatchArray([
            'measurable' => true,
            'queue_connection' => 'database',
            'driver' => 'database',
            'queue_label' => 'social-publish',
            'total' => 0,
        ]);

    Artisan::call('pulse:buffer:inventory-legacy', [
        '--source-context' => 'representative-clone',
        '--queue-scope' => [
            'database:social-publish',
            'database:legacy-social-publish',
        ],
        '--confirm-queue-scope-list-complete' => true,
        '--confirm-read-only-scan' => true,
    ]);
    $humanOutput = Artisan::output();

    expect($humanOutput)
        ->toContain('Queued publications (database:legacy-social-publish)')
        ->toContain('Queued publications (database:social-publish)')
        ->toContain('Queue scope manifest: 2 inspected; 2 measurable; 0 unmeasurable; scope list attested complete by operator; all inspected scopes measurable; failed-job evidence measurable.');
});

it('rejects malformed or duplicate queue scopes', function (array $queueScopes, string $message) {
    $exitCode = Artisan::call('pulse:buffer:inventory-legacy', [
        '--json' => true,
        '--queue-scope' => $queueScopes,
        '--confirm-read-only-scan' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)
        ->toContain('Invalid queue scope')
        ->toContain($message)
        ->not->toContain('Pulse Buffer legacy inventory');
})->with([
    'missing separator' => [
        ['database-social-publish'],
        'Queue scopes must use the connection:queue format.',
    ],
    'duplicate after normalization' => [
        ['database:social-publish', ' database : social-publish '],
        'Each queue scope must be declared only once.',
    ],
]);

it('rejects complete-list attestation without explicit queue scopes', function () {
    $exitCode = Artisan::call('pulse:buffer:inventory-legacy', [
        '--json' => true,
        '--confirm-queue-scope-list-complete' => true,
        '--confirm-read-only-scan' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)
        ->toContain('A complete queue scope attestation requires at least one explicit --queue-scope value.')
        ->not->toContain('Pulse Buffer legacy inventory');
});

it('rejects database aliases that would double count the same physical queue', function () {
    config()->set('queue.connections.legacy_database', [
        'driver' => 'database',
        'connection' => null,
        'table' => 'jobs',
        'queue' => 'legacy-default',
        'retry_after' => 90,
        'after_commit' => false,
    ]);

    $exitCode = Artisan::call('pulse:buffer:inventory-legacy', [
        '--json' => true,
        '--queue-scope' => [
            'database:social-publish',
            'legacy_database:social-publish',
        ],
        '--confirm-read-only-scan' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)
        ->toContain('Database queue scopes must not alias the same connection, table, and queue.')
        ->not->toContain('Pulse Buffer legacy inventory');
});

it('rejects database queue aliases that differ only by case', function () {
    $exitCode = Artisan::call('pulse:buffer:inventory-legacy', [
        '--json' => true,
        '--queue-scope' => [
            'database:social-publish',
            'database:Social-Publish',
        ],
        '--confirm-read-only-scan' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)
        ->toContain('Database queue scopes must not alias the same connection, table, and queue.')
        ->not->toContain('Pulse Buffer legacy inventory');
});

it('keeps external queue credentials redacted and requires separate evidence', function () {
    config()->set('queue.connections.legacy_sqs', [
        'driver' => 'sqs',
        'key' => 'secret-legacy-key',
        'secret' => 'secret-legacy-token',
        'queue' => 'legacy-default',
    ]);
    $parameters = [
        '--queue-scope' => [
            'legacy_sqs:legacy-social-publish',
            'database:social-publish',
        ],
        '--confirm-queue-scope-list-complete' => true,
        '--confirm-read-only-scan' => true,
    ];

    $exitCode = Artisan::call('pulse:buffer:inventory-legacy', [
        '--json' => true,
        ...$parameters,
    ]);
    $output = Artisan::output();
    $inventory = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($inventory['queue_scope_manifest'])->toMatchArray([
            'operator_attested_complete_scope_list' => true,
            'scope_count' => 2,
            'measurable_scope_count' => 1,
            'unmeasurable_scope_count' => 1,
        ])
        ->and($inventory['queue_scope_manifest']['scopes'][1])->toMatchArray([
            'measurable' => false,
            'queue_connection' => 'legacy_sqs',
            'driver' => 'sqs',
            'queue_label' => 'legacy-social-publish',
            'reason' => 'queue_driver_not_database',
        ])
        ->and($output)->not->toContain('secret-legacy-key')
        ->and($output)->not->toContain('secret-legacy-token');

    Artisan::call('pulse:buffer:inventory-legacy', $parameters);
    $humanOutput = Artisan::output();

    expect($humanOutput)
        ->toContain('1 unmeasurable')
        ->toContain('scope list attested complete by operator')
        ->toContain('external queue evidence required')
        ->not->toContain('secret-legacy-key')
        ->not->toContain('secret-legacy-token');
});

it('reports an unmeasurable queue without inspecting non-database payloads', function () {
    config()->set('queue.default', 'sync');

    Artisan::call('pulse:buffer:inventory-legacy', [
        '--json' => true,
        '--confirm-read-only-scan' => true,
    ]);
    $output = Artisan::output();
    $inventory = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    expect($inventory['queue_scope_manifest']['scopes'][0])->toBe([
        'measurable' => false,
        'queue_connection' => 'sync',
        'driver' => 'sync',
        'queue_label' => 'social-publish',
        'reason' => 'queue_driver_not_database',
        'total' => null,
        'ready' => null,
        'delayed' => null,
        'active_reserved' => null,
        'expired_reserved' => null,
        'unparseable_candidates' => null,
    ]);
});

it('inventories an explicitly selected legacy database queue', function () {
    config()->set('queue.connections.legacy_database', [
        'driver' => 'database',
        'connection' => null,
        'table' => 'jobs',
        'queue' => 'legacy-default',
        'retry_after' => 90,
        'after_commit' => false,
    ]);
    $nowTimestamp = now()->timestamp;
    DB::table('jobs')->insert([
        'queue' => 'legacy-social-publish',
        'payload' => json_encode(['displayName' => PublishSocialPostTargetJob::class]),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => $nowTimestamp + 300,
        'created_at' => $nowTimestamp,
    ]);

    $exitCode = Artisan::call('pulse:buffer:inventory-legacy', [
        '--json' => true,
        '--queue-scope' => ['legacy_database:legacy-social-publish'],
        '--confirm-read-only-scan' => true,
    ]);
    $inventory = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($inventory['queue_scope_manifest']['scopes'][0])->toMatchArray([
            'measurable' => true,
            'queue_connection' => 'legacy_database',
            'driver' => 'database',
            'queue_label' => 'legacy-social-publish',
            'reason' => null,
            'total' => 1,
            'ready' => 0,
            'delayed' => 1,
            'active_reserved' => 0,
            'expired_reserved' => 0,
            'unparseable_candidates' => 0,
        ]);
});

it('reports an explicitly selected external queue without exposing or opening its connection', function () {
    config()->set('queue.connections.legacy_sqs', [
        'driver' => 'sqs',
        'key' => 'secret-legacy-key',
        'secret' => 'secret-legacy-token',
        'queue' => 'legacy-default',
    ]);

    $exitCode = Artisan::call('pulse:buffer:inventory-legacy', [
        '--json' => true,
        '--queue-scope' => ['legacy_sqs:legacy-social-publish'],
        '--confirm-read-only-scan' => true,
    ]);
    $output = Artisan::output();
    $inventory = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($inventory['queue_scope_manifest']['scopes'][0])->toBe([
            'measurable' => false,
            'queue_connection' => 'legacy_sqs',
            'driver' => 'sqs',
            'queue_label' => 'legacy-social-publish',
            'reason' => 'queue_driver_not_database',
            'total' => null,
            'ready' => null,
            'delayed' => null,
            'active_reserved' => null,
            'expired_reserved' => null,
            'unparseable_candidates' => null,
        ])
        ->and($output)
        ->not->toContain('secret-legacy-key')
        ->not->toContain('secret-legacy-token');
});

it('reports failed publication storage as unmeasurable without exposing its configuration', function () {
    config()->set('queue.failed', [
        'driver' => 'dynamodb',
        'key' => 'secret-failed-jobs-key',
        'secret' => 'secret-failed-jobs-token',
        'table' => 'failed_jobs',
    ]);

    $exitCode = Artisan::call('pulse:buffer:inventory-legacy', [
        '--json' => true,
        '--confirm-read-only-scan' => true,
    ]);
    $output = Artisan::output();
    $inventory = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($inventory['failed_publications'])->toBe([
            'measurable' => false,
            'driver' => 'dynamodb',
            'reason' => 'failed_queue_driver_not_database',
            'total' => null,
            'unparseable_candidates' => null,
        ])
        ->and($output)->not->toContain('secret-failed-jobs-key')
        ->and($output)->not->toContain('secret-failed-jobs-token');

    Artisan::call('pulse:buffer:inventory-legacy', [
        '--confirm-read-only-scan' => true,
    ]);
    $humanOutput = Artisan::output();

    expect($humanOutput)
        ->toContain('external queue evidence required')
        ->toContain('failed-job evidence required')
        ->not->toContain('secret-failed-jobs-key')
        ->not->toContain('secret-failed-jobs-token');
});

it('fails closed for an unknown explicit queue connection', function () {
    $exitCode = Artisan::call('pulse:buffer:inventory-legacy', [
        '--json' => true,
        '--queue-scope' => ['missing_legacy_connection:legacy-social-publish'],
        '--confirm-read-only-scan' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)
        ->toContain('Invalid queue scope')
        ->toContain('Queue connection [missing_legacy_connection] is not configured.')
        ->not->toContain('Pulse Buffer legacy inventory');
});

it('fails closed for an invalid explicit queue connection name', function () {
    $exitCode = Artisan::call('pulse:buffer:inventory-legacy', [
        '--json' => true,
        '--queue-scope' => ['legacy.connection:legacy-social-publish'],
        '--confirm-read-only-scan' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)
        ->toContain('Queue connection must use only letters, numbers, underscores, or hyphens.')
        ->not->toContain('Pulse Buffer legacy inventory');
});

it('fails closed when an explicit queue connection has no driver', function () {
    config()->set('queue.connections.legacy_driverless', ['queue' => 'legacy-social-publish']);

    $exitCode = Artisan::call('pulse:buffer:inventory-legacy', [
        '--json' => true,
        '--queue-scope' => ['legacy_driverless:legacy-social-publish'],
        '--confirm-read-only-scan' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)
        ->toContain('Queue connection [legacy_driverless] does not define a driver.')
        ->not->toContain('Pulse Buffer legacy inventory');
});

it('fails closed when an explicit queue name contains control characters', function () {
    $exitCode = Artisan::call('pulse:buffer:inventory-legacy', [
        '--json' => true,
        '--queue-scope' => ["database:legacy\nsocial"],
        '--confirm-read-only-scan' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)
        ->toContain('Queue name must be non-empty, at most 255 characters, and cannot contain control characters.')
        ->not->toContain('Pulse Buffer legacy inventory');
});

it('redacts unsafe queue identifiers while preserving external queue evidence', function () {
    $unsafeQueue = 'https://sqs.us-east-1.amazonaws.com/123456789012/private-social-queue';

    $exitCode = Artisan::call('pulse:buffer:inventory-legacy', [
        '--json' => true,
        '--queue-scope' => ["sqs:{$unsafeQueue}"],
        '--confirm-read-only-scan' => true,
    ]);
    $output = Artisan::output();
    $inventory = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($inventory['queue_scope_manifest']['scopes'][0]['queue_label'])
        ->toMatch('/\Asha256:[a-f0-9]{64}\z/')
        ->and($output)
        ->not->toContain($unsafeQueue)
        ->not->toContain('123456789012');
});

it('limits the number of declared queue scopes before resolving connections', function () {
    $queueScopes = array_map(
        static fn (int $index): string => "missing_{$index}:social-publish",
        range(1, 33),
    );

    $exitCode = Artisan::call('pulse:buffer:inventory-legacy', [
        '--json' => true,
        '--queue-scope' => $queueScopes,
        '--confirm-read-only-scan' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('No more than 32 queue scopes may be declared.');
});

it('rejects an unsupported source context before scanning data', function () {
    $exitCode = Artisan::call('pulse:buffer:inventory-legacy', [
        '--json' => true,
        '--source-context' => 'production-copy',
        '--confirm-read-only-scan' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)
        ->toContain('Inventory source context must be approved-environment, local, representative-clone, or unspecified.')
        ->not->toContain('Pulse Buffer legacy inventory');
});

it('fails closed when the configured database queue table is unavailable', function () {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.table', 'missing_pulse_jobs');

    Artisan::call('pulse:buffer:inventory-legacy', [
        '--json' => true,
        '--confirm-read-only-scan' => true,
    ]);
    $inventory = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($inventory['queue_scope_manifest']['scopes'][0])->toBe([
        'measurable' => false,
        'queue_connection' => 'database',
        'driver' => 'database',
        'queue_label' => 'social-publish',
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
        ->and($inventory['schema_version'])->toBe('pulse_legacy_inventory_v2');
});
