<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_member_id')->nullable()->constrained('team_members')->nullOnDelete();
            $table->date('date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('type', 20)->default('closed');
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->index(['account_id', 'date']);
            $table->index(['account_id', 'team_member_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_exceptions');
    }
};

