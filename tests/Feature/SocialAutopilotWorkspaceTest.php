<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Jobs\PublishSocialPostTargetJob;
use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\SocialApprovalRequest;
use App\Models\SocialAutomationRule;
use App\Models\SocialAutomationRun;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\SocialPostTemplate;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function pulseAutopilotRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function pulseAutopilotOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => pulseAutopilotRoleId('owner'),
        'email' => 'pulse-autopilot-owner-'.Str::lower(Str::random(10)).'@example.com',
        'company_type' => 'services',
        'company_sector' => 'service_general',
        'onboarding_completed_at' => now(),
        'company_timezone' => 'America/Toronto',
        'company_features' => [
            'social' => true,
            'services' => true,
            'products' => true,
            'campaigns' => true,
            'promotions' => true,
        ],
    ], $overrides));
}

function pulseAutopilotTeamMember(
    User $owner,
    array $permissions = [],
    array $userOverrides = [],
    array $membershipOverrides = []
): User {
    $member = User::factory()->create(array_merge([
        'email' => 'pulse-autopilot-member-'.Str::lower(Str::random(10)).'@example.com',
        'company_type' => $owner->company_type,
        'company_features' => $owner->company_features,
        'onboarding_completed_at' => now(),
    ], $userOverrides));

    TeamMember::query()->create(array_merge([
        'account_id' => $owner->id,
        'user_id' => $member->id,
        'role' => 'member',
        'permissions' => $permissions,
        'is_active' => true,
    ], $membershipOverrides));

    return $member;
}

function pulseAutopilotConnection(User $owner, string $platform, array $overrides = []): SocialAccountConnection
{
    return SocialAccountConnection::query()->create(array_merge([
        'user_id' => $owner->id,
        'platform' => $platform,
        'label' => Str::headline($platform).' Pulse account',
        'display_name' => 'Pulse '.Str::headline($platform),
        'external_account_id' => $platform.'-'.Str::lower(Str::random(8)),
        'credentials' => [
            'access_token' => 'token-'.$platform,
        ],
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
        'metadata' => [
            'provider_label' => Str::headline($platform),
            'target_type' => 'page',
        ],
    ], $overrides));
}

function pulseAutopilotTemplate(User $owner, array $overrides = []): SocialPostTemplate
{
    return SocialPostTemplate::query()->create(array_merge([
        'user_id' => $owner->id,
        'name' => 'Weekly spotlight',
        'content_payload' => [
            'text' => 'Spotlight this week',
        ],
        'media_payload' => [
            [
                'type' => 'image',
                'url' => 'https://example.com/assets/pulse-template.jpg',
            ],
        ],
        'link_url' => 'https://example.com/offers/pulse-template',
        'metadata' => [
            'link_cta_label' => 'See more',
        ],
    ], $overrides));
}

/**
 * @param  array<int, SocialAccountConnection>  $connections
 */
function pulseAutopilotDraft(User $owner, User $actor, array $connections, array $overrides = []): SocialPost
{
    $scheduledFor = $overrides['scheduled_for'] ?? null;

    $post = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $actor->id,
        'updated_by_user_id' => $actor->id,
        'source_type' => $overrides['source_type'] ?? null,
        'source_id' => $overrides['source_id'] ?? null,
        'social_automation_rule_id' => $overrides['social_automation_rule_id'] ?? null,
        'content_payload' => [
            'text' => $overrides['text'] ?? 'Pulse autopilot draft',
        ],
        'media_payload' => [
            [
                'type' => 'image',
                'url' => $overrides['image_url'] ?? 'https://example.com/assets/pulse-autopilot.jpg',
            ],
        ],
        'link_url' => $overrides['link_url'] ?? 'https://example.com/offers/pulse-autopilot',
        'status' => $scheduledFor ? SocialPost::STATUS_SCHEDULED : SocialPost::STATUS_DRAFT,
        'scheduled_for' => $scheduledFor,
        'metadata' => array_merge([
            'selected_target_count' => count($connections),
            'draft_saved_from' => 'social_autopilot_test',
            'source' => [
                'type' => $overrides['source_type'] ?? null,
                'id' => $overrides['source_id'] ?? null,
                'label' => $overrides['source_label'] ?? null,
            ],
            'automation' => $overrides['automation_metadata'] ?? null,
        ], $overrides['metadata'] ?? []),
    ]);

    foreach ($connections as $connection) {
        SocialPostTarget::query()->create([
            'social_post_id' => $post->id,
            'social_account_connection_id' => $connection->id,
            'status' => $scheduledFor
                ? SocialPostTarget::STATUS_SCHEDULED
                : SocialPostTarget::STATUS_PENDING,
            'metadata' => [
                'snapshot_label' => $connection->label,
                'provider_label' => data_get($connection->metadata, 'provider_label'),
                'platform' => $connection->platform,
                'display_name' => $connection->display_name,
                'account_handle' => $connection->account_handle,
                'target_type' => data_get($connection->metadata, 'target_type'),
            ],
        ]);
    }

    return $post->fresh(['targets.socialAccountConnection']);
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

