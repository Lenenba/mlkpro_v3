<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'tip_reversed_amount')) {
                $table->decimal('tip_reversed_amount', 10, 2)->default(0)->after('tip_amount');
            }
            if (!Schema::hasColumn('payments', 'tip_reversed_at')) {
                $table->timestamp('tip_reversed_at')->nullable()->after('charged_total');
            }
            if (!Schema::hasColumn('payments', 'tip_reversal_rule')) {
                $table->string('tip_reversal_rule', 20)->nullable()->after('tip_reversed_at');
            }
            if (!Schema::hasColumn('payments', 'tip_reversal_reason')) {
                $table->string('tip_reversal_reason', 255)->nullable()->after('tip_reversal_rule');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            foreach (['tip_reversed_amount', 'tip_reversed_at', 'tip_reversal_rule', 'tip_reversal_reason'] as $column) {
                if (Schema::hasColumn('payments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

