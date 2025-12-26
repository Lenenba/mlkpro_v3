<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('auto_validate_jobs')->default(false)->after('auto_accept_quotes');
            $table->boolean('auto_validate_tasks')->default(false)->after('auto_validate_jobs');
            $table->boolean('auto_validate_invoices')->default(false)->after('auto_validate_tasks');
        });

        DB::table('customers')->update([
            'auto_validate_jobs' => DB::raw('auto_accept_quotes'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'auto_validate_jobs',
                'auto_validate_tasks',
                'auto_validate_invoices',
            ]);
        });
    }
};