it('renders the pulse autopilot and approval queue workspaces', function () {
    $owner = pulseAutopilotOwner();
    $connection = pulseAutopilotConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $publisher = pulseAutopilotTeamMember($owner, ['social.publish']);

    $rule = SocialAutomationRule::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Daily pulse rhythm',
        'is_active' => true,
        'frequency_type' => SocialAutomationRule::FREQUENCY_DAILY,
        'frequency_interval' => 1,
        'scheduled_time' => '09:00',
        'timezone' => 'America/Toronto',
        'approval_mode' => SocialAutomationRule::APPROVAL_REQUIRED,
        'language' => 'fr',
        'content_sources' => [
            ['type' => 'template', 'mode' => 'all'],
        ],
        'target_connection_ids' => [$connection->id],
        'max_posts_per_day' => 2,
        'min_hours_between_similar_posts' => 24,
        'next_generation_at' => now()->addDay(),
        'metadata' => [
            'day_of_week' => 1,
            'day_of_month' => 1,
        ],
    ]);

    $draft = pulseAutopilotDraft($owner, $owner, [$connection], [
        'text' => 'Pending approval item',
    ]);

    $this->actingAs($publisher)
        ->postJson(route('social.posts.submit-approval', $draft))
        ->assertStatus(202);

    $this->actingAs($owner)
        ->get(route('social.automations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Social/Automations')
            ->where('access.can_manage_automations', true)
            ->where('summary.total', 1)
            ->where('generation_tone_options.0.value', 'professional')
            ->where('generation_goal_options.0.value', 'sell')
            ->where('image_mode_options.0.value', 'never')
            ->where('image_format_options.0.value', 'auto')
            ->has('rules', 1)
            ->where('rules.0.id', $rule->id)
        );

    $this->actingAs($owner)
        ->get(route('social.approvals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Social/Approvals')
            ->where('summary.pending', 1)
            ->has('posts', 1)
            ->where('posts.0.status', SocialPost::STATUS_PENDING_APPROVAL)
        );
});

