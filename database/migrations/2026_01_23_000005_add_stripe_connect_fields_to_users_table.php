<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'stripe_connect_account_id')) {
                $table->string('stripe_connect_account_id')->nullable()->after('stripe_customer_id');
            }
            if (!Schema::hasColumn('users', 'stripe_connect_charges_enabled')) {
                $table->boolean('stripe_connect_charges_enabled')->default(false)->after('stripe_connect_account_id');
            }
            if (!Schema::hasColumn('users', 'stripe_connect_payouts_enabled')) {
                $table->boolean('stripe_connect_payouts_enabled')->default(false)->after('stripe_connect_charges_enabled');
            }
            if (!Schema::hasColumn('users', 'stripe_connect_details_submitted')) {
                $table->boolean('stripe_connect_details_submitted')->default(false)->after('stripe_connect_payouts_enabled');
            }
            if (!Schema::hasColumn('users', 'stripe_connect_requirements')) {
                $table->json('stripe_connect_requirements')->nullable()->after('stripe_connect_details_submitted');
            }
            if (!Schema::hasColumn('users', 'stripe_connect_onboarded_at')) {
                $table->timestamp('stripe_connect_onboarded_at')->nullable()->after('stripe_connect_requirements');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = [
                'stripe_connect_account_id',
                'stripe_connect_charges_enabled',
                'stripe_connect_payouts_enabled',
                'stripe_connect_details_submitted',
                'stripe_connect_requirements',
                'stripe_connect_onboarded_at',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
