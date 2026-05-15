<?php

use App\Models\Customer;
use App\Models\Request as LeadRequest;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_assistant_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(User::class, 'tenant_id')->constrained('users')->cascadeOnDelete();
            $table->string('assistant_name')->default('Malikia AI Assistant');
            $table->boolean('enabled')->default(false);
            $table->string('default_language', 10)->default('fr');
            $table->json('supported_languages');
            $table->string('tone', 40)->default('warm');
            $table->text('greeting_message')->nullable();
            $table->text('fallback_message')->nullable();
            $table->boolean('allow_create_prospect')->default(true);
            $table->boolean('allow_create_client')->default(false);
            $table->boolean('allow_create_reservation')->default(true);
            $table->boolean('allow_reschedule_reservation')->default(false);
            $table->boolean('allow_create_task')->default(false);
            $table->boolean('require_human_validation')->default(true);
            $table->text('business_context')->nullable();
            $table->json('service_area_rules')->nullable();
            $table->json('working_hours_rules')->nullable();
            $table->timestamps();

            $table->unique('tenant_id');
            $table->index(['tenant_id', 'enabled']);
        });

        Schema::create('ai_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(User::class, 'tenant_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('public_uuid')->unique();
            $table->string('channel', 40);
            $table->string('status', 40)->default('open');
            $table->string('visitor_name')->nullable();
            $table->string('visitor_email')->nullable();
            $table->string('visitor_phone')->nullable();
            $table->foreignIdFor(Customer::class, 'client_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignIdFor(LeadRequest::class, 'prospect_id')->nullable()->constrained('requests')->nullOnDelete();
            $table->foreignIdFor(Reservation::class, 'reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->string('detected_language', 10)->nullable();
            $table->string('intent', 80)->nullable();
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->text('summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'channel']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'intent']);
        });

        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->string('sender_type', 40);
            $table->longText('content');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['conversation_id', 'sender_type']);
        });

        Schema::create('ai_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(User::class, 'tenant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->string('action_type', 80);
            $table->string('status', 40)->default('pending');
            $table->json('input_payload');
            $table->json('output_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'action_type']);
            $table->index(['conversation_id', 'status']);
        });

        Schema::create('ai_knowledge_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(User::class, 'tenant_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->longText('content');
            $table->string('category')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge_items');
        Schema::dropIfExists('ai_actions');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('ai_assistant_settings');
    }
};
