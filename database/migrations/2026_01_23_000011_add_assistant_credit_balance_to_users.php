<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'assistant_credit_balance')) {
                $table->unsignedInteger('assistant_credit_balance')->default(0)->after('company_limits');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'assistant_credit_balance')) {
                $table->dropColumn('assistant_credit_balance');
            }
        });
    }
};