it('lets a publisher manage pulse automation rules while a viewer stays read only', function () {
    $owner = pulseAutopilotOwner();
    $connection = pulseAutopilotConnection($owner, SocialAccountConnection::PLATFORM_LINKEDIN);
    $publisher = pulseAutopilotTeamMember($owner, ['social.publish']);
    $viewer = pulseAutopilotTeamMember($owner, ['social.view']);

    $payload = [
        'name' => 'Template cadence',
        'description' => 'Generate from templates every week.',
        'is_active' => true,
        'frequency_type' => SocialAutomationRule::FREQUENCY_WEEKLY,
        'frequency_interval' => 1,
        'scheduled_time' => '10:00',
        'timezone' => 'America/Toronto',
        'approval_mode' => SocialAutomationRule::APPROVAL_REQUIRED,
        'language' => 'en',
        'target_connection_ids' => [$connection->id],
        'content_sources' => [
            ['type' => 'template', 'mode' => 'all', 'ids' => []],
        ],
        'max_posts_per_day' => 3,
        'min_hours_between_similar_posts' => 12,
        'generation_settings' => [
            'text_ai_enabled' => true,
            'image_ai_enabled' => false,
            'creative_prompt' => 'Keep the copy premium, local, and concise.',
            'image_prompt' => 'Bright realistic service visual with no embedded text.',
            'tone' => 'premium',
            'goal' => 'book',
            'image_mode' => 'if_missing',
            'image_format' => 'portrait',
            'variant_count' => 4,
        ],
    ];

    $store = $this->actingAs($publisher)
        ->postJson(route('social.automations.store'), $payload);

    $store->assertCreated()
        ->assertJsonPath('rule.name', 'Template cadence')
        ->assertJsonPath('rule.approval_mode', SocialAutomationRule::APPROVAL_REQUIRED)
        ->assertJsonPath('rule.generation_settings.text_ai_enabled', true)
        ->assertJsonPath('rule.generation_settings.image_ai_enabled', false)
        ->assertJsonPath('rule.generation_settings.tone', 'premium')
        ->assertJsonPath('rule.generation_settings.goal', 'book')
        ->assertJsonPath('rule.generation_settings.image_format', 'portrait')
        ->assertJsonPath('rule.generation_settings.variant_count', 4);

    $ruleId = (int) $store->json('rule.id');

    $createdRule = SocialAutomationRule::query()->findOrFail($ruleId);
    expect(data_get($createdRule->metadata, 'generation_settings.creative_prompt'))
        ->toBe('Keep the copy premium, local, and concise.')
        ->and(data_get($createdRule->metadata, 'generation_settings.image_prompt'))
        ->toBe('Bright realistic service visual with no embedded text.');

    $updatedPayload = array_merge($payload, [
        'name' => 'Updated template cadence',
        'generation_settings' => array_merge($payload['generation_settings'], [
            'image_ai_enabled' => true,
            'image_mode' => 'always',
            'image_format' => 'square',
            'variant_count' => 2,
        ]),
    ]);

    $this->actingAs($publisher)
        ->putJson(route('social.automations.update', $ruleId), $updatedPayload)
        ->assertOk()
        ->assertJsonPath('rule.name', 'Updated template cadence')
        ->assertJsonPath('rule.generation_settings.image_ai_enabled', true)
        ->assertJsonPath('rule.generation_settings.image_mode', 'always')
        ->assertJsonPath('rule.generation_settings.image_format', 'square')
        ->assertJsonPath('rule.generation_settings.variant_count', 2);

    $this->actingAs($viewer)
        ->postJson(route('social.automations.store'), $payload)
        ->assertForbidden();

    $this->actingAs($publisher)
        ->postJson(route('social.automations.pause', $ruleId))
        ->assertOk()
        ->assertJsonPath('rule.is_active', false);

    $this->actingAs($publisher)
        ->postJson(route('social.automations.resume', $ruleId))
        ->assertOk()
        ->assertJsonPath('rule.is_active', true);

    $this->actingAs($publisher)
        ->deleteJson(route('social.automations.destroy', $ruleId))
        ->assertOk();

    expect(SocialAutomationRule::query()->find($ruleId))->toBeNull();
});

