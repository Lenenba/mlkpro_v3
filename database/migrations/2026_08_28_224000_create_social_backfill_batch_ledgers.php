<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('social_backfill_batches')) {
            Schema::create('social_backfill_batches', function (Blueprint $table): void {
                $table->id();
                $table->string('operation', 64);
                $table->string('state', 20);
                $table->unsignedInteger('row_count');
                $table->char('manifest_hash', 64);
                $table->timestamp('applied_at');
                $table->timestamp('rolled_back_at')->nullable();
                $table->timestamps();

                $table->index(
                    ['operation', 'state', 'id'],
                    'social_backfill_batches_operation_state_idx'
                );
            });
        }

        if (! Schema::hasTable('social_backfill_batch_entries')) {
            Schema::create('social_backfill_batch_entries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('social_backfill_batch_id')
                    ->constrained('social_backfill_batches')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('workspace_id');
                $table->string('entity_type', 48);
                $table->unsignedBigInteger('entity_id');
                $table->string('mutation', 16);
                $table->char('before_fingerprint', 64)->nullable();
                $table->char('after_fingerprint', 64);
                $table->timestamp('created_at');

                $table->unique(
                    ['social_backfill_batch_id', 'entity_type', 'entity_id'],
                    'social_backfill_entries_batch_entity_uq'
                );
                $table->index(
                    ['entity_type', 'entity_id'],
                    'social_backfill_entries_entity_idx'
                );
                $table->index(
                    ['workspace_id', 'social_backfill_batch_id'],
                    'social_backfill_entries_workspace_batch_idx'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('social_backfill_batches')
            && DB::table('social_backfill_batches')->where('state', 'applied')->exists()) {
            throw new \LogicException(
                'Active Pulse backfill batches must be rolled back before removing their ledger.'
            );
        }

        Schema::dropIfExists('social_backfill_batch_entries');
        Schema::dropIfExists('social_backfill_batches');
    }
};
