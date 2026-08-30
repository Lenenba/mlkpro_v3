<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\SocialPost;
use App\Models\SocialPostRevision;
use App\Models\SocialPostTarget;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Social\SocialMediaAssetService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
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

function pulseComposerConnection(
    User $owner,
    string $platform,
    string $externalAccountId,
    string $label,
): SocialAccountConnection {
    return SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => $platform,
        'label' => $label,
        'external_account_id' => $externalAccountId,
        ...pulseDirectTransportIdentity($owner, $platform, $externalAccountId),
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
    ]);
}

beforeEach(function () {
    config()->set('services.buffer.delivery.enabled', false);
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

it('renders the pulse workspace overview and composer for owners', function () {
    $owner = pulseComposerOwner();

    pulseComposerConnection(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'fb-main',
        'Main page',
    );

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

it('keeps only the selected failed Pulse post available when the composer reloads', function () {
    $owner = pulseComposerOwner();
    $foreignOwner = pulseComposerOwner();
    $connection = pulseComposerConnection(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'fb-selected-failed',
        'Selected failed page',
    );
    $selectedPost = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'content_payload' => ['text' => 'Selected failed publication'],
        'status' => SocialPost::STATUS_FAILED,
    ]);
    SocialPostTarget::query()->create([
        'social_post_id' => $selectedPost->id,
        'social_account_connection_id' => $connection->id,
        'delivery_provider' => $connection->delivery_provider,
        'transport_generation' => $connection->transport_generation,
        'logical_destination_key' => $connection->logical_destination_key,
        'status' => SocialPostTarget::STATUS_FAILED,
    ]);
    $otherFailedPost = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'content_payload' => ['text' => 'Unselected failed publication'],
        'status' => SocialPost::STATUS_FAILED,
    ]);
    $foreignFailedPost = SocialPost::query()->create([
        'user_id' => $foreignOwner->id,
        'created_by_user_id' => $foreignOwner->id,
        'updated_by_user_id' => $foreignOwner->id,
        'content_payload' => ['text' => 'Foreign failed publication'],
        'status' => SocialPost::STATUS_FAILED,
    ]);

    $this->actingAs($owner)
        ->getJson(route('social.composer', ['draft' => $selectedPost->id]))
        ->assertOk()
        ->assertJsonPath('selected_draft_id', $selectedPost->id)
        ->assertJsonPath('drafts.0.id', $selectedPost->id)
        ->assertJsonPath('drafts.0.can_retry', true)
        ->assertJsonMissing(['id' => $otherFailedPost->id]);

    $this->actingAs($owner)
        ->getJson(route('social.composer', ['draft' => $foreignFailedPost->id]))
        ->assertOk()
        ->assertJsonPath('selected_draft_id', null)
        ->assertJsonCount(0, 'drafts');
});

it('lets owners create and update pulse drafts with multi-account selection and scheduling', function () {
    $owner = pulseComposerOwner();

    $facebook = pulseComposerConnection(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'fb-001',
        'North page',
    );
    $linkedin = pulseComposerConnection(
        $owner,
        SocialAccountConnection::PLATFORM_LINKEDIN,
        'li-001',
        'Corporate page',
    );

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
        ->and((string) data_get($draft->media_payload, '0.url'))->toBe('https://example.com/assets/pulse-spring-updated.jpg')
        ->and((string) $draft->link_url)->toBe('https://example.com/offers/spring-v2')
        ->and((bool) data_get($draft->metadata, 'has_image'))->toBeTrue()
        ->and((string) data_get($draft->metadata, 'link_cta_label'))->toBe('Magasiner maintenant')
        ->and($draft->scheduled_for)->not->toBeNull()
        ->and($draft->targets)->toHaveCount(1)
        ->and((int) $draft->targets->first()->social_account_connection_id)->toBe((int) $linkedin->id);
});

