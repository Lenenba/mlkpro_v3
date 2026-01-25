<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'payment_provider')) {
                $table->string('payment_provider')->nullable()->after('status');
            }
            if (!Schema::hasColumn('sales', 'stripe_payment_intent_id')) {
                $table->string('stripe_payment_intent_id')->nullable()->after('payment_provider');
                $table->index('stripe_payment_intent_id', 'sales_stripe_payment_intent_id_index');
            }
            if (!Schema::hasColumn('sales', 'stripe_checkout_session_id')) {
                $table->string('stripe_checkout_session_id')->nullable()->after('stripe_payment_intent_id');
                $table->index('stripe_checkout_session_id', 'sales_stripe_checkout_session_id_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'stripe_checkout_session_id')) {
                $table->dropIndex('sales_stripe_checkout_session_id_index');
                $table->dropColumn('stripe_checkout_session_id');
            }
            if (Schema::hasColumn('sales', 'stripe_payment_intent_id')) {
                $table->dropIndex('sales_stripe_payment_intent_id_index');
                $table->dropColumn('stripe_payment_intent_id');
            }
            if (Schema::hasColumn('sales', 'payment_provider')) {
                $table->dropColumn('payment_provider');
            }
        });
    }
};
