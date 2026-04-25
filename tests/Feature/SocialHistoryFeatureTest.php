<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function pulseHistoryRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function pulseHistoryOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => pulseHistoryRoleId('owner'),
        'email' => 'pulse-history-owner-'.Str::lower(Str::random(10)).'@example.com',
        'company_type' => 'services',
        'company_sector' => 'service_general',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'social' => true,
        ],
    ], $overrides));
}

function pulseHistoryTeamMember(
    User $owner,
    array $permissions = [],
    array $userOverrides = [],
    array $membershipOverrides = []
): User {
    $member = User::factory()->create(array_merge([
        'email' => 'pulse-history-member-'.Str::lower(Str::random(10)).'@example.com',
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

function pulseHistoryConnection(User $owner, string $platform, array $overrides = []): SocialAccountConnection
{
    return SocialAccountConnection::query()->create(array_merge([
        'user_id' => $owner->id,
        'platform' => $platform,
        'label' => Str::headline($platform).' channel',
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

/**
 * @param  array<int, array{connection: SocialAccountConnection, status?: string, published_at?: Carbon|null, failed_at?: Carbon|null, failure_reason?: string|null}>  $targets
 */
function pulseHistoryPost(
    User $owner,
    User $actor,
    string $status,
    array $targets,
    array $overrides = []
): SocialPost {
    $post = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $actor->id,
        'updated_by_user_id' => $actor->id,
        'content_payload' => [
            'text' => $overrides['text'] ?? 'Pulse history content',
        ],
        'media_payload' => [
            [
                'type' => 'image',
                'url' => $overrides['image_url'] ?? 'https://example.com/assets/history-default.jpg',
            ],
        ],
        'link_url' => $overrides['link_url'] ?? 'https://example.com/offers/history-default',
        'status' => $status,
        'scheduled_for' => $overrides['scheduled_for'] ?? null,
        'published_at' => $overrides['published_at'] ?? null,
        'failed_at' => $overrides['failed_at'] ?? null,
        'failure_reason' => $overrides['failure_reason'] ?? null,
        'metadata' => $overrides['metadata'] ?? [
            'selected_target_count' => count($targets),
            'draft_saved_from' => 'social_history_seed',
        ],
    ]);

    foreach ($targets as $targetConfig) {
        $connection = $targetConfig['connection'];

        SocialPostTarget::query()->create([
            'social_post_id' => $post->id,
            'social_account_connection_id' => $connection->id,
            'status' => $targetConfig['status'] ?? SocialPostTarget::STATUS_PENDING,
            'published_at' => $targetConfig['published_at'] ?? null,
            'failed_at' => $targetConfig['failed_at'] ?? null,
            'failure_reason' => $targetConfig['failure_reason'] ?? null,
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

it('renders the pulse history page and filters posts by status platform and search', function () {
    $owner = pulseHistoryOwner();
    $facebook = pulseHistoryConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $linkedin = pulseHistoryConnection($owner, SocialAccountConnection::PLATFORM_LINKEDIN);

    pulseHistoryPost($owner, $owner, SocialPost::STATUS_PUBLISHED, [[
        'connection' => $facebook,
        'status' => SocialPostTarget::STATUS_PUBLISHED,
        'published_at' => Carbon::parse('2026-04-22 10:00:00'),
    ]], [
        'text' => 'Spring launch published',
        'published_at' => Carbon::parse('2026-04-22 10:00:00'),
    ]);

    pulseHistoryPost($owner, $owner, SocialPost::STATUS_FAILED, [[
        'connection' => $linkedin,
        'status' => SocialPostTarget::STATUS_FAILED,
        'failed_at' => Carbon::parse('2026-04-22 11:00:00'),
        'failure_reason' => 'LinkedIn API timeout',
    ]], [
        'text' => 'LinkedIn retry needed',
        'failed_at' => Carbon::parse('2026-04-22 11:00:00'),
        'failure_reason' => 'LinkedIn API timeout',
    ]);

    pulseHistoryPost($owner, $owner, SocialPost::STATUS_DRAFT, [[
        'connection' => $facebook,
        'status' => SocialPostTarget::STATUS_PENDING,
    ]], [
        'text' => 'Draft reuse candidate',
    ]);

    $this->actingAs($owner)
        ->get(route('social.history'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Social/History')
            ->where('access.can_view', true)
            ->where('access.can_manage_posts', true)
            ->has('posts', 3)
            ->has('platform_filters', 4)
            ->has('status_filters', 7)
        );

    $this->actingAs($owner)
        ->getJson(route('social.history', [
            'status' => SocialPost::STATUS_PUBLISHED,
            'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
            'search' => 'Spring',
        ]))
        ->assertOk()
        ->assertJsonPath('filters.status', SocialPost::STATUS_PUBLISHED)
        ->assertJsonPath('filters.platform', SocialAccountConnection::PLATFORM_FACEBOOK)
        ->assertJsonPath('filters.search', 'Spring')
        ->assertJsonCount(1, 'posts')
        ->assertJsonPath('posts.0.status', SocialPost::STATUS_PUBLISHED)
        ->assertJsonPath('posts.0.text', 'Spring launch published');
});

it('duplicates pulse draft published and failed posts into editable drafts', function () {
    $owner = pulseHistoryOwner();
    $facebook = pulseHistoryConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);

    $draft = pulseHistoryPost($owner, $owner, SocialPost::STATUS_DRAFT, [[
        'connection' => $facebook,
        'status' => SocialPostTarget::STATUS_PENDING,
    ]], [
        'text' => 'Draft content to duplicate',
        'scheduled_for' => Carbon::parse('2026-04-25 09:00:00'),
    ]);

    $published = pulseHistoryPost($owner, $owner, SocialPost::STATUS_PUBLISHED, [[
        'connection' => $facebook,
        'status' => SocialPostTarget::STATUS_PUBLISHED,
        'published_at' => Carbon::parse('2026-04-22 10:00:00'),
    ]], [
        'text' => 'Published content to duplicate',
        'published_at' => Carbon::parse('2026-04-22 10:00:00'),
        'metadata' => [
            'publish_requested_at' => Carbon::parse('2026-04-22 09:59:00')->toIso8601String(),
            'link_cta_label' => 'Voir la publication',
        ],
    ]);

    $failed = pulseHistoryPost($owner, $owner, SocialPost::STATUS_FAILED, [[
        'connection' => $facebook,
        'status' => SocialPostTarget::STATUS_FAILED,
        'failed_at' => Carbon::parse('2026-04-22 12:00:00'),
        'failure_reason' => 'Provider rejected the post',
    ]], [
        'text' => 'Failed content to duplicate',
        'failed_at' => Carbon::parse('2026-04-22 12:00:00'),
        'failure_reason' => 'Provider rejected the post',
    ]);

    foreach ([$draft, $published, $failed] as $sourcePost) {
        $response = $this->actingAs($owner)
            ->postJson(route('social.posts.duplicate', $sourcePost));

        $response->assertCreated()
            ->assertJsonPath('draft.status', SocialPost::STATUS_DRAFT)
            ->assertJsonPath(
                'draft.link_cta_label',
                $sourcePost->id === $published->id ? 'Voir la publication' : null
            )
            ->assertJsonPath('draft.metadata.copy_mode', 'duplicate')
            ->assertJsonPath('draft.metadata.copied_from_post_id', $sourcePost->id)
            ->assertJsonPath('draft.metadata.copied_from_status', $sourcePost->status)
            ->assertJsonPath('draft.selected_accounts_count', 1);

        $copyId = (int) $response->json('draft.id');
        $copy = SocialPost::query()->with('targets')->findOrFail($copyId);

        expect($copy->status)->toBe(SocialPost::STATUS_DRAFT)
            ->and($copy->scheduled_for)->toBeNull()
            ->and($copy->published_at)->toBeNull()
            ->and($copy->failed_at)->toBeNull()
            ->and($copy->failure_reason)->toBeNull()
            ->and($copy->targets)->toHaveCount(1);
    }
});

it('creates an editable repost draft only from a published pulse post', function () {
    $owner = pulseHistoryOwner();
    $linkedin = pulseHistoryConnection($owner, SocialAccountConnection::PLATFORM_LINKEDIN);

    $published = pulseHistoryPost($owner, $owner, SocialPost::STATUS_PUBLISHED, [[
        'connection' => $linkedin,
        'status' => SocialPostTarget::STATUS_PUBLISHED,
        'published_at' => Carbon::parse('2026-04-22 14:00:00'),
    ]], [
        'text' => 'Published content for repost',
        'published_at' => Carbon::parse('2026-04-22 14:00:00'),
        'metadata' => [
            'publish_requested_at' => Carbon::parse('2026-04-22 13:55:00')->toIso8601String(),
            'link_cta_label' => 'Redecouvrir le contenu',
        ],
    ]);

    $repost = $this->actingAs($owner)
        ->postJson(route('social.posts.repost', $published));

    $repost->assertCreated()
        ->assertJsonPath('draft.status', SocialPost::STATUS_DRAFT)
        ->assertJsonPath('draft.link_cta_label', 'Redecouvrir le contenu')
        ->assertJsonPath('draft.metadata.copy_mode', 'repost')
        ->assertJsonPath('draft.metadata.repost_of_post_id', $published->id)
        ->assertJsonPath('draft.selected_accounts_count', 1);

    $failed = pulseHistoryPost($owner, $owner, SocialPost::STATUS_FAILED, [[
        'connection' => $linkedin,
        'status' => SocialPostTarget::STATUS_FAILED,
        'failed_at' => Carbon::parse('2026-04-22 15:00:00'),
        'failure_reason' => 'Target rejected content',
    ]], [
        'text' => 'Failed content not eligible for repost',
        'failed_at' => Carbon::parse('2026-04-22 15:00:00'),
        'failure_reason' => 'Target rejected content',
    ]);

    $this->actingAs($owner)
        ->postJson(route('social.posts.repost', $failed))
        ->assertStatus(422);
});

it('keeps pulse history read only for viewers while social manage can duplicate posts', function () {
    $owner = pulseHistoryOwner();
    $viewer = pulseHistoryTeamMember($owner, ['social.view']);
    $manager = pulseHistoryTeamMember($owner, ['social.manage']);
    $facebook = pulseHistoryConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);

    $published = pulseHistoryPost($owner, $owner, SocialPost::STATUS_PUBLISHED, [[
        'connection' => $facebook,
        'status' => SocialPostTarget::STATUS_PUBLISHED,
        'published_at' => Carbon::parse('2026-04-22 16:00:00'),
    ]], [
        'text' => 'Viewer can inspect this history item',
        'published_at' => Carbon::parse('2026-04-22 16:00:00'),
    ]);

    $this->actingAs($viewer)
        ->get(route('social.history'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Social/History')
            ->where('access.can_view', true)
            ->where('access.can_manage_posts', false)
        );

    $this->actingAs($viewer)
        ->postJson(route('social.posts.duplicate', $published))
        ->assertForbidden();

    $this->actingAs($viewer)
        ->postJson(route('social.posts.repost', $published))
        ->assertForbidden();

    $this->actingAs($manager)
        ->postJson(route('social.posts.duplicate', $published))
        ->assertCreated()
        ->assertJsonPath('draft.status', SocialPost::STATUS_DRAFT);
});

it('blocks pulse history duplication and repost routes when the social module is disabled', function () {
    $owner = pulseHistoryOwner([
        'company_features' => [
            'social' => false,
        ],
    ]);
    $facebook = pulseHistoryConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $published = pulseHistoryPost($owner, $owner, SocialPost::STATUS_PUBLISHED, [[
        'connection' => $facebook,
        'status' => SocialPostTarget::STATUS_PUBLISHED,
        'published_at' => Carbon::parse('2026-04-22 18:00:00'),
    ]], [
        'text' => 'Blocked social history item',
        'published_at' => Carbon::parse('2026-04-22 18:00:00'),
    ]);

    $this->actingAs($owner)
        ->getJson(route('social.history'))
        ->assertForbidden();

    $this->actingAs($owner)
        ->postJson(route('social.posts.duplicate', $published))
        ->assertForbidden();

    $this->actingAs($owner)
        ->postJson(route('social.posts.repost', $published))
        ->assertForbidden();
});
