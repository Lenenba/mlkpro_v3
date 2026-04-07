<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stripe_subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('stripe_subscriptions', 'promotion_coupon_id')) {
                $table->string('promotion_coupon_id')->nullable()->after('comped_coupon_id');
            }

            if (! Schema::hasColumn('stripe_subscriptions', 'promotion_discount_percent')) {
                $table->unsignedTinyInteger('promotion_discount_percent')->nullable()->after('promotion_coupon_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stripe_subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('stripe_subscriptions', 'promotion_discount_percent')) {
                $table->dropColumn('promotion_discount_percent');
            }

            if (Schema::hasColumn('stripe_subscriptions', 'promotion_coupon_id')) {
                $table->dropColumn('promotion_coupon_id');
            }
        });
    }
};
