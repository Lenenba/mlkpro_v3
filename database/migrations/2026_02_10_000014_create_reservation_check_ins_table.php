<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reservation_queue_item_id')->constrained('reservation_queue_items')->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->foreignId('client_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('checked_in_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 30)->default('self');
            $table->dateTime('checked_in_at');
            $table->dateTime('grace_deadline_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['account_id', 'reservation_queue_item_id', 'checked_in_at'],
                'reservation_check_ins_account_item_checked_in_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_check_ins');
    }
};

