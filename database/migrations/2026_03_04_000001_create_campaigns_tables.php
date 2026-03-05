<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audience_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->json('filters')->nullable();
            $table->json('exclusions')->nullable();
            $table->boolean('is_shared')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('audience_segment_id')->nullable()->constrained('audience_segments')->nullOnDelete();
            $table->string('name');
            $table->string('type', 40);
            $table->string('status', 40)->default('draft');
            $table->string('schedule_type', 40)->default('manual');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('locale', 10)->nullable();
            $table->string('cta_url', 1024)->nullable();
            $table->boolean('is_marketing')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->json('settings')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'type']);
            $table->index(['user_id', 'scheduled_at']);
        });

        Schema::create('campaign_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'product_id']);
        });

        Schema::create('campaign_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->boolean('is_enabled')->default(true);
            $table->string('subject_template', 255)->nullable();
            $table->string('title_template', 255)->nullable();
            $table->longText('body_template')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'channel']);
            $table->index(['channel', 'is_enabled']);
        });

        Schema::create('campaign_audiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->json('smart_filters')->nullable();
            $table->json('exclusion_filters')->nullable();
            $table->json('manual_customer_ids')->nullable();
            $table->json('manual_contacts')->nullable();
            $table->json('estimated_counts')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique('campaign_id');
        });

        Schema::create('campaign_automation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('trigger_type', 50);
            $table->json('trigger_config')->nullable();
            $table->unsignedInteger('delay_minutes')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->index(['user_id', 'trigger_type']);
        });

        Schema::create('campaign_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trigger_type', 30)->default('manual');
            $table->string('status', 30)->default('pending');
            $table->string('idempotency_key', 80)->nullable()->unique();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('audience_snapshot')->nullable();
            $table->json('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_run_id')->constrained('campaign_runs')->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('channel', 20);
            $table->string('destination', 255)->nullable();
            $table->string('destination_hash', 128)->nullable();
            $table->string('dedupe_key', 120)->nullable();
            $table->string('status', 30)->default('queued');
            $table->string('provider', 60)->nullable();
            $table->string('provider_message_id', 191)->nullable();
            $table->string('tracking_token', 80)->nullable()->unique();
            $table->string('unsubscribe_token', 80)->nullable()->unique();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['campaign_run_id', 'channel', 'destination_hash'],
                'campaign_recipients_run_channel_destination_unique'
            );
            $table->index(['campaign_run_id', 'status']);
            $table->index(['campaign_id', 'channel', 'status']);
            $table->index(['user_id', 'customer_id', 'channel']);
            $table->index(['provider', 'provider_message_id']);
        });

        Schema::create('campaign_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_recipient_id')->constrained('campaign_recipients')->cascadeOnDelete();
            $table->foreignId('campaign_run_id')->constrained('campaign_runs')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('subject_rendered', 255)->nullable();
            $table->string('title_rendered', 255)->nullable();
            $table->longText('body_rendered')->nullable();
            $table->string('cta_url', 1024)->nullable();
            $table->string('tracked_cta_url', 1024)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique('campaign_recipient_id');
            $table->index(['campaign_run_id', 'channel']);
        });

        Schema::create('campaign_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('campaign_run_id')->nullable()->constrained('campaign_runs')->cascadeOnDelete();
            $table->foreignId('campaign_recipient_id')->nullable()->constrained('campaign_recipients')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('channel', 20)->nullable();
            $table->string('event_type', 50);
            $table->string('provider_message_id', 191)->nullable();
            $table->string('conversion_type', 40)->nullable();
            $table->unsignedBigInteger('conversion_id')->nullable();
            $table->timestamp('occurred_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'event_type', 'occurred_at']);
            $table->index(['campaign_run_id', 'event_type', 'occurred_at']);
            $table->index(['campaign_recipient_id', 'event_type']);
            $table->index(['user_id', 'customer_id', 'occurred_at']);
            $table->index(['provider_message_id']);
        });

        Schema::create('customer_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('status', 20)->default('unknown');
            $table->string('source', 80)->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'customer_id', 'channel']);
            $table->index(['user_id', 'channel', 'status']);
        });

        Schema::create('customer_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('channel', 20);
            $table->string('destination', 255)->nullable();
            $table->string('destination_hash', 128);
            $table->string('reason', 120)->nullable();
            $table->string('source', 80)->nullable();
            $table->timestamp('opted_out_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'channel', 'destination_hash']);
            $table->index(['user_id', 'customer_id', 'channel']);
        });

        Schema::create('customer_interest_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('score_scope', 80);
            $table->unsignedTinyInteger('score')->default(0);
            $table->json('factors')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'customer_id', 'score_scope']);
            $table->index(['user_id', 'score_scope', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_interest_scores');
        Schema::dropIfExists('customer_opt_outs');
        Schema::dropIfExists('customer_consents');
        Schema::dropIfExists('campaign_events');
        Schema::dropIfExists('campaign_messages');
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaign_runs');
        Schema::dropIfExists('campaign_automation_rules');
        Schema::dropIfExists('campaign_audiences');
        Schema::dropIfExists('campaign_channels');
        Schema::dropIfExists('campaign_product');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('audience_segments');
    }
};

