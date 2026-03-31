<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_scans', function (Blueprint $table) {
            $table->json('ai_reviewed_payload')->nullable()->after('ai_extraction_normalized');
        });
    }

    public function down(): void
    {
        Schema::table('plan_scans', function (Blueprint $table) {
            $table->dropColumn('ai_reviewed_payload');
        });
    }
};
