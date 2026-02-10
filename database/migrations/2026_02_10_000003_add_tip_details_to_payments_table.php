<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'tip_type')) {
                $table->string('tip_type', 20)->nullable()->after('tip_amount');
            }
            if (!Schema::hasColumn('payments', 'tip_percent')) {
                $table->decimal('tip_percent', 5, 2)->nullable()->after('tip_type');
            }
            if (!Schema::hasColumn('payments', 'tip_base_amount')) {
                $table->decimal('tip_base_amount', 10, 2)->nullable()->after('tip_percent');
            }
            if (!Schema::hasColumn('payments', 'charged_total')) {
                $table->decimal('charged_total', 10, 2)->nullable()->after('tip_base_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $dropColumns = [];
            foreach (['tip_type', 'tip_percent', 'tip_base_amount', 'charged_total'] as $column) {
                if (Schema::hasColumn('payments', $column)) {
                    $dropColumns[] = $column;
                }
            }

            if ($dropColumns) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};

