<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_member_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_member_id')->nullable()->constrained('team_members')->nullOnDelete();
            $table->dateTime('clock_in_at');
            $table->dateTime('clock_out_at')->nullable();
            $table->string('method', 20)->default('manual');
            $table->string('clock_out_method', 20)->nullable();
            $table->timestamps();

            $table->index(['account_id', 'user_id', 'clock_in_at']);
            $table->index(['account_id', 'clock_out_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_member_attendances');
    }
};
