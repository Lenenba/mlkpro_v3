<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reservation_status_transitions')) {
            return;
        }

        Schema::create('reservation_status_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('reservation_id');
            $table->string('event_type', 50);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->string('actor_type', 20);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 50);
            $table->string('reason_code', 80);
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('status_version')->default(0);
            $table->unsignedBigInteger('schedule_version')->default(0);
            $table->string('idempotency_key', 64);
            $table->json('metadata')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->unique(['account_id', 'idempotency_key'], 'rst_account_idempotency_uq');
            $table->index('reservation_id', 'rst_reservation_idx');
            $table->index(['account_id', 'reservation_id', 'occurred_at'], 'rst_account_reservation_time_idx');
            $table->index(['account_id', 'event_type', 'occurred_at'], 'rst_account_event_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_status_transitions');
    }
};
