<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('works', function (Blueprint $table) {
            $table->string('billing_mode')->nullable()->after('total');
            $table->string('billing_cycle')->nullable()->after('billing_mode');
            $table->string('billing_grouping')->nullable()->after('billing_cycle');
            $table->unsignedSmallInteger('billing_delay_days')->nullable()->after('billing_grouping');
            $table->string('billing_date_rule')->nullable()->after('billing_delay_days');
        });
    }

    public function down(): void
    {
        Schema::table('works', function (Blueprint $table) {
            $table->dropColumn([
                'billing_mode',
                'billing_cycle',
                'billing_grouping',
                'billing_delay_days',
                'billing_date_rule',
            ]);
        });
    }
};