it('stores ordered Buffer image video and document assets from the composer', function () {
    $owner = pulseComposerOwner();
    $facebook = pulseComposerConnection(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'fb-buffer-media',
        'Buffer media page',
    );

    $mediaAssets = [
        [
            'type' => 'image',
            'url' => 'https://cdn.example.com/pulse-cover.jpg',
            'alt_text' => 'Malikia Pulse cover',
        ],
        [
            'type' => 'image',
            'url' => 'https://cdn.example.com/pulse-details.jpg',
        ],
        [
            'type' => 'video',
            'url' => 'https://cdn.example.com/pulse-demo.mp4',
            'title' => 'Malikia Pulse demo',
            'thumbnail_offset' => 1500,
        ],
        [
            'type' => 'document',
            'url' => 'https://cdn.example.com/pulse-guide.pdf',
            'title' => 'Malikia Pulse guide',
            'thumbnail_url' => 'https://cdn.example.com/pulse-guide-cover.jpg',
        ],
    ];

    $response = $this->actingAs($owner)
        ->postJson(route('social.posts.store'), [
            'media_assets' => $mediaAssets,
            'target_connection_ids' => [$facebook->id],
        ]);

    $response->assertCreated()
        ->assertJsonCount(4, 'draft.media_assets')
        ->assertJsonPath('draft.image_url', 'https://cdn.example.com/pulse-cover.jpg')
        ->assertJsonPath('draft.media_assets.2.type', 'video')
        ->assertJsonPath('draft.media_assets.2.thumbnail_offset', 1500)
        ->assertJsonPath('draft.media_assets.3.type', 'document')
        ->assertJsonPath('draft.media_assets.3.thumbnail_url', 'https://cdn.example.com/pulse-guide-cover.jpg');

    $draft = SocialPost::query()->findOrFail((int) $response->json('draft.id'));

    expect($draft->media_payload)->toHaveCount(4)
        ->and(data_get($draft->media_payload, '0.alt_text'))->toBe('Malikia Pulse cover')
        ->and(data_get($draft->media_payload, '1.url'))->toBe('https://cdn.example.com/pulse-details.jpg')
        ->and(data_get($draft->media_payload, '2.title'))->toBe('Malikia Pulse demo')
        ->and(data_get($draft->media_payload, '3.title'))->toBe('Malikia Pulse guide')
        ->and((bool) data_get($draft->metadata, 'has_media'))->toBeTrue();

    $this->actingAs($owner)
        ->postJson(route('social.posts.store'), [
            'media_assets' => [[
                'type' => 'document',
                'url' => 'https://cdn.example.com/incomplete.pdf',
            ]],
            'target_connection_ids' => [$facebook->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('media_assets.0');

    $this->actingAs($owner)
        ->postJson(route('social.posts.store'), [
            'media_assets' => [[
                'type' => 'video',
                'url' => 'http://cdn.example.com/insecure.mp4',
            ]],
            'target_connection_ids' => [$facebook->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('media_assets.0.url');
});

it('preserves Buffer media order and rejects more than twenty combined assets', function () {
    $owner = pulseComposerOwner();
    $facebook = pulseComposerConnection(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'fb-buffer-media-order',
        'Buffer ordered media page',
    );
    $imageUrl = 'https://cdn.example.com/pulse-after-video.jpg';

    $response = $this->actingAs($owner)
        ->postJson(route('social.posts.store'), [
            'image_url' => $imageUrl,
            'media_assets' => [
                [
                    'type' => 'video',
                    'url' => 'https://cdn.example.com/pulse-first.mp4',
                ],
                [
                    'type' => 'image',
                    'url' => $imageUrl,
                ],
            ],
            'target_connection_ids' => [$facebook->id],
        ]);

    $response->assertCreated();

    $draft = SocialPost::query()->findOrFail((int) $response->json('draft.id'));

    expect(data_get($draft->media_payload, '0.type'))->toBe('video')
        ->and(data_get($draft->media_payload, '1.type'))->toBe('image')
        ->and(data_get($draft->media_payload, '1.url'))->toBe($imageUrl);

    $this->actingAs($owner)
        ->postJson(route('social.posts.store'), [
            'image_url' => 'https://cdn.example.com/primary.jpg',
            'media_assets' => collect(range(1, 20))
                ->map(fn (int $index): array => [
                    'type' => 'image',
                    'url' => 'https://cdn.example.com/asset-'.$index.'.jpg',
                ])
                ->all(),
            'target_connection_ids' => [$facebook->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('media_assets');
});

it('stores browser wall-clock schedules as UTC in the workspace timezone', function () {
    $this->travelTo(Carbon::parse('2026-08-28 12:00:00', 'UTC'));
    $owner = pulseComposerOwner(['company_timezone' => 'America/Toronto']);
    $facebook = pulseComposerConnection(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'fb-timezone-contract',
        'Timezone page',
    );

    $response = $this->actingAs($owner)
        ->postJson(route('social.posts.store'), [
            'text' => 'Tenant-local schedule contract.',
            'scheduled_for' => '2026-08-29T10:00',
            'target_connection_ids' => [$facebook->id],
        ]);

    $response->assertCreated()
        ->assertJsonPath('draft.scheduled_for', '2026-08-29T14:00:00+00:00')
        ->assertJsonPath('draft.scheduled_local_time', '2026-08-29T10:00')
        ->assertJsonPath('draft.scheduled_timezone', 'America/Toronto');

    $post = SocialPost::query()->findOrFail((int) $response->json('draft.id'));

    expect($post->scheduled_for?->copy()->utc()->toDateTimeString())
        ->toBe('2026-08-29 14:00:00')
        ->and($post->scheduled_local_time?->format('Y-m-d H:i:s'))
        ->toBe('2026-08-29 10:00:00')
        ->and($post->scheduled_timezone)->toBe('America/Toronto');
});

it('renders the pulse editorial calendar from existing posts', function () {
    $owner = pulseComposerOwner();

    SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'content_payload' => [
            'text' => 'Draft calendar idea',
        ],
        'status' => SocialPost::STATUS_DRAFT,
    ]);

    SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'content_payload' => [
            'text' => 'Scheduled calendar idea',
        ],
        'status' => SocialPost::STATUS_SCHEDULED,
        'scheduled_for' => Carbon::now()->addDays(2)->setTime(10, 30),
    ]);

    SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'content_payload' => [
            'text' => 'Published calendar idea',
        ],
        'status' => SocialPost::STATUS_PUBLISHED,
        'published_at' => Carbon::now()->subDay()->setTime(14, 0),
    ]);

    $this->actingAs($owner)
        ->get(route('social.calendar'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Social/Calendar')
            ->has('calendar_posts', 3)
            ->where('summary.scheduled', 1)
            ->where('summary.published', 1)
            ->where('access.can_manage_posts', true)
        );

    $this->actingAs($owner)
        ->getJson(route('social.calendar'))
        ->assertOk()
        ->assertJsonCount(3, 'calendar_posts')
        ->assertJsonFragment(['calendar_bucket' => 'draft'])
        ->assertJsonFragment(['calendar_bucket' => 'scheduled'])
        ->assertJsonFragment(['calendar_bucket' => 'published']);
});

it('lets owners reschedule editable pulse drafts from the calendar', function () {
    $owner = pulseComposerOwner();

    $connection = pulseComposerConnection(
        $owner,
        SocialAccountConnection::PLATFORM_LINKEDIN,
        'li-calendar',
        'Calendar page',
    );

    $draft = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'content_payload' => [
            'text' => 'Calendar reschedule draft',
        ],
        'status' => SocialPost::STATUS_DRAFT,
    ]);

    SocialPostTarget::query()->create([
        'social_post_id' => $draft->id,
        'social_account_connection_id' => $connection->id,
        'status' => SocialPostTarget::STATUS_PENDING,
    ]);

    $scheduledFor = Carbon::now()->addDays(3)->setTime(11, 15);

    $this->actingAs($owner)
        ->putJson(route('social.posts.reschedule', $draft), [
            'scheduled_for' => $scheduledFor->toIso8601String(),
        ])
        ->assertOk()
        ->assertJsonPath('draft.status', SocialPost::STATUS_SCHEDULED)
        ->assertJsonPath('summary.scheduled', 1)
        ->assertJsonFragment(['calendar_bucket' => 'scheduled']);

    $scheduledDraft = $draft->fresh(['targets']);

    expect($scheduledDraft?->status)->toBe(SocialPost::STATUS_SCHEDULED)
        ->and($scheduledDraft?->scheduled_for?->equalTo($scheduledFor))->toBeTrue()
        ->and($scheduledDraft?->targets->first()?->status)->toBe(SocialPostTarget::STATUS_SCHEDULED);

    $this->actingAs($owner)
        ->putJson(route('social.posts.reschedule', $draft), [
            'scheduled_for' => null,
        ])
        ->assertOk()
        ->assertJsonPath('draft.status', SocialPost::STATUS_DRAFT)
        ->assertJsonPath('summary.scheduled', 0);

    $unscheduledDraft = $draft->fresh(['targets']);

    expect($unscheduledDraft?->status)->toBe(SocialPost::STATUS_DRAFT)
        ->and($unscheduledDraft?->scheduled_for)->toBeNull()
        ->and($unscheduledDraft?->targets->first()?->status)->toBe(SocialPostTarget::STATUS_PENDING);
});

it('blocks calendar rescheduling for queued pulse publications', function () {
    $owner = pulseComposerOwner();

    $post = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'content_payload' => [
            'text' => 'Already queued calendar post',
        ],
        'status' => SocialPost::STATUS_SCHEDULED,
        'scheduled_for' => Carbon::now()->addDays(2),
        'metadata' => [
            'publish_requested_at' => now()->toIso8601String(),
        ],
    ]);

    $this->actingAs($owner)
        ->putJson(route('social.posts.reschedule', $post), [
            'scheduled_for' => Carbon::now()->addDays(5)->toIso8601String(),
        ])
        ->assertUnprocessable();
});

