<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Role;
use App\Models\SocialMediaAsset;
use App\Models\SocialPost;
use App\Models\SocialPostTemplate;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function pulseMediaRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function pulseMediaOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => pulseMediaRoleId('owner'),
        'email' => 'pulse-media-owner-'.Str::lower(Str::random(10)).'@example.com',
        'company_type' => 'services',
        'company_sector' => 'service_general',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'social' => true,
        ],
    ], $overrides));
}

function pulseMediaTeamMember(
    User $owner,
    array $permissions = [],
    array $userOverrides = [],
    array $membershipOverrides = []
): User {
    $member = User::factory()->create(array_merge([
        'email' => 'pulse-media-member-'.Str::lower(Str::random(10)).'@example.com',
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

it('renders the pulse media library from posts and templates', function () {
    $owner = pulseMediaOwner();

    SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'content_payload' => [
            'text' => 'Post with an uploaded image',
        ],
        'media_payload' => [
            [
                'type' => 'image',
                'url' => 'https://example.com/social/post-upload.jpg',
                'source' => 'upload',
                'name' => 'post-upload.jpg',
            ],
        ],
        'status' => SocialPost::STATUS_DRAFT,
    ]);

    SocialPostTemplate::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'AI template visual',
        'media_payload' => [
            [
                'type' => 'image',
                'url' => 'https://example.com/social/template-ai.jpg',
                'source' => 'ai',
                'name' => 'template-ai.jpg',
            ],
        ],
    ]);

    $this->actingAs($owner)
        ->get(route('social.media.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Social/MediaLibrary')
            ->has('assets', 2)
            ->where('summary.total', 2)
            ->where('summary.uploads', 1)
            ->where('summary.ai', 1)
            ->where('access.can_manage_posts', true)
        );

    $this->actingAs($owner)
        ->getJson(route('social.media.index', ['source' => 'ai']))
        ->assertOk()
        ->assertJsonCount(1, 'assets')
        ->assertJsonPath('assets.0.source', 'ai')
        ->assertJsonPath('assets.0.origin', 'template');
});

it('lets authorized users upload a reusable media asset and open it in the composer', function () {
    Storage::fake('public');

    $owner = pulseMediaOwner();
    $publisher = pulseMediaTeamMember($owner, ['social.publish']);
    $viewer = pulseMediaTeamMember($owner, ['social.view']);

    $this->actingAs($viewer)
        ->withHeaders(['Accept' => 'application/json'])
        ->post(route('social.media.store'), [
            'image_file' => UploadedFile::fake()->image('blocked.png', 900, 900),
        ])
        ->assertForbidden();

    $upload = $this->actingAs($publisher)
        ->post(route('social.media.store'), [
            'image_file' => UploadedFile::fake()->image('pulse-library.png', 1200, 800),
        ]);

    $upload->assertCreated()
        ->assertJsonPath('asset.source', 'upload')
        ->assertJsonPath('asset.origin', 'library')
        ->assertJsonPath('summary.uploads', 1);

    $path = (string) $upload->json('asset.path');
    $url = (string) $upload->json('asset.url');

    expect($path)->not->toBe('')
        ->and($url)->not->toBe('')
        ->and(SocialMediaAsset::query()->where('user_id', $owner->id)->count())->toBe(1);

    Storage::disk('public')->assertExists($path);

    $this->actingAs($owner)
        ->getJson(route('social.composer', ['image_url' => $url]))
        ->assertOk()
        ->assertJsonPath('initial_media_url', $url);
});

it('blocks pulse media routes when the social module is unavailable', function () {
    $owner = pulseMediaOwner([
        'company_features' => [
            'social' => false,
        ],
    ]);

    $this->actingAs($owner)
        ->getJson(route('social.media.index'))
        ->assertForbidden();

    $this->actingAs($owner)
        ->withHeaders(['Accept' => 'application/json'])
        ->post(route('social.media.store'), [
            'image_file' => UploadedFile::fake()->image('blocked.png', 900, 900),
        ])
        ->assertForbidden();
});
