<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'stripe_product_id')) {
                $table->string('stripe_product_id')->nullable()->after('tracking_type');
            }
            if (!Schema::hasColumn('products', 'stripe_price_id')) {
                $table->string('stripe_price_id')->nullable()->after('stripe_product_id');
            }
            if (!Schema::hasColumn('products', 'stripe_price_account_id')) {
                $table->string('stripe_price_account_id')->nullable()->after('stripe_price_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $columns = ['stripe_product_id', 'stripe_price_id', 'stripe_price_account_id'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
