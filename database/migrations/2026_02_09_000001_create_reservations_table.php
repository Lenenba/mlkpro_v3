<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_member_id')->constrained('team_members')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('client_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rescheduled_from_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->string('source', 30)->default('staff');
            $table->string('timezone', 120)->default('UTC');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedInteger('duration_minutes');
            $table->unsignedInteger('buffer_minutes')->default(0);
            $table->text('internal_notes')->nullable();
            $table->text('client_notes')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'starts_at']);
            $table->index(['account_id', 'team_member_id', 'starts_at']);
            $table->index(['account_id', 'client_user_id', 'starts_at']);
            $table->index(['account_id', 'status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
