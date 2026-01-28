<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'promo_discount_percent')) {
                $table->decimal('promo_discount_percent', 5, 2)->nullable()->after('price');
            }
            if (!Schema::hasColumn('products', 'promo_start_at')) {
                $table->timestamp('promo_start_at')->nullable()->after('promo_discount_percent');
            }
            if (!Schema::hasColumn('products', 'promo_end_at')) {
                $table->timestamp('promo_end_at')->nullable()->after('promo_start_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'promo_end_at')) {
                $table->dropColumn('promo_end_at');
            }
            if (Schema::hasColumn('products', 'promo_start_at')) {
                $table->dropColumn('promo_start_at');
            }
            if (Schema::hasColumn('products', 'promo_discount_percent')) {
                $table->dropColumn('promo_discount_percent');
            }
        });
    }
};