it('lets owners upload local images for pulse drafts', function () {
    Storage::fake('public');

    $owner = pulseComposerOwner();

    $facebook = pulseComposerConnection(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'fb-upload-001',
        'North page',
    );

    $create = $this->actingAs($owner)
        ->post(route('social.posts.store'), [
            'text' => 'Local image draft',
            'image_file' => UploadedFile::fake()->image('pulse-local.png', 1200, 800),
            'media_assets' => json_encode([[
                'type' => 'video',
                'url' => 'https://cdn.example.com/pulse-upload-demo.mp4',
            ]], JSON_THROW_ON_ERROR),
            'target_connection_ids' => [$facebook->id],
        ]);

    $create->assertCreated()
        ->assertJsonPath('draft.status', SocialPost::STATUS_DRAFT)
        ->assertJsonPath('draft.selected_accounts_count', 1);

    $draftId = (int) $create->json('draft.id');
    $draft = SocialPost::query()->findOrFail($draftId);
    $storedPath = data_get($draft->media_payload, '0.path');

    expect($storedPath)->toBeString()->not->toBe('');
    expect($draft->media_payload)->toHaveCount(2)
        ->and(data_get($draft->media_payload, '1.type'))->toBe('video')
        ->and(data_get($draft->media_payload, '1.url'))->toBe('https://cdn.example.com/pulse-upload-demo.mp4');
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
    $originalRevision = SocialPostRevision::query()
        ->where('social_post_id', $draftId)
        ->oldest('revision_number')
        ->firstOrFail();

    expect(data_get($originalRevision->media_snapshot, 'items.0.path'))->toBe($storedPath);
    Storage::disk('public')->assertExists($storedPath);
    Storage::disk('public')->assertExists($updatedPath);
    $update->assertJsonPath('draft.image_url', Storage::disk('public')->url($updatedPath));
});

