<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_member_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_member_id')->constrained('team_members')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 120)->nullable();
            $table->text('notes')->nullable();
            $table->date('shift_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('recurrence_group_id', 36)->nullable();
            $table->timestamps();

            $table->index(['account_id', 'shift_date']);
            $table->index(['team_member_id', 'shift_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_member_shifts');
    }
};
