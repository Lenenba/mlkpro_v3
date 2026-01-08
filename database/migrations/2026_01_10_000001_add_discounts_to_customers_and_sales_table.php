<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('discount_rate', 5, 2)->default(0)->after('billing_date_rule');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('discount_rate', 5, 2)->default(0)->after('tax_total');
            $table->decimal('discount_total', 12, 2)->default(0)->after('discount_rate');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('discount_rate');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['discount_rate', 'discount_total']);
        });
    }
};
