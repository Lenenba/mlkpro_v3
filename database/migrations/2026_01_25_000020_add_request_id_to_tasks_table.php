<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('request_id')
                ->nullable()
                ->after('work_id')
                ->constrained('requests')
                ->nullOnDelete();

            $table->index(['request_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['request_id', 'due_date']);
            $table->dropConstrainedForeignId('request_id');
        });
    }
};
