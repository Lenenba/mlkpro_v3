<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_queue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('client_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('team_member_id')->nullable()->constrained('team_members')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('item_type', 30)->default('ticket');
            $table->string('source', 30)->default('client');
            $table->string('queue_number', 30)->nullable();
            $table->string('status', 30)->default('checked_in');
            $table->integer('priority')->default(0);
            $table->unsignedInteger('estimated_duration_minutes')->default(60);
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('pre_called_at')->nullable();
            $table->dateTime('called_at')->nullable();
            $table->dateTime('call_expires_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('left_at')->nullable();
            $table->dateTime('skipped_at')->nullable();
            $table->unsignedInteger('position')->nullable();
            $table->unsignedInteger('eta_minutes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['account_id', 'team_member_id', 'status', 'created_at'],
                'reservation_queue_items_account_member_status_idx'
            );
            $table->index(
                ['account_id', 'item_type', 'status', 'created_at'],
                'reservation_queue_items_account_type_status_idx'
            );
            $table->index(
                ['account_id', 'client_user_id', 'status'],
                'reservation_queue_items_account_client_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_queue_items');
    }
};

