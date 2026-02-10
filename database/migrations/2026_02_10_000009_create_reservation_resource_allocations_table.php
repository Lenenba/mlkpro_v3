<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_resource_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->foreignId('reservation_resource_id')->constrained('reservation_resources')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->unique(
                ['reservation_id', 'reservation_resource_id'],
                'reservation_resource_alloc_unique'
            );
            $table->index(
                ['account_id', 'reservation_resource_id'],
                'reservation_resource_alloc_account_resource_idx'
            );
            $table->index(
                ['account_id', 'reservation_id'],
                'reservation_resource_alloc_account_reservation_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_resource_allocations');
    }
};

