<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 100);
            $table->string('title');
            $table->text('intro')->nullable();
            $table->json('details')->nullable();
            $table->string('action_url')->nullable();
            $table->string('action_label')->nullable();
            $table->string('severity', 20)->default('info');
            $table->string('digest_frequency', 20)->default('immediate');
            $table->string('reference')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'sent_at']);
            $table->index(['category']);
            $table->index(['digest_frequency', 'sent_at']);
            $table->index(['reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_notifications');
    }
};
