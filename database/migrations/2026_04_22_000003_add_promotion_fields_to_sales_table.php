<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'promotion_id')) {
                $table->foreignId('promotion_id')->nullable()->after('customer_id')->constrained('promotions')->nullOnDelete();
            }

            if (! Schema::hasColumn('sales', 'discount_source')) {
                $table->string('discount_source')->nullable()->after('discount_total');
            }

            if (! Schema::hasColumn('sales', 'discount_label')) {
                $table->string('discount_label')->nullable()->after('discount_source');
            }

            if (! Schema::hasColumn('sales', 'discount_code')) {
                $table->string('discount_code')->nullable()->after('discount_label');
            }

            if (! Schema::hasColumn('sales', 'discount_type')) {
                $table->string('discount_type')->nullable()->after('discount_code');
            }

            if (! Schema::hasColumn('sales', 'discount_value')) {
                $table->decimal('discount_value', 12, 2)->nullable()->after('discount_type');
            }

            if (! Schema::hasColumn('sales', 'discount_target_type')) {
                $table->string('discount_target_type')->nullable()->after('discount_value');
            }

            if (! Schema::hasColumn('sales', 'discount_target_id')) {
                $table->unsignedBigInteger('discount_target_id')->nullable()->after('discount_target_type');
            }

            if (! Schema::hasColumn('sales', 'pricing_discount_total')) {
                $table->decimal('pricing_discount_total', 12, 2)->default(0)->after('discount_target_id');
            }

            if (! Schema::hasColumn('sales', 'discount_snapshot')) {
                $table->json('discount_snapshot')->nullable()->after('pricing_discount_total');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'promotion_id')) {
                $table->dropConstrainedForeignId('promotion_id');
            }

            $table->dropColumn([
                'discount_source',
                'discount_label',
                'discount_code',
                'discount_type',
                'discount_value',
                'discount_target_type',
                'discount_target_id',
                'pricing_discount_total',
                'discount_snapshot',
            ]);
        });
    }
};
