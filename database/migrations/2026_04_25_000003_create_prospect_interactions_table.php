<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospect_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 60);
            $table->text('description')->nullable();
            $table->string('source_type', 160)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_mime')->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->timestamp('next_action_at')->nullable();
            $table->string('next_action_label')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['request_id', 'created_at']);
            $table->index(['request_id', 'type']);
            $table->index(['request_id', 'source_type', 'source_id'], 'prospect_interactions_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospect_interactions');
    }
};
