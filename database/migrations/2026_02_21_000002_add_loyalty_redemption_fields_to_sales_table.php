<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'loyalty_points_redeemed')) {
                $table->integer('loyalty_points_redeemed')->default(0)->after('discount_total');
            }

            if (!Schema::hasColumn('sales', 'loyalty_discount_total')) {
                $table->decimal('loyalty_discount_total', 10, 2)->default(0)->after('loyalty_points_redeemed');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'loyalty_discount_total')) {
                $table->dropColumn('loyalty_discount_total');
            }

            if (Schema::hasColumn('sales', 'loyalty_points_redeemed')) {
                $table->dropColumn('loyalty_points_redeemed');
            }
        });
    }
};

