<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'reservation_queue_payment_attempts';

    /**
     * MySQL does not roll back table creation when a later ALTER TABLE fails.
     * Keep this migration restartable so a deployment interrupted while adding
     * constraints can safely finish without dropping payment-attempt data.
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->createTable();

            return;
        }

        $this->assertExistingTableIsComplete();
        $this->addMissingIndexes();
        $this->addMissingForeignKeys();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function createTable(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id');
            $table->string('active_key')->nullable();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('reservation_queue_item_id');
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->string('provider', 30)->default('stripe');
            $table->string('status', 30);
            $table->string('request_fingerprint', 64);
            $table->uuid('idempotency_key');
            $table->string('stripe_checkout_session_id')->nullable();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('stripe_account_id')->nullable();
            $table->text('checkout_url')->nullable();
            $table->decimal('amount', 12, 2);
            $table->decimal('tip_amount', 12, 2)->default(0);
            $table->string('tip_type', 20)->default('none');
            $table->decimal('tip_percent', 8, 2)->nullable();
            $table->decimal('tip_base_amount', 12, 2)->default(0);
            $table->unsignedBigInteger('tip_assignee_user_id')->nullable();
            $table->decimal('charged_total', 12, 2);
            $table->string('currency_code', 3);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $this->defineIndexes($table);
            $this->defineForeignKeys($table);
        });
    }

    private function assertExistingTableIsComplete(): void
    {
        $required = [
            'id',
            'public_id',
            'active_key',
            'account_id',
            'reservation_queue_item_id',
            'invoice_id',
            'payment_id',
            'provider',
            'status',
            'request_fingerprint',
            'idempotency_key',
            'stripe_checkout_session_id',
            'stripe_payment_intent_id',
            'stripe_account_id',
            'checkout_url',
            'amount',
            'tip_amount',
            'tip_type',
            'tip_percent',
            'tip_base_amount',
            'tip_assignee_user_id',
            'charged_total',
            'currency_code',
            'expires_at',
            'completed_at',
            'cancelled_at',
            'last_verified_at',
            'last_error',
            'metadata',
            'created_at',
            'updated_at',
        ];
        $missing = array_values(array_diff($required, Schema::getColumnListing(self::TABLE)));

        if ($missing !== []) {
            throw new \RuntimeException(sprintf(
                'The existing %s table is incomplete; missing columns: %s.',
                self::TABLE,
                implode(', ', $missing)
            ));
        }
    }

    private function addMissingIndexes(): void
    {
        $definitions = [
            [['public_id'], 'rqpa_public_id_uq', 'unique'],
            [['active_key'], 'rqpa_active_key_uq', 'unique'],
            [['idempotency_key'], 'rqpa_idempotency_uq', 'unique'],
            [['stripe_checkout_session_id'], 'rqpa_checkout_session_uq', 'unique'],
            [['stripe_payment_intent_id'], 'rqpa_payment_intent_uq', 'unique'],
            [['account_id'], 'rqpa_account_idx', 'index'],
            [['reservation_queue_item_id'], 'rqpa_item_idx', 'index'],
            [['invoice_id'], 'rqpa_invoice_idx', 'index'],
            [['payment_id'], 'rqpa_payment_idx', 'index'],
            [['tip_assignee_user_id'], 'rqpa_tip_user_idx', 'index'],
            [['reservation_queue_item_id', 'status'], 'rqpa_item_status_idx', 'index'],
            [['invoice_id', 'status'], 'rqpa_invoice_status_idx', 'index'],
        ];

        foreach ($definitions as [$columns, $name, $type]) {
            if (Schema::hasIndex(self::TABLE, $columns, $type === 'unique' ? 'unique' : null)) {
                continue;
            }

            Schema::table(self::TABLE, function (Blueprint $table) use ($columns, $name, $type): void {
                if ($type === 'unique') {
                    $table->unique($columns, $name);

                    return;
                }

                $table->index($columns, $name);
            });
        }
    }

    private function addMissingForeignKeys(): void
    {
        $existingColumns = array_map(
            static fn (array $foreignKey): array => $foreignKey['columns'],
            Schema::getForeignKeys(self::TABLE)
        );
        $definitions = [
            ['account_id', 'users', 'rqpa_account_fk', 'cascade'],
            ['reservation_queue_item_id', 'reservation_queue_items', 'rqpa_item_fk', 'cascade'],
            ['invoice_id', 'invoices', 'rqpa_invoice_fk', 'cascade'],
            ['payment_id', 'payments', 'rqpa_payment_fk', 'set null'],
            ['tip_assignee_user_id', 'users', 'rqpa_tip_user_fk', 'set null'],
        ];

        foreach ($definitions as [$column, $foreignTable, $name, $onDelete]) {
            if (in_array([$column], $existingColumns, true)) {
                continue;
            }

            Schema::table(self::TABLE, function (Blueprint $table) use ($column, $foreignTable, $name, $onDelete): void {
                $foreign = $table->foreign($column, $name)
                    ->references('id')
                    ->on($foreignTable);

                if ($onDelete === 'cascade') {
                    $foreign->cascadeOnDelete();
                } else {
                    $foreign->nullOnDelete();
                }
            });
        }
    }

    private function defineIndexes(Blueprint $table): void
    {
        $table->unique('public_id', 'rqpa_public_id_uq');
        $table->unique('active_key', 'rqpa_active_key_uq');
        $table->unique('idempotency_key', 'rqpa_idempotency_uq');
        $table->unique('stripe_checkout_session_id', 'rqpa_checkout_session_uq');
        $table->unique('stripe_payment_intent_id', 'rqpa_payment_intent_uq');
        $table->index('account_id', 'rqpa_account_idx');
        $table->index('reservation_queue_item_id', 'rqpa_item_idx');
        $table->index('invoice_id', 'rqpa_invoice_idx');
        $table->index('payment_id', 'rqpa_payment_idx');
        $table->index('tip_assignee_user_id', 'rqpa_tip_user_idx');
        $table->index(['reservation_queue_item_id', 'status'], 'rqpa_item_status_idx');
        $table->index(['invoice_id', 'status'], 'rqpa_invoice_status_idx');
    }

    private function defineForeignKeys(Blueprint $table): void
    {
        $table->foreign('account_id', 'rqpa_account_fk')
            ->references('id')
            ->on('users')
            ->cascadeOnDelete();
        $table->foreign('reservation_queue_item_id', 'rqpa_item_fk')
            ->references('id')
            ->on('reservation_queue_items')
            ->cascadeOnDelete();
        $table->foreign('invoice_id', 'rqpa_invoice_fk')
            ->references('id')
            ->on('invoices')
            ->cascadeOnDelete();
        $table->foreign('payment_id', 'rqpa_payment_fk')
            ->references('id')
            ->on('payments')
            ->nullOnDelete();
        $table->foreign('tip_assignee_user_id', 'rqpa_tip_user_fk')
            ->references('id')
            ->on('users')
            ->nullOnDelete();
    }
};
