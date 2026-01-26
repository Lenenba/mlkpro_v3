<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stripe_subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('stripe_subscriptions', 'is_comped')) {
                $table->boolean('is_comped')->default(false)->after('price_id');
            }
            if (!Schema::hasColumn('stripe_subscriptions', 'comped_coupon_id')) {
                $table->string('comped_coupon_id')->nullable()->after('is_comped');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stripe_subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('stripe_subscriptions', 'comped_coupon_id')) {
                $table->dropColumn('comped_coupon_id');
            }
            if (Schema::hasColumn('stripe_subscriptions', 'is_comped')) {
                $table->dropColumn('is_comped');
            }
        });
    }
};
