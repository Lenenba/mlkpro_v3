<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_support_ticket_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('platform_support_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_support_ticket_media');
    }
};
