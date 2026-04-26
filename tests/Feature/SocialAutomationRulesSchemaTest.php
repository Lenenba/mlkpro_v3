<?php

use App\Models\SocialAutomationRule;
use App\Models\SocialAutomationRun;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds pulse social automation rules table with expected columns', function () {
    expect(Schema::hasTable('social_automation_rules'))->toBeTrue()
        ->and(Schema::hasColumns('social_automation_rules', [
            'user_id',
            'created_by_user_id',
            'updated_by_user_id',
            'name',
            'description',
            'is_active',
            'frequency_type',
            'frequency_interval',
            'scheduled_time',
            'timezone',
            'approval_mode',
            'language',
            'content_sources',
            'target_connection_ids',
            'max_posts_per_day',
            'min_hours_between_similar_posts',
            'last_generated_at',
            'next_generation_at',
            'last_error',
            'metadata',
        ]))->toBeTrue()
        ->and(Schema::hasTable('social_automation_runs'))->toBeTrue()
        ->and(Schema::hasColumns('social_automation_runs', [
            'user_id',
            'social_automation_rule_id',
            'social_post_id',
            'status',
            'outcome_code',
            'message',
            'source_type',
            'source_id',
            'metadata',
            'started_at',
            'completed_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('social_posts', 'social_automation_rule_id'))->toBeTrue();
});

it('persists and relates pulse automation rules with generated posts', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'social' => true,
        ],
    ]);

    $lastGeneratedAt = Carbon::parse('2026-04-25 09:15:00');
    $nextGenerationAt = Carbon::parse('2026-04-26 09:00:00');

    $rule = SocialAutomationRule::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Morning product autopilot',
        'description' => 'Publie une suggestion produit le matin.',
        'is_active' => true,
        'frequency_type' => SocialAutomationRule::FREQUENCY_DAILY,
        'frequency_interval' => 1,
        'scheduled_time' => '09:00',
        'timezone' => 'America/Toronto',
        'approval_mode' => SocialAutomationRule::APPROVAL_REQUIRED,
        'language' => 'fr',
        'content_sources' => [
            ['type' => 'product', 'mode' => 'all'],
        ],
        'target_connection_ids' => [41, 52],
        'max_posts_per_day' => 2,
        'min_hours_between_similar_posts' => 36,
        'last_generated_at' => $lastGeneratedAt,
        'next_generation_at' => $nextGenerationAt,
        'metadata' => [
            'day_of_week' => 5,
            'day_of_month' => 25,
        ],
    ]);

    $post = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'source_type' => 'product',
        'source_id' => 11,
        'social_automation_rule_id' => $rule->id,
        'content_payload' => [
            'text' => 'Pulse automation generated this product candidate.',
        ],
        'status' => SocialPost::STATUS_PENDING_APPROVAL,
        'metadata' => [
            'automation' => [
                'rule_id' => $rule->id,
                'content_fingerprint' => sha1('automation-product-11'),
            ],
        ],
    ]);

    $run = SocialAutomationRun::query()->create([
        'user_id' => $owner->id,
        'social_automation_rule_id' => $rule->id,
        'social_post_id' => $post->id,
        'status' => SocialAutomationRun::STATUS_GENERATED,
        'outcome_code' => 'queued_for_approval',
        'message' => 'Generated and queued for approval.',
        'source_type' => 'product',
        'source_id' => 11,
        'metadata' => [
            'content_fingerprint' => sha1('automation-product-11'),
        ],
        'started_at' => $lastGeneratedAt->copy()->subMinute(),
        'completed_at' => $lastGeneratedAt,
    ]);

    $freshRule = $rule->fresh(['generatedPosts', 'runs', 'latestRun', 'user', 'createdBy', 'updatedBy']);
    $freshPost = $post->fresh(['automationRule']);

    expect($freshRule)->not->toBeNull()
        ->and($freshRule->frequency_type)->toBe(SocialAutomationRule::FREQUENCY_DAILY)
        ->and($freshRule->approval_mode)->toBe(SocialAutomationRule::APPROVAL_REQUIRED)
        ->and($freshRule->content_sources)->toEqual([
            ['type' => 'product', 'mode' => 'all'],
        ])
        ->and($freshRule->target_connection_ids)->toBe([41, 52])
        ->and($freshRule->last_generated_at?->equalTo($lastGeneratedAt))->toBeTrue()
        ->and($freshRule->next_generation_at?->equalTo($nextGenerationAt))->toBeTrue()
        ->and($freshRule->generatedPosts)->toHaveCount(1)
        ->and($freshRule->generatedPosts->first()?->is($post))->toBeTrue()
        ->and($freshRule->runs)->toHaveCount(1)
        ->and($freshRule->runs->first()?->is($run))->toBeTrue()
        ->and($freshRule->latestRun?->is($run))->toBeTrue()
        ->and($freshRule->user->is($owner))->toBeTrue()
        ->and($freshRule->createdBy->is($owner))->toBeTrue()
        ->and($freshRule->updatedBy->is($owner))->toBeTrue()
        ->and($owner->socialAutomationRules->first()?->is($rule))->toBeTrue()
        ->and($owner->socialAutomationRuns->first()?->is($run))->toBeTrue()
        ->and($freshPost->automationRule?->is($rule))->toBeTrue();
});
