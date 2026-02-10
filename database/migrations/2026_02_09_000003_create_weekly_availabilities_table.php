<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_member_id')->constrained('team_members')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['account_id', 'team_member_id', 'day_of_week'], 'wa_account_member_day_idx');
            $table->unique(
                ['account_id', 'team_member_id', 'day_of_week', 'start_time', 'end_time'],
                'weekly_availabilities_unique_slot'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_availabilities');
    }
};
