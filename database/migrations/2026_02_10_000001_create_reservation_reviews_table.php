<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('client_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('feedback')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique('reservation_id');
            $table->index(['account_id', 'rating']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_reviews');
    }
};
