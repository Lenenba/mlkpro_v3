<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->default('before');
            $table->string('path');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['work_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_media');
    }
};