it('deletes replaced pulse uploads that have no persisted reference', function () {
    Storage::fake('public');

    $owner = pulseComposerOwner();
    $facebook = pulseComposerConnection(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'fb-unreferenced-replacement',
        'Unreferenced replacement page',
    );
    $oldAsset = app(SocialMediaAssetService::class)->storeUploadedMedia(
        $owner,
        UploadedFile::fake()->create('unreferenced-old.mp4', 64, 'video/mp4'),
        'posts',
    );
    $oldPath = (string) $oldAsset['path'];
    $post = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'content_payload' => ['text' => 'Draft without an editorial revision'],
        'media_payload' => [$oldAsset],
        'status' => SocialPost::STATUS_DRAFT,
    ]);

    $response = $this->actingAs($owner)
        ->post(route('social.posts.update', $post), [
            '_method' => 'PUT',
            'text' => 'Draft with a replacement upload',
            'media_files' => [
                UploadedFile::fake()->create('replacement.mp4', 64, 'video/mp4'),
            ],
            'target_connection_ids' => [$facebook->id],
        ]);

    $response->assertOk();
    $replacementPath = (string) $response->json('draft.media_assets.0.path');

    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($replacementPath);
});

it('retains replaced uploads referenced by legacy pulse revision media lists', function () {
    Storage::fake('public');

    $owner = pulseComposerOwner();
    $facebook = pulseComposerConnection(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'fb-legacy-revision-media',
        'Legacy revision media page',
    );
    $oldAsset = app(SocialMediaAssetService::class)->storeUploadedMedia(
        $owner,
        UploadedFile::fake()->create('legacy-revision.mp4', 64, 'video/mp4'),
        'posts',
    );
    $oldPath = (string) $oldAsset['path'];
    $post = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'content_payload' => ['text' => 'Draft with a legacy revision'],
        'media_payload' => [$oldAsset],
        'status' => SocialPost::STATUS_DRAFT,
    ]);
    SocialPostRevision::query()->create([
        'user_id' => $owner->id,
        'social_post_id' => $post->id,
        'revision_number' => 1,
        'base_content' => ['content_payload' => ['text' => 'Legacy snapshot']],
        'source_snapshot' => [],
        'media_snapshot' => [$oldAsset],
        'scheduled_timezone' => 'UTC',
        'payload_hash' => hash('sha256', 'legacy-media-'.$post->id),
        'created_by_user_id' => $owner->id,
        'origin' => SocialPostRevision::ORIGIN_LEGACY_BACKFILL_V1,
    ]);

    $this->actingAs($owner)
        ->post(route('social.posts.update', $post), [
            '_method' => 'PUT',
            'text' => 'Draft replacing legacy revision media',
            'media_files' => [
                UploadedFile::fake()->create('replacement.mp4', 64, 'video/mp4'),
            ],
            'target_connection_ids' => [$facebook->id],
        ])
        ->assertOk();

    Storage::disk('public')->assertExists($oldPath);
});