it('supports revision flow and scheduled approval from the pulse approval inbox', function () {
    Queue::fake();

    $owner = pulseAutopilotOwner();
    $publisher = pulseAutopilotTeamMember($owner, ['social.publish']);
    $approver = pulseAutopilotTeamMember($owner, ['social.approve']);
    $connection = pulseAutopilotConnection($owner, SocialAccountConnection::PLATFORM_X);

    $firstDraft = pulseAutopilotDraft($owner, $publisher, [$connection], [
        'text' => 'Needs a quick revision',
    ]);

    $this->actingAs($publisher)
        ->postJson(route('social.posts.submit-approval', $firstDraft))
        ->assertStatus(202);

    $revision = $this->actingAs($approver)
        ->postJson(route('social.posts.prepare-revision', $firstDraft));

    $revision->assertCreated()
        ->assertJsonPath('draft.status', SocialPost::STATUS_DRAFT);

    $rejectedOriginal = SocialPost::query()->with('latestApprovalRequest')->findOrFail($firstDraft->id);
    expect((string) $rejectedOriginal->latestApprovalRequest?->status)->toBe(SocialApprovalRequest::STATUS_REJECTED);

    $secondDraft = pulseAutopilotDraft($owner, $publisher, [$connection], [
        'text' => 'Ready to schedule after approval',
    ]);

    $this->actingAs($publisher)
        ->postJson(route('social.posts.submit-approval', $secondDraft))
        ->assertStatus(202);

    $scheduledFor = Carbon::now()->addDay()->setTime(11, 30, 0);

    $this->actingAs($approver)
        ->postJson(route('social.posts.approve', $secondDraft), [
            'mode' => 'scheduled',
            'scheduled_for' => $scheduledFor->toIso8601String(),
        ])
        ->assertStatus(202)
        ->assertJsonPath('draft.status', SocialPost::STATUS_SCHEDULED);

    Queue::assertPushed(PublishSocialPostTargetJob::class, 1);

    $scheduledPost = SocialPost::query()->with('latestApprovalRequest')->findOrFail($secondDraft->id);
    expect($scheduledPost->status)->toBe(SocialPost::STATUS_SCHEDULED)
        ->and($scheduledPost->scheduled_for?->equalTo($scheduledFor))->toBeTrue()
        ->and((string) $scheduledPost->latestApprovalRequest?->status)->toBe(SocialApprovalRequest::STATUS_APPROVED);
});

it('regenerates an automated pending pulse post from autopilot', function () {
    $owner = pulseAutopilotOwner();
    $connection = pulseAutopilotConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $template = pulseAutopilotTemplate($owner, [
        'name' => 'Autopilot showcase',
        'content_payload' => [
            'text' => 'Autopilot generated template',
        ],
    ]);

    $rule = SocialAutomationRule::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Template autopilot',
        'is_active' => true,
        'frequency_type' => SocialAutomationRule::FREQUENCY_DAILY,
        'frequency_interval' => 1,
        'scheduled_time' => '08:30',
        'timezone' => 'America/Toronto',
        'approval_mode' => SocialAutomationRule::APPROVAL_REQUIRED,
        'language' => 'en',
        'content_sources' => [
            ['type' => 'template', 'mode' => 'selected_ids', 'ids' => [$template->id]],
        ],
        'target_connection_ids' => [$connection->id],
        'max_posts_per_day' => 1,
        'min_hours_between_similar_posts' => 24,
        'next_generation_at' => now()->addDay(),
        'metadata' => [
            'day_of_week' => 2,
            'day_of_month' => 2,
        ],
    ]);

    $post = pulseAutopilotDraft($owner, $owner, [$connection], [
        'text' => 'Autopilot generated template',
        'source_type' => 'template',
        'source_id' => $template->id,
        'source_label' => $template->name,
        'social_automation_rule_id' => $rule->id,
        'automation_metadata' => [
            'rule_id' => $rule->id,
            'rule_name_snapshot' => $rule->name,
            'generated_at' => now()->toIso8601String(),
            'generation_mode' => 'scheduled_rule',
            'approval_mode' => SocialAutomationRule::APPROVAL_REQUIRED,
            'selected_source_type' => 'template',
            'selected_source_id' => $template->id,
            'selected_source_label' => $template->name,
            'generation_attempt' => 1,
        ],
    ]);

    SocialApprovalRequest::query()->create([
        'social_post_id' => $post->id,
        'requested_by_user_id' => $owner->id,
        'status' => SocialApprovalRequest::STATUS_PENDING,
        'requested_at' => now(),
        'metadata' => [
            'requested_mode' => 'immediate',
        ],
    ]);

    $post->forceFill([
        'status' => SocialPost::STATUS_PENDING_APPROVAL,
    ])->save();

    $response = $this->actingAs($owner)
        ->postJson(route('social.posts.regenerate', $post));

    $response->assertCreated()
        ->assertJsonPath('draft.status', SocialPost::STATUS_PENDING_APPROVAL)
        ->assertJsonPath('draft.social_automation_rule_id', $rule->id);

    $original = SocialPost::query()->with('latestApprovalRequest')->findOrFail($post->id);
    expect((string) $original->latestApprovalRequest?->status)->toBe(SocialApprovalRequest::STATUS_REJECTED);

    $newPostId = (int) $response->json('draft.id');
    $newPost = SocialPost::query()->with('latestApprovalRequest')->findOrFail($newPostId);

    expect((int) $newPost->social_automation_rule_id)->toBe((int) $rule->id)
        ->and((string) $newPost->status)->toBe(SocialPost::STATUS_PENDING_APPROVAL)
        ->and((string) $newPost->latestApprovalRequest?->status)->toBe(SocialApprovalRequest::STATUS_PENDING);
});

