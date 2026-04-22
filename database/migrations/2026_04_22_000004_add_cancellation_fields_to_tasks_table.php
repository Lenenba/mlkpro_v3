<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dateTime('cancelled_at')->nullable()->after('completed_at');
            $table->string('cancellation_reason')->nullable()->after('completion_reason');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn([
                'cancelled_at',
                'cancellation_reason',
            ]);
        });
    }
};
