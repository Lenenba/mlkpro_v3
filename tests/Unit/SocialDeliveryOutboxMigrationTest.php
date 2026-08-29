<?php

use App\Models\SocialAccountConnection;
use App\Models\SocialDeliveryOutbox;
use App\Models\SocialPost;
use App\Models\SocialPostRevision;
use App\Models\SocialPostTarget;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('keeps the encrypted Pulse delivery outbox additive constrained and reversible', function () {
    $migrationPath = database_path(
        'migrations/2026_08_28_230000_create_social_delivery_outbox_table.php'
    );

    try {
        expect(Artisan::call('migrate:fresh', [
            '--force' => true,
            '--no-interaction' => true,
        ]))->toBe(0);

        /** @var Migration $migration */
        $migration = require $migrationPath;

        $migration->up();
        assertSocialDeliveryOutboxSchema();

        $owner = User::factory()->create();
        $timestamp = now();
        $logicalDestinationKey = 'ldk:v1:'.str_repeat('a', 64);
        $payloadHash = str_repeat('b', 64);
        $connectionId = DB::table('social_account_connections')->insertGetId([
            'user_id' => $owner->id,
            'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
            'label' => 'Outbox migration account',
            'external_account_id' => 'outbox-facebook-page',
            'delivery_provider' => 'buffer',
            'transport_generation' => 'buffer_v1',
            'logical_destination_key' => $logicalDestinationKey,
            'status' => SocialAccountConnection::STATUS_CONNECTED,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $postId = DB::table('social_posts')->insertGetId([
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
            'content_payload' => json_encode(
                ['text' => 'Outbox migration post'],
                JSON_THROW_ON_ERROR
            ),
            'status' => SocialPost::STATUS_DRAFT,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $revisionId = DB::table('social_post_revisions')->insertGetId([
            'user_id' => $owner->id,
            'social_post_id' => $postId,
            'revision_number' => 1,
            'base_content' => json_encode(
                ['content_payload' => ['text' => 'Outbox migration post']],
                JSON_THROW_ON_ERROR
            ),
            'scheduled_timezone' => 'America/Toronto',
            'payload_hash' => $payloadHash,
            'created_by_user_id' => $owner->id,
            'origin' => SocialPostRevision::ORIGIN_COMPOSER,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $targetId = DB::table('social_post_targets')->insertGetId([
            'social_post_id' => $postId,
            'social_account_connection_id' => $connectionId,
            'delivery_provider' => 'buffer',
            'transport_generation' => 'buffer_v1',
            'logical_destination_key' => $logicalDestinationKey,
            'status' => SocialPostTarget::STATUS_PENDING,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $payload = [
            'text' => 'Sensitive unpublished content',
            'channel_options' => ['facebook' => ['link_preview' => true]],
        ];

        $outbox = SocialDeliveryOutbox::query()->create([
            'user_id' => $owner->id,
            'social_post_target_id' => $targetId,
            'social_post_revision_id' => $revisionId,
            'social_provider_connection_id' => $connectionId,
            'operation' => SocialDeliveryOutbox::OPERATION_CREATE,
            'delivery_provider' => 'buffer',
            'transport_generation' => 'buffer_v1',
            'logical_destination_key' => $logicalDestinationKey,
            'external_organization_id_snapshot' => 'buffer-organization-snapshot',
            'external_channel_id_snapshot' => 'buffer-channel-snapshot',
            'editorial_revision' => 1,
            'idempotency_key' => str_repeat('c', 64),
            'correlation_key' => 'pulse-correlation-1',
            'payload_hash' => $payloadHash,
            'payload' => $payload,
            'available_at' => $timestamp,
        ]);

        $storedPayload = DB::table('social_delivery_outbox')
            ->where('id', $outbox->id)
            ->value('payload');
        $freshOutbox = SocialDeliveryOutbox::query()->findOrFail($outbox->id);

        expect($storedPayload)->toBeString()
            ->not->toContain('Sensitive unpublished content')
            ->and($freshOutbox->payload)->toBe($payload)
            ->and($freshOutbox->editorial_revision)->toBe(1)
            ->and($freshOutbox->external_organization_id_snapshot)
            ->toBe('buffer-organization-snapshot')
            ->and($freshOutbox->external_channel_id_snapshot)->toBe('buffer-channel-snapshot')
            ->and($freshOutbox->recovery_generation)->toBe(0)
            ->and($freshOutbox->attempts)->toBe(0)
            ->and($freshOutbox->claim_version)->toBe(0)
            ->and($freshOutbox->available_at?->toDateTimeString())->toBe($timestamp->toDateTimeString())
            ->and($freshOutbox->toArray())->not->toHaveKeys(['payload', 'claim_token'])
            ->and($freshOutbox->user()->is($owner))->toBeTrue()
            ->and($freshOutbox->socialPostTarget()->is(SocialPostTarget::query()->findOrFail($targetId)))
            ->toBeTrue()
            ->and($freshOutbox->socialPostRevision()->is(SocialPostRevision::query()->findOrFail($revisionId)))
            ->toBeTrue()
            ->and($freshOutbox->socialProviderConnection()->is(
                SocialAccountConnection::query()->findOrFail($connectionId)
            ))->toBeTrue();

        $freshOutbox->payload = ['text' => 'Mutated content'];

        expect(fn () => $freshOutbox->save())
            ->toThrow(\LogicException::class, 'identity cannot be changed');

        $migration->down();
        $migration->down();

        expect(Schema::hasTable('social_delivery_outbox'))->toBeFalse();

        $migration->up();
        $migration->up();

        assertSocialDeliveryOutboxSchema();
    } finally {
        Artisan::call('migrate:fresh', [
            '--force' => true,
            '--no-interaction' => true,
        ]);
    }
});

it('fails explicitly instead of recording a migration without its required tables', function () {
    $migrationPath = database_path(
        'migrations/2026_08_28_230000_create_social_delivery_outbox_table.php'
    );

    try {
        expect(Artisan::call('migrate:fresh', [
            '--force' => true,
            '--no-interaction' => true,
        ]))->toBe(0);

        /** @var Migration $migration */
        $migration = require $migrationPath;
        $migration->down();
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('social_post_revisions');
        Schema::enableForeignKeyConstraints();

        expect(fn () => $migration->up())
            ->toThrow(LogicException::class, 'required tables are missing: social_post_revisions');
        expect(Schema::hasTable('social_delivery_outbox'))->toBeFalse();
    } finally {
        Schema::enableForeignKeyConstraints();
        Artisan::call('migrate:fresh', [
            '--force' => true,
            '--no-interaction' => true,
        ]);
    }
});

function assertSocialDeliveryOutboxSchema(): void
{
    expect(Schema::hasTable('social_delivery_outbox'))->toBeTrue();

    $columns = collect(Schema::getColumns('social_delivery_outbox'))->keyBy('name');
    $indexes = collect(Schema::getIndexes('social_delivery_outbox'))->keyBy('name');
    $foreignKeys = collect(Schema::getForeignKeys('social_delivery_outbox'))
        ->keyBy(fn (array $foreignKey): string => $foreignKey['columns'][0]);

    expect($columns)->toHaveKeys([
        'id',
        'user_id',
        'social_post_target_id',
        'social_post_revision_id',
        'social_provider_connection_id',
        'operation',
        'delivery_provider',
        'transport_generation',
        'logical_destination_key',
        'external_organization_id_snapshot',
        'external_channel_id_snapshot',
        'editorial_revision',
        'recovery_generation',
        'supersedes_outbox_id',
        'idempotency_key',
        'correlation_key',
        'payload_hash',
        'payload',
        'status',
        'attempts',
        'available_at',
        'claimed_at',
        'claim_expires_at',
        'claimed_by',
        'claim_token',
        'claim_version',
        'request_started_at',
        'submitted_at',
        'processed_at',
        'aggregate_repaired_at',
        'provider_post_id',
        'last_error_category',
        'last_error_code',
        'last_error_message',
        'created_at',
        'updated_at',
    ])->and(trim((string) $columns['recovery_generation']['default'], "'"))->toBe('0')
        ->and(trim((string) $columns['status']['default'], "'"))->toBe('pending')
        ->and(trim((string) $columns['attempts']['default'], "'"))->toBe('0')
        ->and(trim((string) $columns['claim_version']['default'], "'"))->toBe('0')
        ->and((bool) $columns['external_organization_id_snapshot']['nullable'])->toBeTrue()
        ->and((bool) $columns['external_channel_id_snapshot']['nullable'])->toBeTrue()
        ->and((bool) $columns['supersedes_outbox_id']['nullable'])->toBeTrue()
        ->and((bool) $columns['correlation_key']['nullable'])->toBeTrue()
        ->and((bool) $columns['aggregate_repaired_at']['nullable'])->toBeTrue()
        ->and((bool) $columns['payload']['nullable'])->toBeFalse();

    expect($indexes['social_delivery_outbox_idempotency_uq']['columns'])
        ->toBe(['idempotency_key'])
        ->and($indexes['social_delivery_outbox_idempotency_uq']['unique'])->toBeTrue()
        ->and($indexes['social_delivery_outbox_target_operation_revision_recovery_uq']['columns'])
        ->toBe([
            'social_post_target_id',
            'operation',
            'social_post_revision_id',
            'recovery_generation',
        ])
        ->and($indexes['social_delivery_outbox_target_operation_revision_recovery_uq']['unique'])
        ->toBeTrue()
        ->and($indexes['social_delivery_outbox_claim_idx']['columns'])
        ->toBe(['status', 'available_at'])
        ->and($indexes['social_delivery_outbox_recovery_idx']['columns'])
        ->toBe(['claim_expires_at'])
        ->and($indexes['social_delivery_outbox_aggregate_repair_idx']['columns'])
        ->toBe(['status', 'aggregate_repaired_at'])
        ->and($indexes['social_delivery_outbox_tenant_status_idx']['columns'])
        ->toBe(['user_id', 'status'])
        ->and($indexes['social_delivery_outbox_target_status_idx']['columns'])
        ->toBe(['social_post_target_id', 'status']);

    expect($foreignKeys)->toHaveKeys([
        'user_id',
        'social_post_target_id',
        'social_post_revision_id',
        'social_provider_connection_id',
        'supersedes_outbox_id',
    ])
        ->and($foreignKeys['user_id']['foreign_table'])->toBe('users')
        ->and($foreignKeys['social_post_target_id']['foreign_table'])->toBe('social_post_targets')
        ->and($foreignKeys['social_post_revision_id']['foreign_table'])->toBe('social_post_revisions')
        ->and($foreignKeys['social_provider_connection_id']['foreign_table'])
        ->toBe('social_account_connections')
        ->and($foreignKeys['supersedes_outbox_id']['foreign_table'])
        ->toBe('social_delivery_outbox');

    $foreignKeys->each(function (array $foreignKey): void {
        expect(strtolower((string) $foreignKey['on_delete']))->toBeIn(['restrict', 'no action']);
    });

    if (DB::connection()->getDriverName() === 'mysql') {
        expect(strtolower((string) $columns['idempotency_key']['type']))->toBe('char(64)')
            ->and(strtolower((string) $columns['payload_hash']['type']))->toBe('char(64)')
            ->and(strtolower((string) $columns['payload']['type_name']))->toBe('longtext');
    }
}