it('surfaces pulse autopilot health and approval filters in the workspace payloads', function () {
    $owner = pulseAutopilotOwner();
    $connection = pulseAutopilotConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK, [
        'status' => SocialAccountConnection::STATUS_DISCONNECTED,
        'is_active' => false,
        'connected_at' => null,
    ]);
    $publisher = pulseAutopilotTeamMember($owner, ['social.publish']);

    $rule = SocialAutomationRule::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Attention rule',
        'is_active' => false,
        'frequency_type' => SocialAutomationRule::FREQUENCY_DAILY,
        'frequency_interval' => 1,
        'scheduled_time' => '09:00',
        'timezone' => 'America/Toronto',
        'approval_mode' => SocialAutomationRule::APPROVAL_REQUIRED,
        'language' => 'fr',
        'content_sources' => [
            ['type' => 'template', 'mode' => 'all'],
        ],
        'target_connection_ids' => [$connection->id],
        'max_posts_per_day' => 1,
        'min_hours_between_similar_posts' => 24,
        'next_generation_at' => now()->subHours(7),
        'last_error' => 'Disconnected account',
        'metadata' => [
            'health' => [
                'auto_paused' => true,
                'auto_paused_at' => now()->subHour()->toIso8601String(),
                'auto_pause_reason' => 'Disconnected account',
                'consecutive_failures' => 3,
            ],
        ],
    ]);

    SocialAutomationRun::query()->create([
        'user_id' => $owner->id,
        'social_automation_rule_id' => $rule->id,
        'status' => SocialAutomationRun::STATUS_SKIPPED,
        'outcome_code' => 'auto_paused',
        'message' => 'Pulse Autopilot paused this rule.',
        'started_at' => now()->subHour(),
        'completed_at' => now()->subHour(),
    ]);

    $automatedDraft = pulseAutopilotDraft($owner, $publisher, [$connection], [
        'text' => 'Automated approval candidate',
        'source_type' => 'template',
        'source_id' => 12,
        'social_automation_rule_id' => $rule->id,
    ]);

    $manualDraft = pulseAutopilotDraft($owner, $publisher, [$connection], [
        'text' => 'Manual approval candidate',
        'source_type' => 'product',
        'source_id' => 44,
    ]);

    $this->actingAs($publisher)->postJson(route('social.posts.submit-approval', $automatedDraft))->assertStatus(202);
    $this->actingAs($publisher)->postJson(route('social.posts.submit-approval', $manualDraft))->assertStatus(202);

    $this->actingAs($owner)
        ->get(route('social.automations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Social/Automations')
            ->where('summary.auto_paused', 1)
            ->where('summary.attention', 1)
            ->has('recent_runs', 1)
            ->where('rules.0.health.auto_paused', true)
        );

    $this->actingAs($owner)
        ->get(route('social.approvals.index', [
            'origin' => 'automated',
            'source_type' => 'template',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Social/Approvals')
            ->where('filters.origin', 'automated')
            ->where('filters.source_type', 'template')
            ->where('summary.automated', 1)
            ->has('source_filters')
            ->has('posts', 1)
            ->where('posts.0.social_automation_rule_id', $rule->id)
        );
});