it('stores uploaded image video and document files in order for pulse drafts', function () {
    Storage::fake('public');

    $owner = pulseComposerOwner();
    $facebook = pulseComposerConnection(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'fb-media-files',
        'Media upload page',
    );

    $response = $this->actingAs($owner)
        ->post(route('social.posts.store'), [
            'text' => 'Draft with local media',
            'media_files' => [
                UploadedFile::fake()->image('cover.jpg', 1200, 800),
                UploadedFile::fake()->create('launch.mp4', 128, 'video/mp4'),
                UploadedFile::fake()->create('brief.pdf', 64, 'application/pdf'),
            ],
            'target_connection_ids' => [$facebook->id],
        ]);

    $response->assertCreated()
        ->assertJsonPath('draft.media_assets.0.type', 'image')
        ->assertJsonPath('draft.media_assets.1.type', 'video')
        ->assertJsonPath('draft.media_assets.2.type', 'document');

    $draft = SocialPost::query()->findOrFail((int) $response->json('draft.id'));
    $media = (array) $draft->media_payload;

    expect($media)->toHaveCount(3)
        ->and(array_column($media, 'type'))->toBe(['image', 'video', 'document'])
        ->and(array_column($media, 'name'))->toBe(['cover.jpg', 'launch.mp4', 'brief.pdf'])
        ->and(array_column($media, 'disk'))->toBe(['public', 'public', 'public'])
        ->and(data_get($media, '0.mime_type'))->toBe('image/jpeg')
        ->and(data_get($media, '1.mime_type'))->toBe('video/mp4')
        ->and(data_get($media, '2.mime_type'))->toBe('application/pdf')
        ->and(data_get($media, '2.title'))->toBe('brief')
        ->and((string) data_get($media, '2.thumbnail_url'))
        ->toEndWith('/storage/social/system/document-thumbnail.png');

    Storage::disk('public')->assertExists('social/system/document-thumbnail.png');

    foreach ($media as $asset) {
        expect(data_get($asset, 'path'))->toBeString()->not->toBe('');
        Storage::disk('public')->assertExists((string) data_get($asset, 'path'));
    }

    $response->assertJsonPath('draft.image_url', data_get($media, '0.url'));
});

