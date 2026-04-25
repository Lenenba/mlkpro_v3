<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\SocialPost;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function pulseComposerRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function pulseComposerOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => pulseComposerRoleId('owner'),
        'email' => 'pulse-composer-owner-'.Str::lower(Str::random(10)).'@example.com',
        'company_type' => 'services',
        'company_sector' => 'service_general',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'social' => true,
        ],
    ], $overrides));
}

function pulseComposerTeamMember(
    User $owner,
    array $permissions = [],
    array $userOverrides = [],
    array $membershipOverrides = []
): User {
    $member = User::factory()->create(array_merge([
        'email' => 'pulse-composer-member-'.Str::lower(Str::random(10)).'@example.com',
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

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

it('renders the pulse workspace overview and composer for owners', function () {
    $owner = pulseComposerOwner();

    SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Main page',
        'external_account_id' => 'fb-main',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
    ]);

    SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'content_payload' => [
            'text' => 'Seasonal update',
        ],
        'status' => SocialPost::STATUS_DRAFT,
    ]);

    $this->actingAs($owner)
        ->get(route('social.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Social/Index')
            ->where('workspace_stats.connected_accounts', 1)
            ->where('workspace_stats.draft_posts', 1)
            ->where('access.can_manage_posts', true)
            ->has('recent_drafts', 1)
        );

    $this->actingAs($owner)
        ->get(route('social.composer'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Social/Composer')
            ->where('workspace_stats.connected_accounts', 1)
            ->where('summary.drafts', 1)
            ->where('access.can_manage_posts', true)
            ->has('connected_accounts', 1)
            ->has('drafts', 1)
        );
});

it('lets owners create and update pulse drafts with multi-account selection and scheduling', function () {
    $owner = pulseComposerOwner();

    $facebook = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'North page',
        'external_account_id' => 'fb-001',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
    ]);

    $linkedin = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_LINKEDIN,
        'label' => 'Corporate page',
        'external_account_id' => 'li-001',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
    ]);

    $create = $this->actingAs($owner)
        ->postJson(route('social.posts.store'), [
            'text' => 'Spring launch is ready.',
            'image_url' => 'https://example.com/assets/pulse-spring.jpg',
            'link_url' => 'https://example.com/offers/spring',
            'link_cta_label' => 'Voir la collection',
            'target_connection_ids' => [$facebook->id, $linkedin->id],
        ]);

    $create->assertCreated()
        ->assertJsonPath('draft.status', SocialPost::STATUS_DRAFT)
        ->assertJsonPath('draft.link_cta_label', 'Voir la collection')
        ->assertJsonPath('draft.selected_accounts_count', 2)
        ->assertJsonPath('summary.drafts', 1)
        ->assertJsonCount(1, 'drafts');

    $draftId = (int) $create->json('draft.id');

    $this->actingAs($owner)
        ->putJson(route('social.posts.update', $draftId), [
            'text' => 'Spring launch is scheduled.',
            'image_url' => 'https://example.com/assets/pulse-spring-updated.jpg',
            'link_url' => 'https://example.com/offers/spring-v2',
            'link_cta_label' => 'Magasiner maintenant',
            'scheduled_for' => '2026-04-24T10:30',
            'target_connection_ids' => [$linkedin->id],
        ])
        ->assertOk()
        ->assertJsonPath('draft.status', SocialPost::STATUS_SCHEDULED)
        ->assertJsonPath('draft.link_cta_label', 'Magasiner maintenant')
        ->assertJsonPath('draft.selected_accounts_count', 1)
        ->assertJsonPath('draft.selected_target_connection_ids.0', $linkedin->id)
        ->assertJsonPath('summary.scheduled', 1);

    $draft = SocialPost::query()->with('targets')->findOrFail($draftId);

    expect($draft->status)->toBe(SocialPost::STATUS_SCHEDULED)
        ->and((string) data_get($draft->content_payload, 'text'))->toBe('Spring launch is scheduled.')
        ->and((string) $draft->link_url)->toBe('https://example.com/offers/spring-v2')
        ->and((string) data_get($draft->metadata, 'link_cta_label'))->toBe('Magasiner maintenant')
        ->and($draft->scheduled_for)->not->toBeNull()
        ->and($draft->targets)->toHaveCount(1)
        ->and((int) $draft->targets->first()->social_account_connection_id)->toBe((int) $linkedin->id);
});

