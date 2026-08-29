<?php

use App\Models\SocialAccountConnection;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('keeps social delivery reconciliation tracking additive idempotent and reversible', function () {
    $migrationPath = database_path(
        'migrations/2026_08_28_231000_add_reconciliation_tracking_to_social_post_targets.php',
    );

    try {
        expect(Artisan::call('migrate:fresh', [
            '--force' => true,
            '--no-interaction' => true,
        ]))->toBe(0);

        $owner = User::factory()->create();
        $connection = SocialAccountConnection::query()->create([
            'user_id' => $owner->id,
            'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
            'label' => 'Reconciliation migration account',
            'external_account_id' => 'reconciliation-migration-account',
            ...pulseDirectTransportIdentity(
                $owner,
                SocialAccountConnection::PLATFORM_FACEBOOK,
                'reconciliation-migration-account',
            ),
            'status' => SocialAccountConnection::STATUS_CONNECTED,
        ]);
        $post = SocialPost::query()->create([
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
            'content_payload' => ['text' => 'Migration preservation'],
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

        /** @var Migration $migration */
        $migration = require $migrationPath;
        $migration->up();
        $migration->up();

        assertSocialDeliveryReconciliationSchema();
        expect($target->fresh()->reconcile_attempts)->toBe(0)
            ->and($target->fresh()->reconcile_claim_version)->toBe(0);

        $migration->down();
        $migration->down();

        expect(Schema::hasColumn('social_post_targets', 'provider_post_id'))->toBeFalse()
            ->and(Schema::hasColumn('social_post_targets', 'next_reconcile_at'))->toBeFalse()
            ->and(Schema::hasIndex('social_post_targets', 'spt_reconciliation_due_idx'))->toBeFalse()
            ->and(DB::table('social_post_targets')->where('id', $target->id)->exists())->toBeTrue();

        $migration->up();
        $migration->up();

        assertSocialDeliveryReconciliationSchema();
        expect(DB::table('social_post_targets')
            ->where('id', $target->id)
            ->value('reconcile_attempts'))->toBe(0);
    } finally {
        Artisan::call('migrate:fresh', [
            '--force' => true,
            '--no-interaction' => true,
        ]);
    }
});

it('keeps outbox reconciliation resolution additive idempotent and reversible', function () {
    $migrationPath = database_path(
        'migrations/2026_08_28_232000_add_reconciliation_resolution_to_social_delivery_outbox.php',
    );

    try {
        expect(Artisan::call('migrate:fresh', [
            '--force' => true,
            '--no-interaction' => true,
        ]))->toBe(0);

        /** @var Migration $migration */
        $migration = require $migrationPath;
        $migration->up();
        $migration->up();

        expect(Schema::hasColumns('social_delivery_outbox', [
            'reconciliation_resolved_at',
            'reconciliation_observed_at',
            'reconciliation_resolution',
            'reconciliation_resolution_source',
        ]))->toBeTrue()
            ->and(Schema::hasIndex(
                'social_delivery_outbox',
                'sdo_active_ambiguity_idx',
            ))->toBeTrue()
            ->and(collect(Schema::getIndexes('social_delivery_outbox'))
                ->firstWhere('name', 'sdo_active_ambiguity_idx')['columns'])
            ->toBe(['status', 'reconciliation_resolved_at']);

        $migration->down();
        $migration->down();

        expect(Schema::hasTable('social_delivery_outbox'))->toBeTrue()
            ->and(Schema::hasColumn(
                'social_delivery_outbox',
                'reconciliation_resolved_at',
            ))->toBeFalse()
            ->and(Schema::hasIndex(
                'social_delivery_outbox',
                'sdo_active_ambiguity_idx',
            ))->toBeFalse();

        $migration->up();
        $migration->up();

        expect(Schema::hasColumn(
            'social_delivery_outbox',
            'reconciliation_resolved_at',
        ))->toBeTrue()
            ->and(Schema::hasIndex(
                'social_delivery_outbox',
                'sdo_active_ambiguity_idx',
            ))->toBeTrue();
    } finally {
        Artisan::call('migrate:fresh', [
            '--force' => true,
            '--no-interaction' => true,
        ]);
    }
});

function assertSocialDeliveryReconciliationSchema(): void
{
    $columns = collect(Schema::getColumns('social_post_targets'))->keyBy('name');
    $indexes = collect(Schema::getIndexes('social_post_targets'))->keyBy('name');

    expect($columns)->toHaveKeys([
        'provider_post_id',
        'provider_status',
        'submitted_at',
        'remote_scheduled_for',
        'last_synced_at',
        'next_reconcile_at',
        'reconcile_attempts',
        'reconcile_claimed_at',
        'reconcile_claim_expires_at',
        'reconcile_claimed_by',
        'reconcile_claim_token',
        'reconcile_claim_version',
        'provider_error_code',
        'provider_error_message',
    ])->and($indexes)->toHaveKeys([
        'spt_reconciliation_due_idx',
        'spt_reconciliation_lease_idx',
    ])->and($indexes['spt_reconciliation_due_idx']['columns'])
        ->toBe(['next_reconcile_at', 'id'])
        ->and($indexes['spt_reconciliation_lease_idx']['columns'])
        ->toBe(['reconcile_claim_expires_at'])
        ->and((bool) $columns['provider_post_id']['nullable'])->toBeTrue()
        ->and((bool) $columns['next_reconcile_at']['nullable'])->toBeTrue()
        ->and(trim((string) $columns['reconcile_attempts']['default'], "'\""))->toBe('0')
        ->and(trim((string) $columns['reconcile_claim_version']['default'], "'\""))->toBe('0');
}
