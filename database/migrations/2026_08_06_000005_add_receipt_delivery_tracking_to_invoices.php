<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoices', 'receipt_delivery_status')) {
                $table->string('receipt_delivery_status', 20)
                    ->nullable()
                    ->after('receipt_delivery');
            }

            if (! Schema::hasColumn('invoices', 'receipt_delivery_queued_at')) {
                $table->timestamp('receipt_delivery_queued_at')
                    ->nullable()
                    ->after('receipt_delivery_status');
            }

            if (! Schema::hasColumn('invoices', 'receipt_delivery_attempts')) {
                $table->unsignedSmallInteger('receipt_delivery_attempts')
                    ->default(0)
                    ->after('receipt_delivery_queued_at');
            }

            if (! Schema::hasColumn('invoices', 'receipt_delivery_started_at')) {
                $table->timestamp('receipt_delivery_started_at')
                    ->nullable()
                    ->after('receipt_delivery_queued_at');
            }

            if (! Schema::hasColumn('invoices', 'receipt_delivery_claim_token')) {
                $table->uuid('receipt_delivery_claim_token')
                    ->nullable()
                    ->after('receipt_delivery_started_at');
            }

            if (! Schema::hasColumn('invoices', 'receipt_delivery_last_error')) {
                $table->string('receipt_delivery_last_error', 255)
                    ->nullable()
                    ->after('receipt_delivery_attempts');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('invoices', 'receipt_delivery_status') ? 'receipt_delivery_status' : null,
                Schema::hasColumn('invoices', 'receipt_delivery_queued_at') ? 'receipt_delivery_queued_at' : null,
                Schema::hasColumn('invoices', 'receipt_delivery_started_at') ? 'receipt_delivery_started_at' : null,
                Schema::hasColumn('invoices', 'receipt_delivery_claim_token') ? 'receipt_delivery_claim_token' : null,
                Schema::hasColumn('invoices', 'receipt_delivery_attempts') ? 'receipt_delivery_attempts' : null,
                Schema::hasColumn('invoices', 'receipt_delivery_last_error') ? 'receipt_delivery_last_error' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
