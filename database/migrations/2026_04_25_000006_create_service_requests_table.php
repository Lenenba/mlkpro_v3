<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('prospect_id')->nullable()->constrained('requests')->nullOnDelete();
            $table->string('source', 64)->default('manual_admin');
            $table->string('channel', 64)->nullable();
            $table->string('status', 32)->default('new');
            $table->string('request_type', 64)->nullable();
            $table->string('service_type')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('requester_name')->nullable();
            $table->string('requester_email')->nullable();
            $table->string('requester_phone', 50)->nullable();
            $table->string('street1')->nullable();
            $table->string('street2')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('postal_code', 30)->nullable();
            $table->string('country', 120)->nullable();
            $table->string('source_ref')->nullable();
            $table->json('source_meta')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'source']);
            $table->index(['customer_id', 'created_at']);
            $table->index(['prospect_id', 'created_at']);
            $table->index(['user_id', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_requests');
    }
};
