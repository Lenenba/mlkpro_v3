<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->unique(['invoice_id', 'idempotency_key'], 'payments_invoice_idempotency_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_invoice_idempotency_unique');
            $table->dropColumn(['idempotency_key', 'request_fingerprint']);
        });
    }
};
