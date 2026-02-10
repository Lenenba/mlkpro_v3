<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_waitlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('client_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('team_member_id')->nullable()->constrained('team_members')->nullOnDelete();
            $table->foreignId('matched_reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->string('status', 30)->default('pending');
            $table->dateTime('requested_start_at')->nullable();
            $table->dateTime('requested_end_at')->nullable();
            $table->unsignedInteger('duration_minutes')->default(60);
            $table->unsignedInteger('party_size')->nullable();
            $table->text('notes')->nullable();
            $table->json('resource_filters')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('released_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(
                ['account_id', 'status', 'requested_start_at'],
                'reservation_waitlists_account_status_start_idx'
            );
            $table->index(
                ['account_id', 'client_user_id', 'status'],
                'reservation_waitlists_account_client_status_idx'
            );
            $table->index(
                ['account_id', 'team_member_id', 'status'],
                'reservation_waitlists_account_member_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_waitlists');
    }
};

