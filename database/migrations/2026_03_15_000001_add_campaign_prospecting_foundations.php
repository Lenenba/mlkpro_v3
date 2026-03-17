<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('campaigns')) {
            Schema::table('campaigns', function (Blueprint $table): void {
                if (! Schema::hasColumn('campaigns', 'campaign_direction')) {
                    $table->string('campaign_direction', 40)
                        ->default('customer_marketing')
                        ->after('campaign_type');
                }

                if (! Schema::hasColumn('campaigns', 'prospecting_enabled')) {
                    $table->boolean('prospecting_enabled')
                        ->default(false)
                        ->after('campaign_direction');
                }
            });

            Schema::table('campaigns', function (Blueprint $table): void {
                $table->index(['user_id', 'campaign_direction'], 'campaigns_user_direction_idx');
                $table->index(['user_id', 'prospecting_enabled'], 'campaigns_user_prospecting_idx');
            });
        }

        if (! Schema::hasTable('campaign_prospect_batches')) {
            Schema::create('campaign_prospect_batches', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('source_type', 40);
                $table->string('source_reference', 191)->nullable();
                $table->unsignedInteger('batch_number')->default(1);
                $table->unsignedInteger('input_count')->default(0);
                $table->unsignedInteger('accepted_count')->default(0);
                $table->unsignedInteger('rejected_count')->default(0);
                $table->unsignedInteger('duplicate_count')->default(0);
                $table->unsignedInteger('blocked_count')->default(0);
                $table->unsignedInteger('scored_count')->default(0);
                $table->unsignedInteger('contacted_count')->default(0);
                $table->unsignedInteger('replied_count')->default(0);
                $table->unsignedInteger('lead_count')->default(0);
                $table->unsignedInteger('customer_count')->default(0);
                $table->string('status', 30)->default('draft');
                $table->json('analysis_summary')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();

                $table->unique(['campaign_id', 'batch_number'], 'campaign_prospect_batches_campaign_batch_unique');
                $table->index(['user_id', 'status', 'created_at'], 'campaign_prospect_batches_user_status_created_idx');
            });
        }

        if (! Schema::hasTable('campaign_prospects')) {
            Schema::create('campaign_prospects', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
                $table->foreignId('campaign_prospect_batch_id')->constrained('campaign_prospect_batches')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('source_type', 40);
                $table->string('source_reference', 191)->nullable();
                $table->string('external_ref', 191)->nullable();
                $table->string('company_name', 255)->nullable();
                $table->string('contact_name', 255)->nullable();
                $table->string('first_name', 120)->nullable();
                $table->string('last_name', 120)->nullable();
                $table->string('email', 255)->nullable();
                $table->string('email_normalized', 255)->nullable();
                $table->string('phone', 80)->nullable();
                $table->string('phone_normalized', 80)->nullable();
                $table->string('website', 255)->nullable();
                $table->string('website_domain', 191)->nullable();
                $table->string('city', 120)->nullable();
                $table->string('state', 120)->nullable();
                $table->string('country', 120)->nullable();
                $table->string('industry', 120)->nullable();
                $table->string('company_size', 60)->nullable();
                $table->json('tags')->nullable();
                $table->json('raw_payload')->nullable();
                $table->json('normalized_payload')->nullable();
                $table->unsignedTinyInteger('fit_score')->nullable();
                $table->unsignedTinyInteger('intent_score')->nullable();
                $table->unsignedTinyInteger('priority_score')->nullable();
                $table->text('qualification_summary')->nullable();
                $table->string('status', 40)->default('new');
                $table->string('match_status', 40)->default('none');
                $table->foreignId('matched_customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->foreignId('matched_lead_id')->nullable()->constrained('requests')->nullOnDelete();
                $table->foreignId('converted_to_lead_id')->nullable()->constrained('requests')->nullOnDelete();
                $table->foreignId('converted_to_customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->timestamp('first_contacted_at')->nullable();
                $table->timestamp('last_contacted_at')->nullable();
                $table->timestamp('last_replied_at')->nullable();
                $table->timestamp('last_activity_at')->nullable();
                $table->boolean('do_not_contact')->default(false);
                $table->string('blocked_reason', 120)->nullable();
                $table->text('owner_notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['campaign_id', 'status'], 'campaign_prospects_campaign_status_idx');
                $table->index(['campaign_prospect_batch_id', 'status'], 'campaign_prospects_batch_status_idx');
                $table->index(['user_id', 'email_normalized'], 'campaign_prospects_user_email_idx');
                $table->index(['user_id', 'phone_normalized'], 'campaign_prospects_user_phone_idx');
                $table->index(['user_id', 'website_domain'], 'campaign_prospects_user_domain_idx');
                $table->index(['user_id', 'matched_customer_id'], 'campaign_prospects_user_matched_customer_idx');
                $table->index(['user_id', 'matched_lead_id'], 'campaign_prospects_user_matched_lead_idx');
                $table->index(['user_id', 'priority_score'], 'campaign_prospects_user_priority_idx');
            });
        }

        if (! Schema::hasTable('campaign_prospect_activities')) {
            Schema::create('campaign_prospect_activities', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('campaign_prospect_id')->constrained('campaign_prospects')->cascadeOnDelete();
                $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
                $table->foreignId('campaign_run_id')->nullable()->constrained('campaign_runs')->cascadeOnDelete();
                $table->foreignId('campaign_recipient_id')->nullable()->constrained('campaign_recipients')->nullOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('activity_type', 50);
                $table->string('channel', 20)->nullable();
                $table->string('summary', 255)->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('occurred_at');
                $table->timestamps();

                $table->index(['campaign_prospect_id', 'occurred_at'], 'campaign_prospect_activities_prospect_time_idx');
                $table->index(['campaign_id', 'activity_type', 'occurred_at'], 'campaign_prospect_activities_campaign_type_time_idx');
                $table->index(['campaign_recipient_id', 'activity_type'], 'campaign_prospect_activities_recipient_type_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_prospect_activities');
        Schema::dropIfExists('campaign_prospects');
        Schema::dropIfExists('campaign_prospect_batches');

        if (Schema::hasTable('campaigns')) {
            Schema::table('campaigns', function (Blueprint $table): void {
                if (Schema::hasColumn('campaigns', 'prospecting_enabled')) {
                    $table->dropIndex('campaigns_user_prospecting_idx');
                    $table->dropColumn('prospecting_enabled');
                }

                if (Schema::hasColumn('campaigns', 'campaign_direction')) {
                    $table->dropIndex('campaigns_user_direction_idx');
                    $table->dropColumn('campaign_direction');
                }
            });
        }
    }
};