it('lets owners upload local images for pulse drafts', function () {
    Storage::fake('public');

    $owner = pulseComposerOwner();

    $facebook = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'North page',
        'external_account_id' => 'fb-upload-001',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
    ]);

    $create = $this->actingAs($owner)
        ->post(route('social.posts.store'), [
            'text' => 'Local image draft',
            'image_file' => UploadedFile::fake()->image('pulse-local.png', 1200, 800),
            'target_connection_ids' => [$facebook->id],
        ]);

    $create->assertCreated()
        ->assertJsonPath('draft.status', SocialPost::STATUS_DRAFT)
        ->assertJsonPath('draft.selected_accounts_count', 1);

    $draftId = (int) $create->json('draft.id');
    $draft = SocialPost::query()->findOrFail($draftId);
    $storedPath = data_get($draft->media_payload, '0.path');

    expect($storedPath)->toBeString()->not->toBe('');
    Storage::disk('public')->assertExists($storedPath);
    $create->assertJsonPath('draft.image_url', Storage::disk('public')->url($storedPath));

    $update = $this->actingAs($owner)
        ->post(route('social.posts.update', $draftId), [
            '_method' => 'PUT',
            'text' => 'Updated local image draft',
            'image_file' => UploadedFile::fake()->image('pulse-local-updated.png', 1280, 720),
            'target_connection_ids' => [$facebook->id],
        ]);

    $update->assertOk()
        ->assertJsonPath('draft.status', SocialPost::STATUS_DRAFT)
        ->assertJsonPath('draft.text', 'Updated local image draft');

    $updatedDraft = SocialPost::query()->findOrFail($draftId);
    $updatedPath = data_get($updatedDraft->media_payload, '0.path');

    expect($updatedPath)->toBeString()->not->toBe('');
    Storage::disk('public')->assertExists($updatedPath);
    $update->assertJsonPath('draft.image_url', Storage::disk('public')->url($updatedPath));
});

it('lets team members with social publish manage pulse drafts while social view stays read only', function () {
    $owner = pulseComposerOwner();
    $publisher = pulseComposerTeamMember($owner, ['social.publish']);
    $viewer = pulseComposerTeamMember($owner, ['social.view']);

    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_INSTAGRAM,
        'label' => 'Main IG',
        'external_account_id' => 'ig-001',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
    ]);

    $this->actingAs($viewer)
        ->get(route('social.composer'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Social/Composer')
            ->where('access.can_view', true)
            ->where('access.can_manage_posts', false)
        );

    $this->actingAs($viewer)
        ->postJson(route('social.posts.store'), [
            'text' => 'Viewer draft',
            'target_connection_ids' => [$connection->id],
        ])
        ->assertForbidden();

    $create = $this->actingAs($publisher)
        ->postJson(route('social.posts.store'), [
            'text' => 'Publisher draft',
            'target_connection_ids' => [$connection->id],
        ]);

    $create->assertCreated()
        ->assertJsonPath('draft.status', SocialPost::STATUS_DRAFT)
        ->assertJsonPath('summary.drafts', 1);

    $draftId = (int) $create->json('draft.id');

    $this->actingAs($publisher)
        ->putJson(route('social.posts.update', $draftId), [
            'text' => 'Publisher scheduled draft',
            'scheduled_for' => '2026-04-24T16:45',
            'target_connection_ids' => [$connection->id],
        ])
        ->assertOk()
        ->assertJsonPath('draft.status', SocialPost::STATUS_SCHEDULED);
});

it('blocks pulse composer routes when the social module is unavailable', function () {
    $owner = pulseComposerOwner([
        'company_features' => [
            'social' => false,
        ],
    ]);

    $this->actingAs($owner)
        ->getJson(route('social.index'))
        ->assertForbidden();

    $this->actingAs($owner)
        ->getJson(route('social.composer'))
        ->assertForbidden();

    $this->actingAs($owner)
        ->postJson(route('social.posts.store'), [
            'text' => 'Blocked draft',
            'target_connection_ids' => [1],
        ])
        ->assertForbidden();
});
