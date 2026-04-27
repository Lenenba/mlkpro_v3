<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Social\SocialBrandVoiceService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function pulsePhaseThreeRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function pulsePhaseThreeOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => pulsePhaseThreeRoleId('owner'),
        'email' => 'pulse-phase-three-owner-'.Str::lower(Str::random(10)).'@example.com',
        'company_type' => 'services',
        'company_timezone' => 'America/Toronto',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'social' => true,
            'campaigns' => true,
        ],
    ], $overrides));
}

function pulsePhaseThreeTeamMember(User $owner, array $permissions = []): User
{
    $member = User::factory()->create([
        'email' => 'pulse-phase-three-member-'.Str::lower(Str::random(10)).'@example.com',
        'company_features' => $owner->company_features,
        'onboarding_completed_at' => now(),
    ]);

    TeamMember::query()->create([
        'account_id' => $owner->id,
        'user_id' => $member->id,
        'role' => 'member',
        'permissions' => $permissions,
        'is_active' => true,
    ]);

    return $member;
}

function pulsePhaseThreeConnection(User $owner, string $platform = SocialAccountConnection::PLATFORM_FACEBOOK): SocialAccountConnection
{
    return SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => $platform,
        'label' => Str::headline($platform).' page',
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
    ]);
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

it('renders campaign mode and generates planned editable pulse drafts', function () {
    $owner = pulsePhaseThreeOwner();
    $connection = pulsePhaseThreeConnection($owner, SocialAccountConnection::PLATFORM_LINKEDIN);

    $this->actingAs($owner)
        ->get(route('social.campaigns.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Social/Campaigns')
            ->has('connected_accounts', 1)
            ->has('intention_options')
            ->where('access.can_manage_posts', true)
        );

    $response = $this->actingAs($owner)
        ->postJson(route('social.campaigns.store'), [
            'name' => 'Spring services push',
            'intention_type' => 'service_push',
            'brief' => 'Promote the new booking flow for local clients.',
            'start_date' => now()->addDay()->toDateString(),
            'post_count' => 3,
            'duration_days' => 6,
            'link_url' => 'https://example.com/book',
            'target_connection_ids' => [$connection->id],
        ]);

    $response->assertCreated()
        ->assertJsonCount(3, 'posts')
        ->assertJsonPath('batch.name', 'Spring services push')
        ->assertJsonPath('posts.0.status', SocialPost::STATUS_SCHEDULED)
        ->assertJsonPath('posts.0.ai_trace.has_trace', true)
        ->assertJsonPath('posts.0.quality_review.status', 'good')
        ->assertJsonPath('summary.scheduled', 3);

    $posts = SocialPost::query()
        ->with('targets')
        ->where('user_id', $owner->id)
        ->orderBy('scheduled_for')
        ->get();

    expect($posts)->toHaveCount(3)
        ->and($posts->pluck('status')->unique()->values()->all())->toBe([SocialPost::STATUS_SCHEDULED])
        ->and(data_get($posts->first()?->metadata, 'campaign_batch.id'))->toBe($response->json('batch.id'))
        ->and(data_get($posts->first()?->metadata, 'draft_saved_from'))->toBe('social_campaign_mode')
        ->and($posts->first()?->targets)->toHaveCount(1);
});

it('keeps campaign generation restricted to pulse editors', function () {
    $owner = pulsePhaseThreeOwner();
    $viewer = pulsePhaseThreeTeamMember($owner, ['social.view']);
    $connection = pulsePhaseThreeConnection($owner);

    $this->actingAs($viewer)
        ->get(route('social.campaigns.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Social/Campaigns')
            ->where('access.can_manage_posts', false)
        );

    $this->actingAs($viewer)
        ->postJson(route('social.campaigns.store'), [
            'intention_type' => 'promotion',
            'brief' => 'Viewer should not generate.',
            'start_date' => now()->addDay()->toDateString(),
            'post_count' => 2,
            'duration_days' => 2,
            'target_connection_ids' => [$connection->id],
        ])
        ->assertForbidden();
});

it('exposes readable ai trace and advanced quality issues in pulse history', function () {
    $owner = pulsePhaseThreeOwner();
    $connection = pulsePhaseThreeConnection($owner, SocialAccountConnection::PLATFORM_INSTAGRAM);

    app(SocialBrandVoiceService::class)->update($owner, [
        'tone' => 'premium',
        'language' => 'fr',
        'words_to_avoid' => ['cheap'],
    ]);

    $post = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'content_payload' => [
            'text' => 'Cheap service update with a visual to inspect.',
        ],
        'status' => SocialPost::STATUS_DRAFT,
        'metadata' => [
            'source' => [
                'type' => 'campaign',
                'id' => 10,
                'label' => 'Spring push',
            ],
            'ai_generation' => [
                'generation_mode' => 'ai_creative',
                'text_model' => 'test-social-model',
                'image_model' => 'test-image-model',
                'selected_score' => 88,
                'selected_score_reason' => 'Strong fit for the requested campaign.',
                'fallback_used' => false,
                'generated_at' => now()->toIso8601String(),
            ],
            'automation' => [
                'rule_name_snapshot' => 'Daily campaign rule',
                'selected_source_label' => 'Spring push',
            ],
        ],
    ]);

    SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'social_account_connection_id' => $connection->id,
        'status' => 'pending',
        'metadata' => [
            'platform' => SocialAccountConnection::PLATFORM_INSTAGRAM,
        ],
    ]);

    $this->actingAs($owner)
        ->getJson(route('social.history'))
        ->assertOk()
        ->assertJsonPath('posts.0.ai_trace.has_trace', true)
        ->assertJsonPath('posts.0.ai_trace.score', 88)
        ->assertJsonPath('posts.0.ai_trace.items.0.key', 'source')
        ->assertJsonPath('posts.0.quality_review.issues.0.key', 'missing_image')
        ->assertJsonFragment(['key' => 'brand_voice_word']);
});

it('blocks phase three pulse routes when the social module is unavailable', function () {
    $owner = pulsePhaseThreeOwner([
        'company_features' => [
            'social' => false,
        ],
    ]);

    $this->actingAs($owner)
        ->getJson(route('social.campaigns.index'))
        ->assertForbidden();

    $this->actingAs($owner)
        ->postJson(route('social.campaigns.store'), [
            'intention_type' => 'event',
            'brief' => 'Blocked campaign.',
            'start_date' => now()->addDay()->toDateString(),
            'post_count' => 2,
            'duration_days' => 2,
            'target_connection_ids' => [1],
        ])
        ->assertForbidden();
});
