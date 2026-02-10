<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_member_id')->nullable()->constrained('team_members')->cascadeOnDelete();
            $table->unsignedInteger('buffer_minutes')->default(0);
            $table->unsignedInteger('slot_interval_minutes')->default(30);
            $table->unsignedInteger('min_notice_minutes')->default(0);
            $table->unsignedInteger('max_advance_days')->default(90);
            $table->unsignedInteger('cancellation_cutoff_hours')->default(12);
            $table->boolean('allow_client_cancel')->default(true);
            $table->boolean('allow_client_reschedule')->default(true);
            $table->timestamps();

            $table->unique(['account_id', 'team_member_id'], 'reservation_settings_account_team_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_settings');
    }
};