it('rejects unsupported or oversized pulse media uploads', function (
    string $fileName,
    int $sizeInKilobytes,
    string $mimeType,
) {
    Storage::fake('public');

    $owner = pulseComposerOwner();
    $facebook = pulseComposerConnection(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'fb-invalid-media-files',
        'Invalid media upload page',
    );

    $response = $this->actingAs($owner)
        ->withHeader('Accept', 'application/json')
        ->post(route('social.posts.store'), [
            'text' => 'Invalid local media',
            'media_files' => [
                UploadedFile::fake()->create($fileName, $sizeInKilobytes, $mimeType),
            ],
            'target_connection_ids' => [$facebook->id],
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['media_files.0']);

    expect(SocialPost::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    'unsupported executable' => ['payload.exe', 1, 'application/x-msdownload'],
    'image over 10 MiB' => ['large.png', 10241, 'image/png'],
    'video over 24 MiB' => ['large.mp4', 24577, 'video/mp4'],
]);

it('enforces the twenty item total across remote and uploaded pulse media', function () {
    Storage::fake('public');

    $owner = pulseComposerOwner();
    $facebook = pulseComposerConnection(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'fb-media-total-limit',
        'Media limit page',
    );
    $remoteAssets = collect(range(1, 20))
        ->map(fn (int $index): array => [
            'type' => 'image',
            'url' => 'https://cdn.example.com/media-'.$index.'.jpg',
        ])
        ->all();

    $response = $this->actingAs($owner)
        ->withHeader('Accept', 'application/json')
        ->post(route('social.posts.store'), [
            'text' => 'Too many mixed media items',
            'media_assets' => json_encode($remoteAssets, JSON_THROW_ON_ERROR),
            'media_files' => [
                UploadedFile::fake()->image('extra.jpg', 600, 400),
            ],
            'target_connection_ids' => [$facebook->id],
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['media_assets', 'media_files']);

    expect(SocialPost::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('rejects pulse upload batches over the one hundred MiB budget', function () {
    Storage::fake('public');

    $owner = pulseComposerOwner();
    $facebook = pulseComposerConnection(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'fb-upload-byte-limit',
        'Upload byte limit page',
    );
    $mediaFiles = collect(range(1, 5))
        ->map(fn (int $index): UploadedFile => UploadedFile::fake()->create(
            'large-'.$index.'.mp4',
            21 * 1024,
            'video/mp4',
        ))
        ->all();

    $response = $this->actingAs($owner)
        ->withHeader('Accept', 'application/json')
        ->post(route('social.posts.store'), [
            'text' => 'Upload batch over its total byte budget',
            'media_files' => $mediaFiles,
            'target_connection_ids' => [$facebook->id],
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['media_files']);

    expect(SocialPost::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('accepts pulse upload batches below the one hundred MiB budget', function () {
    Storage::fake('public');

    $owner = pulseComposerOwner();
    $facebook = pulseComposerConnection(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'fb-upload-byte-budget',
        'Upload byte budget page',
    );
    $mediaFiles = collect(range(1, 4))
        ->map(fn (int $index): UploadedFile => UploadedFile::fake()->create(
            'allowed-'.$index.'.mp4',
            24 * 1024,
            'video/mp4',
        ))
        ->all();

    $response = $this->actingAs($owner)
        ->post(route('social.posts.store'), [
            'text' => 'Upload batch within its total byte budget',
            'media_files' => $mediaFiles,
            'target_connection_ids' => [$facebook->id],
        ]);

    $response->assertCreated()
        ->assertJsonCount(4, 'draft.media_assets');

    foreach ((array) $response->json('draft.media_assets') as $asset) {
        Storage::disk('public')->assertExists((string) ($asset['path'] ?? ''));
    }
});

it('rolls back newly uploaded media when pulse draft targets are invalid', function () {
    Storage::fake('public');

    $owner = pulseComposerOwner();

    $response = $this->actingAs($owner)
        ->withHeader('Accept', 'application/json')
        ->post(route('social.posts.store'), [
            'text' => 'Draft whose target is invalid',
            'media_files' => [
                UploadedFile::fake()->image('rollback.jpg', 800, 600),
            ],
            'target_connection_ids' => [999999],
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['target_connection_ids']);

    expect(SocialPost::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('does not store media before checking that a pulse draft remains editable', function () {
    Storage::fake('public');

    $owner = pulseComposerOwner();
    $facebook = pulseComposerConnection(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'fb-locked-media',
        'Locked media page',
    );
    $post = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'content_payload' => ['text' => 'Published content'],
        'status' => SocialPost::STATUS_PUBLISHED,
        'published_at' => now(),
    ]);

    $response = $this->actingAs($owner)
        ->withHeader('Accept', 'application/json')
        ->post(route('social.posts.update', $post), [
            '_method' => 'PUT',
            'text' => 'Attempted locked edit',
            'media_files' => [
                UploadedFile::fake()->image('locked.jpg', 800, 600),
            ],
            'target_connection_ids' => [$facebook->id],
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['post']);

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('rolls back earlier files when a pulse media upload batch fails', function () {
    Storage::fake('public');

    $owner = pulseComposerOwner();
    $facebook = pulseComposerConnection(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'fb-partial-media-failure',
        'Partial media failure page',
    );
    $temporaryFile = UploadedFile::fake()->create('broken.pdf', 1, 'application/pdf');
    $failingUpload = new class($temporaryFile->getPathname(), 'broken.pdf', 'application/pdf', null, true) extends UploadedFile
    {
        public function getMimeType(): string
        {
            return 'application/pdf';
        }

        public function getSize(): int|false
        {
            return 1024;
        }

        public function store($path = '', $options = [])
        {
            throw new RuntimeException('Simulated second upload failure.');
        }
    };

    $this->actingAs($owner);
    $this->withoutExceptionHandling();

    expect(fn () => $this->post(route('social.posts.store'), [
        'text' => 'Batch with a storage failure',
        'media_files' => [
            UploadedFile::fake()->image('stored-first.jpg', 800, 600),
            $failingUpload,
        ],
        'target_connection_ids' => [$facebook->id],
    ]))->toThrow(RuntimeException::class, 'Simulated second upload failure.');

    expect(SocialPost::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('lets team members with social publish manage pulse drafts while social view stays read only', function () {
    $owner = pulseComposerOwner();
    $publisher = pulseComposerTeamMember($owner, ['social.publish']);
    $viewer = pulseComposerTeamMember($owner, ['social.view']);

    $connection = pulseComposerConnection(
        $owner,
        SocialAccountConnection::PLATFORM_INSTAGRAM,
        'ig-001',
        'Main IG',
    );

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
