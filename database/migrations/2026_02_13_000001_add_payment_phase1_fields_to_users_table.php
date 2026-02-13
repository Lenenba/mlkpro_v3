<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'default_payment_method')) {
                $table->string('default_payment_method', 50)->nullable()->after('payment_methods');
            }

            if (!Schema::hasColumn('users', 'cash_allowed_contexts')) {
                $table->json('cash_allowed_contexts')->nullable()->after('default_payment_method');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'cash_allowed_contexts')) {
                $table->dropColumn('cash_allowed_contexts');
            }

            if (Schema::hasColumn('users', 'default_payment_method')) {
                $table->dropColumn('default_payment_method');
            }
        });
    }
};

