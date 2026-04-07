<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_promotions', function (Blueprint $table) {
            if (! Schema::hasColumn('subscription_promotions', 'monthly_discount_percent')) {
                $table->unsignedTinyInteger('monthly_discount_percent')->nullable()->after('is_enabled');
            }

            if (! Schema::hasColumn('subscription_promotions', 'yearly_discount_percent')) {
                $table->unsignedTinyInteger('yearly_discount_percent')->nullable()->after('monthly_discount_percent');
            }

            if (! Schema::hasColumn('subscription_promotions', 'monthly_stripe_coupon_id')) {
                $table->string('monthly_stripe_coupon_id')->nullable()->after('yearly_discount_percent');
            }

            if (! Schema::hasColumn('subscription_promotions', 'yearly_stripe_coupon_id')) {
                $table->string('yearly_stripe_coupon_id')->nullable()->after('monthly_stripe_coupon_id');
            }
        });

        if (Schema::hasColumn('subscription_promotions', 'discount_percent')) {
            DB::table('subscription_promotions')->update([
                'monthly_discount_percent' => DB::raw('COALESCE(monthly_discount_percent, discount_percent)'),
                'yearly_discount_percent' => DB::raw('COALESCE(yearly_discount_percent, discount_percent)'),
            ]);
        }

        if (Schema::hasColumn('subscription_promotions', 'stripe_coupon_id')) {
            DB::table('subscription_promotions')->update([
                'monthly_stripe_coupon_id' => DB::raw('COALESCE(monthly_stripe_coupon_id, stripe_coupon_id)'),
                'yearly_stripe_coupon_id' => DB::raw('COALESCE(yearly_stripe_coupon_id, stripe_coupon_id)'),
            ]);
        }

        Schema::table('subscription_promotions', function (Blueprint $table) {
            if (Schema::hasColumn('subscription_promotions', 'stripe_coupon_id')) {
                $table->dropColumn('stripe_coupon_id');
            }

            if (Schema::hasColumn('subscription_promotions', 'discount_percent')) {
                $table->dropColumn('discount_percent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscription_promotions', function (Blueprint $table) {
            if (! Schema::hasColumn('subscription_promotions', 'discount_percent')) {
                $table->unsignedTinyInteger('discount_percent')->nullable()->after('is_enabled');
            }

            if (! Schema::hasColumn('subscription_promotions', 'stripe_coupon_id')) {
                $table->string('stripe_coupon_id')->nullable()->after('discount_percent');
            }
        });

        DB::table('subscription_promotions')->update([
            'discount_percent' => DB::raw('COALESCE(monthly_discount_percent, yearly_discount_percent)'),
            'stripe_coupon_id' => DB::raw('COALESCE(monthly_stripe_coupon_id, yearly_stripe_coupon_id)'),
        ]);

        Schema::table('subscription_promotions', function (Blueprint $table) {
            if (Schema::hasColumn('subscription_promotions', 'yearly_stripe_coupon_id')) {
                $table->dropColumn('yearly_stripe_coupon_id');
            }

            if (Schema::hasColumn('subscription_promotions', 'monthly_stripe_coupon_id')) {
                $table->dropColumn('monthly_stripe_coupon_id');
            }

            if (Schema::hasColumn('subscription_promotions', 'yearly_discount_percent')) {
                $table->dropColumn('yearly_discount_percent');
            }

            if (Schema::hasColumn('subscription_promotions', 'monthly_discount_percent')) {
                $table->dropColumn('monthly_discount_percent');
            }
        });
    }
};
