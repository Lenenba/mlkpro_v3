<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'social_delivery_outbox';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        $missingDependencies = collect([
            'users',
            'social_post_targets',
            'social_post_revisions',
            'social_account_connections',
        ])->reject(fn (string $table): bool => Schema::hasTable($table));

        if ($missingDependencies->isNotEmpty()) {
            throw new LogicException(sprintf(
                'Cannot create the Pulse delivery outbox because required tables are missing: %s.',
                $missingDependencies->implode(', '),
            ));
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('social_post_target_id')
                ->constrained('social_post_targets')
                ->restrictOnDelete();
            $table->foreignId('social_post_revision_id')
                ->constrained('social_post_revisions')
                ->restrictOnDelete();
            $table->foreignId('social_provider_connection_id')
                ->constrained('social_account_connections')
                ->restrictOnDelete();
            $table->string('operation', 16);
            $table->string('delivery_provider', 32);
            $table->string('transport_generation', 32);
            $table->string('logical_destination_key', 71);
            $table->string('external_organization_id_snapshot', 191)->nullable();
            $table->string('external_channel_id_snapshot', 191)->nullable();
            $table->unsignedInteger('editorial_revision');
            $table->unsignedInteger('recovery_generation')->default(0);
            $table->foreignId('supersedes_outbox_id')
                ->nullable()
                ->constrained(self::TABLE)
                ->restrictOnDelete();
            $table->char('idempotency_key', 64);
            $table->string('correlation_key', 64)->nullable();
            $table->char('payload_hash', 64);
            $table->longText('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->string('claimed_by', 191)->nullable();
            $table->char('claim_token', 36)->nullable();
            $table->unsignedInteger('claim_version')->default(0);
            $table->timestamp('request_started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('aggregate_repaired_at')->nullable();
            $table->string('provider_post_id', 191)->nullable();
            $table->string('last_error_category', 64)->nullable();
            $table->string('last_error_code', 191)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key', 'social_delivery_outbox_idempotency_uq');
            $table->unique(
                [
                    'social_post_target_id',
                    'operation',
                    'social_post_revision_id',
                    'recovery_generation',
                ],
                'social_delivery_outbox_target_operation_revision_recovery_uq'
            );
            $table->index(
                ['status', 'available_at'],
                'social_delivery_outbox_claim_idx'
            );
            $table->index('claim_expires_at', 'social_delivery_outbox_recovery_idx');
            $table->index(
                ['status', 'aggregate_repaired_at'],
                'social_delivery_outbox_aggregate_repair_idx'
            );
            $table->index(
                ['user_id', 'status'],
                'social_delivery_outbox_tenant_status_idx'
            );
            $table->index(
                ['social_post_target_id', 'status'],
                'social_delivery_outbox_target_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
