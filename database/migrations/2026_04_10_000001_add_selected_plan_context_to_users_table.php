<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'selected_plan_key')) {
                $table->string('selected_plan_key')->nullable()->after('trial_ends_at');
            }

            if (! Schema::hasColumn('users', 'selected_billing_period')) {
                $table->string('selected_billing_period')->nullable()->after('selected_plan_key');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'selected_billing_period')) {
                $table->dropColumn('selected_billing_period');
            }

            if (Schema::hasColumn('users', 'selected_plan_key')) {
                $table->dropColumn('selected_plan_key');
            }
        });
    }
};
