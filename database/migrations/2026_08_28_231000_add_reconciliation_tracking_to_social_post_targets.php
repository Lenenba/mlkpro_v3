<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'social_post_targets';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $columns = [
            'provider_post_id' => ! Schema::hasColumn(self::TABLE, 'provider_post_id'),
            'provider_status' => ! Schema::hasColumn(self::TABLE, 'provider_status'),
            'submitted_at' => ! Schema::hasColumn(self::TABLE, 'submitted_at'),
            'remote_scheduled_for' => ! Schema::hasColumn(self::TABLE, 'remote_scheduled_for'),
            'last_synced_at' => ! Schema::hasColumn(self::TABLE, 'last_synced_at'),
            'next_reconcile_at' => ! Schema::hasColumn(self::TABLE, 'next_reconcile_at'),
            'reconcile_attempts' => ! Schema::hasColumn(self::TABLE, 'reconcile_attempts'),
            'reconcile_claimed_at' => ! Schema::hasColumn(self::TABLE, 'reconcile_claimed_at'),
            'reconcile_claim_expires_at' => ! Schema::hasColumn(self::TABLE, 'reconcile_claim_expires_at'),
            'reconcile_claimed_by' => ! Schema::hasColumn(self::TABLE, 'reconcile_claimed_by'),
            'reconcile_claim_token' => ! Schema::hasColumn(self::TABLE, 'reconcile_claim_token'),
            'reconcile_claim_version' => ! Schema::hasColumn(self::TABLE, 'reconcile_claim_version'),
            'provider_error_code' => ! Schema::hasColumn(self::TABLE, 'provider_error_code'),
            'provider_error_message' => ! Schema::hasColumn(self::TABLE, 'provider_error_message'),
        ];

        if (in_array(true, $columns, true)) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($columns): void {
                if ($columns['provider_post_id']) {
                    $table->string('provider_post_id', 191)->nullable();
                }

                if ($columns['provider_status']) {
                    $table->string('provider_status', 64)->nullable();
                }

                if ($columns['submitted_at']) {
                    $table->timestamp('submitted_at')->nullable();
                }

                if ($columns['remote_scheduled_for']) {
                    $table->timestamp('remote_scheduled_for')->nullable();
                }

                if ($columns['last_synced_at']) {
                    $table->timestamp('last_synced_at')->nullable();
                }

                if ($columns['next_reconcile_at']) {
                    $table->timestamp('next_reconcile_at')->nullable();
                }

                if ($columns['reconcile_attempts']) {
                    $table->unsignedInteger('reconcile_attempts')->default(0);
                }

                if ($columns['reconcile_claimed_at']) {
                    $table->timestamp('reconcile_claimed_at')->nullable();
                }

                if ($columns['reconcile_claim_expires_at']) {
                    $table->timestamp('reconcile_claim_expires_at')->nullable();
                }

                if ($columns['reconcile_claimed_by']) {
                    $table->string('reconcile_claimed_by', 191)->nullable();
                }

                if ($columns['reconcile_claim_token']) {
                    $table->char('reconcile_claim_token', 36)->nullable();
                }

                if ($columns['reconcile_claim_version']) {
                    $table->unsignedInteger('reconcile_claim_version')->default(0);
                }

                if ($columns['provider_error_code']) {
                    $table->string('provider_error_code', 191)->nullable();
                }

                if ($columns['provider_error_message']) {
                    $table->text('provider_error_message')->nullable();
                }
            });
        }

        if (Schema::hasColumn(self::TABLE, 'next_reconcile_at')
            && Schema::hasColumn(self::TABLE, 'id')
            && ! Schema::hasIndex(self::TABLE, 'spt_reconciliation_due_idx')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(
                    ['next_reconcile_at', 'id'],
                    'spt_reconciliation_due_idx',
                );
            });
        }

        if (Schema::hasColumn(self::TABLE, 'reconcile_claim_expires_at')
            && ! Schema::hasIndex(self::TABLE, 'spt_reconciliation_lease_idx')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(
                    'reconcile_claim_expires_at',
                    'spt_reconciliation_lease_idx',
                );
            });
        }

    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach ([
            'spt_reconciliation_lease_idx',
            'spt_reconciliation_due_idx',
        ] as $index) {
            if (Schema::hasIndex(self::TABLE, $index)) {
                Schema::table(self::TABLE, function (Blueprint $table) use ($index): void {
                    $table->dropIndex($index);
                });
            }
        }

        $columns = collect([
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
        ])->filter(fn (string $column): bool => Schema::hasColumn(self::TABLE, $column));

        if ($columns->isNotEmpty()) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns->all());
            });
        }
    }
};
