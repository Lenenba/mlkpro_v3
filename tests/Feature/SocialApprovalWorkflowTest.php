<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Jobs\PublishSocialPostTargetJob;
use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\SocialApprovalRequest;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function pulseApprovalRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function pulseApprovalOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => pulseApprovalRoleId('owner'),
        'email' => 'pulse-approval-owner-'.Str::lower(Str::random(10)).'@example.com',
        'company_type' => 'services',
        'company_sector' => 'service_general',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'social' => true,
        ],
    ], $overrides));
}

function pulseApprovalTeamMember(
    User $owner,
    array $permissions = [],
    array $userOverrides = [],
    array $membershipOverrides = []
): User {
    $member = User::factory()->create(array_merge([
        'email' => 'pulse-approval-member-'.Str::lower(Str::random(10)).'@example.com',
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

function pulseApprovalConnection(User $owner, string $platform, array $overrides = []): SocialAccountConnection
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

/**
 * @param  array<int, SocialAccountConnection>  $connections
 */
function pulseApprovalDraft(
    User $owner,
    User $actor,
    array $connections,
    array $overrides = []
): SocialPost {
    $scheduledFor = $overrides['scheduled_for'] ?? null;

    $post = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $actor->id,
        'updated_by_user_id' => $actor->id,
        'content_payload' => [
            'text' => $overrides['text'] ?? 'Pulse approval draft',
        ],
        'media_payload' => [
            [
                'type' => 'image',
                'url' => $overrides['image_url'] ?? 'https://example.com/assets/pulse-approval.jpg',
            ],
        ],
        'link_url' => $overrides['link_url'] ?? 'https://example.com/offers/pulse-approval',
        'status' => $scheduledFor ? SocialPost::STATUS_SCHEDULED : SocialPost::STATUS_DRAFT,
        'scheduled_for' => $scheduledFor,
        'metadata' => [
            'selected_target_count' => count($connections),
            'draft_saved_from' => 'social_composer',
        ],
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

it('lets a publisher submit a pulse post for approval while direct publication stays blocked', function () {
    $owner = pulseApprovalOwner();
    $publisher = pulseApprovalTeamMember($owner, ['social.publish']);
    $connection = pulseApprovalConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $draft = pulseApprovalDraft($owner, $publisher, [$connection]);

    $this->actingAs($publisher)
        ->get(route('social.composer'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('access.can_manage_posts', true)
            ->where('access.can_publish', false)
            ->where('access.can_submit_for_approval', true)
            ->where('access.can_approve', false)
        );

    $this->actingAs($publisher)
        ->postJson(route('social.posts.publish', $draft))
        ->assertForbidden();

    $this->actingAs($publisher)
        ->postJson(route('social.posts.submit-approval', $draft))
        ->assertStatus(202)
        ->assertJsonPath('draft.status', SocialPost::STATUS_PENDING_APPROVAL)
        ->assertJsonPath('draft.approval_request.status', SocialApprovalRequest::STATUS_PENDING);

    $pendingRequest = SocialApprovalRequest::query()->where('social_post_id', $draft->id)->first();

    expect($pendingRequest)->not->toBeNull()
        ->and((string) $pendingRequest?->status)->toBe(SocialApprovalRequest::STATUS_PENDING)
        ->and((int) $pendingRequest?->requested_by_user_id)->toBe((int) $publisher->id);

    $this->actingAs($publisher)
        ->putJson(route('social.posts.update', $draft), [
            'text' => 'Trying to edit after approval submission',
            'target_connection_ids' => [$connection->id],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('post');
});

it('lets an approver approve a pending pulse request and queue publication', function () {
    Queue::fake();

    $owner = pulseApprovalOwner();
    $publisher = pulseApprovalTeamMember($owner, ['social.publish']);
    $approver = pulseApprovalTeamMember($owner, ['social.approve']);
    $connection = pulseApprovalConnection($owner, SocialAccountConnection::PLATFORM_LINKEDIN);
    $draft = pulseApprovalDraft($owner, $publisher, [$connection]);

    $this->actingAs($publisher)
        ->postJson(route('social.posts.submit-approval', $draft))
        ->assertStatus(202);

    $this->actingAs($approver)
        ->postJson(route('social.posts.approve', $draft))
        ->assertStatus(202)
        ->assertJsonPath('draft.status', SocialPost::STATUS_PUBLISHING)
        ->assertJsonPath('draft.approval_request.status', SocialApprovalRequest::STATUS_APPROVED)
        ->assertJsonPath('summary.publishing', 1);

    Queue::assertPushed(PublishSocialPostTargetJob::class, 1);

    $freshPost = SocialPost::query()->with('latestApprovalRequest')->findOrFail($draft->id);

    expect($freshPost->status)->toBe(SocialPost::STATUS_PUBLISHING)
        ->and((string) $freshPost->latestApprovalRequest?->status)->toBe(SocialApprovalRequest::STATUS_APPROVED)
        ->and((int) $freshPost->latestApprovalRequest?->resolved_by_user_id)->toBe((int) $approver->id);
});

it('lets an approver reject a pending scheduled pulse request and restore the scheduled draft', function () {
    Queue::fake();

    $owner = pulseApprovalOwner();
    $publisher = pulseApprovalTeamMember($owner, ['social.publish']);
    $approver = pulseApprovalTeamMember($owner, ['social.approve']);
    $connection = pulseApprovalConnection($owner, SocialAccountConnection::PLATFORM_X);
    $scheduledFor = Carbon::parse('2026-04-25 11:15:00');
    $draft = pulseApprovalDraft($owner, $publisher, [$connection], [
        'scheduled_for' => $scheduledFor,
    ]);

    $this->actingAs($publisher)
        ->postJson(route('social.posts.submit-approval', $draft))
        ->assertStatus(202)
        ->assertJsonPath('draft.status', SocialPost::STATUS_PENDING_APPROVAL);

    $this->actingAs($approver)
        ->postJson(route('social.posts.reject', $draft), [
            'note' => 'Please shorten the copy before approval.',
        ])
        ->assertOk()
        ->assertJsonPath('draft.status', SocialPost::STATUS_SCHEDULED)
        ->assertJsonPath('draft.approval_request.status', SocialApprovalRequest::STATUS_REJECTED)
        ->assertJsonPath('draft.approval_request.note', 'Please shorten the copy before approval.');

    Queue::assertNothingPushed();

    $freshPost = SocialPost::query()->with('latestApprovalRequest')->findOrFail($draft->id);

    expect($freshPost->status)->toBe(SocialPost::STATUS_SCHEDULED)
        ->and($freshPost->scheduled_for?->equalTo($scheduledFor))->toBeTrue()
        ->and((string) $freshPost->latestApprovalRequest?->status)->toBe(SocialApprovalRequest::STATUS_REJECTED)
        ->and((string) $freshPost->latestApprovalRequest?->note)->toBe('Please shorten the copy before approval.');
});
