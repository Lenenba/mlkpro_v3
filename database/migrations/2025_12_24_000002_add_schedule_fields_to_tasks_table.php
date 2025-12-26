<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('work_id')
                ->nullable()
                ->after('product_id')
                ->constrained('works')
                ->nullOnDelete();
            $table->time('start_time')->nullable()->after('due_date');
            $table->time('end_time')->nullable()->after('start_time');

            $table->index(['work_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['work_id', 'due_date']);
            $table->dropForeign(['work_id']);
            $table->dropColumn(['work_id', 'start_time', 'end_time']);
        });
    }
};
