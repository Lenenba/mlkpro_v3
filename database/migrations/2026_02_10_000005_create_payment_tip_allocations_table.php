<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_tip_allocations')) {
            return;
        }

        Schema::create('payment_tip_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 10, 2)->default(0);
            $table->decimal('percent', 5, 2)->nullable();
            $table->decimal('reversed_amount', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['payment_id', 'user_id']);
            $table->index(['user_id', 'payment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_tip_allocations');
    }
};

