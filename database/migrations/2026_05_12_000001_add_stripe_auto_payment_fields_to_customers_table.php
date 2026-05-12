<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            if (! Schema::hasColumn('customers', 'stripe_customer_id')) {
                $table->string('stripe_customer_id')->nullable()->after('portal_user_id');
                $table->index('stripe_customer_id', 'customers_stripe_customer_id_index');
            }

            if (! Schema::hasColumn('customers', 'stripe_default_payment_method_id')) {
                $table->string('stripe_default_payment_method_id')->nullable()->after('stripe_customer_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            if (Schema::hasColumn('customers', 'stripe_default_payment_method_id')) {
                $table->dropColumn('stripe_default_payment_method_id');
            }

            if (Schema::hasColumn('customers', 'stripe_customer_id')) {
                $table->dropIndex('customers_stripe_customer_id_index');
                $table->dropColumn('stripe_customer_id');
            }
        });
    }
};
