<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Jobs\PublishSocialPostTargetJob;
use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Social\Contracts\PlatformPublisherInterface;
use App\Services\Social\SocialProviderRegistry;
use App\Services\Social\SocialPublishingService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

class PulsePublishingFakePublisher implements PlatformPublisherInterface
{
    /**
     * @param  array<int, string>  $failingPlatforms
     */
    public function __construct(
        private readonly string $platform,
        private readonly array $failingPlatforms = [],
    ) {}

    public function key(): string
    {
        return $this->platform;
    }

    public function label(): string
    {
        return Str::headline($this->platform);
    }

    public function definition(): array
    {
        return [
            'key' => $this->platform,
            'label' => $this->label(),
        ];
    }

    public function beginAuthorization(SocialAccountConnection $connection, string $state): array
    {
        return [
            'redirect_url' => sprintf('https://example.com/%s/oauth?state=%s', $this->platform, $state),
        ];
    }

    public function completeAuthorization(SocialAccountConnection $connection, array $payload): array
    {
        return [];
    }

    public function refreshCredentials(array $credentials): array
    {
        return $credentials;
    }

    public function publish(SocialAccountConnection $connection, array $payload): array
    {
        if (in_array($this->platform, $this->failingPlatforms, true)) {
            throw ValidationException::withMessages([
                'platform' => sprintf('%s temporary publish failure.', Str::headline($this->platform)),
            ]);
        }

        return [
            'provider_post_id' => sprintf('%s-post-%d', $this->platform, $connection->id),
            'published_at' => now()->toIso8601String(),
            'metadata' => [
                'transport' => 'fake-test',
                'platform' => $this->platform,
                'text_preview' => Str::limit((string) ($payload['text'] ?? ''), 80),
            ],
            'message' => sprintf('%s published.', Str::headline($this->platform)),
        ];
    }
}

class PulsePublishingFakeRegistry extends SocialProviderRegistry
{
    /**
     * @param  array<string, PlatformPublisherInterface>  $publishers
     */
    public function __construct(
        private readonly array $publishers,
    ) {}

    public function definitions(): array
    {
        return collect($this->publishers)
            ->map(fn (PlatformPublisherInterface $publisher) => $publisher->definition())
            ->values()
            ->all();
    }

    public function publisher(string $platform): PlatformPublisherInterface
    {
        $publisher = $this->publishers[$platform] ?? null;

        if (! $publisher) {
            throw new InvalidArgumentException(sprintf('Unsupported fake social platform [%s].', $platform));
        }

        return $publisher;
    }
}

function pulsePublishingRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function pulsePublishingOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => pulsePublishingRoleId('owner'),
        'email' => 'pulse-publishing-owner-'.Str::lower(Str::random(10)).'@example.com',
        'company_type' => 'services',
        'company_sector' => 'service_general',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'social' => true,
        ],
    ], $overrides));
}

function pulsePublishingTeamMember(
    User $owner,
    array $permissions = [],
    array $userOverrides = [],
    array $membershipOverrides = []
): User {
    $member = User::factory()->create(array_merge([
        'email' => 'pulse-publishing-member-'.Str::lower(Str::random(10)).'@example.com',
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

function pulsePublishingConnection(User $owner, string $platform, array $overrides = []): SocialAccountConnection
{
    return SocialAccountConnection::query()->create(array_merge([
        'user_id' => $owner->id,
        'platform' => $platform,
        'label' => Str::headline($platform).' account',
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
function pulsePublishingDraft(
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
            'text' => $overrides['text'] ?? 'Pulse launch content',
        ],
        'media_payload' => [
            [
                'type' => 'image',
                'url' => $overrides['image_url'] ?? 'https://example.com/assets/pulse-default.jpg',
            ],
        ],
        'link_url' => $overrides['link_url'] ?? 'https://example.com/offers/pulse-launch',
        'status' => $scheduledFor ? SocialPost::STATUS_SCHEDULED : SocialPost::STATUS_DRAFT,
        'scheduled_for' => $scheduledFor,
        'metadata' => $overrides['metadata'] ?? [
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

/**
 * @param  array<int, string>  $failingPlatforms
 */
function pulsePublishingBindRegistry(array $failingPlatforms = []): void
{
    $publishers = [];

    foreach (SocialAccountConnection::allowedPlatforms() as $platform) {
        $publishers[$platform] = new PulsePublishingFakePublisher($platform, $failingPlatforms);
    }

    app()->instance(SocialProviderRegistry::class, new PulsePublishingFakeRegistry($publishers));
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

it('queues immediate pulse publication and marks all targets as published after workers run', function () {
    Queue::fake();
    pulsePublishingBindRegistry();

    $owner = pulsePublishingOwner();
    $facebook = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $linkedin = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_LINKEDIN);
    $draft = pulsePublishingDraft($owner, $owner, [$facebook, $linkedin]);

    $this->actingAs($owner)
        ->postJson(route('social.posts.publish', $draft))
        ->assertStatus(202)
        ->assertJsonPath('draft.status', SocialPost::STATUS_PUBLISHING)
        ->assertJsonPath('summary.publishing', 1);

    Queue::assertPushed(PublishSocialPostTargetJob::class, 2);

    $service = app(SocialPublishingService::class);
    $targets = SocialPostTarget::query()
        ->where('social_post_id', $draft->id)
        ->orderBy('id')
        ->get();

    foreach ($targets as $target) {
        $service->handleTargetPublication($target->id);
    }

    $freshPost = SocialPost::query()->with('targets.socialAccountConnection')->findOrFail($draft->id);

    expect($freshPost->status)->toBe(SocialPost::STATUS_PUBLISHED)
        ->and($freshPost->published_at)->not->toBeNull()
        ->and($freshPost->targets)->toHaveCount(2)
        ->and($freshPost->targets->every(fn (SocialPostTarget $target) => $target->status === SocialPostTarget::STATUS_PUBLISHED))->toBeTrue();
});

it('queues scheduled pulse publication with a delayed job per target', function () {
    Queue::fake();
    pulsePublishingBindRegistry();

    $owner = pulsePublishingOwner();
    $instagram = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_INSTAGRAM);
    $scheduledFor = Carbon::parse('2026-04-24 14:30:00');
    $draft = pulsePublishingDraft($owner, $owner, [$instagram], [
        'scheduled_for' => $scheduledFor,
    ]);

    $this->actingAs($owner)
        ->postJson(route('social.posts.schedule', $draft))
        ->assertStatus(202)
        ->assertJsonPath('draft.status', SocialPost::STATUS_SCHEDULED)
        ->assertJsonPath('summary.scheduled', 1);

    Queue::assertPushed(PublishSocialPostTargetJob::class, function (PublishSocialPostTargetJob $job) use ($draft, $scheduledFor) {
        $target = SocialPostTarget::query()
            ->where('social_post_id', $draft->id)
            ->first();

        return $target
            && $job->targetId === $target->id
            && $job->delay instanceof Carbon
            && $job->delay->equalTo($scheduledFor);
    });

    $freshPost = SocialPost::query()->with('targets')->findOrFail($draft->id);

    expect($freshPost->status)->toBe(SocialPost::STATUS_SCHEDULED)
        ->and($freshPost->scheduled_for?->equalTo($scheduledFor))->toBeTrue()
        ->and($freshPost->targets->every(fn (SocialPostTarget $target) => $target->status === SocialPostTarget::STATUS_SCHEDULED))->toBeTrue();
});

it('reports a partial failure when only some pulse targets publish successfully', function () {
    Queue::fake();
    pulsePublishingBindRegistry([SocialAccountConnection::PLATFORM_LINKEDIN]);

    $owner = pulsePublishingOwner();
    $facebook = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $linkedin = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_LINKEDIN);
    $draft = pulsePublishingDraft($owner, $owner, [$facebook, $linkedin]);

    $this->actingAs($owner)
        ->postJson(route('social.posts.publish', $draft))
        ->assertStatus(202);

    $service = app(SocialPublishingService::class);
    $targets = SocialPostTarget::query()
        ->where('social_post_id', $draft->id)
        ->orderBy('id')
        ->get();

    foreach ($targets as $target) {
        $service->handleTargetPublication($target->id);
    }

    $freshPost = SocialPost::query()->with('targets.socialAccountConnection')->findOrFail($draft->id);
    $linkedinTarget = $freshPost->targets->firstWhere('socialAccountConnection.platform', SocialAccountConnection::PLATFORM_LINKEDIN);
    $facebookTarget = $freshPost->targets->firstWhere('socialAccountConnection.platform', SocialAccountConnection::PLATFORM_FACEBOOK);

    expect($freshPost->status)->toBe(SocialPost::STATUS_PARTIAL_FAILED)
        ->and($freshPost->failure_reason)->toContain('temporary publish failure')
        ->and($facebookTarget?->status)->toBe(SocialPostTarget::STATUS_PUBLISHED)
        ->and($linkedinTarget?->status)->toBe(SocialPostTarget::STATUS_FAILED)
        ->and((string) $linkedinTarget?->failure_reason)->toContain('temporary publish failure');
});

it('requires social approve in addition to social publish for direct pulse publication and scheduling', function () {
    Queue::fake();
    pulsePublishingBindRegistry();

    $owner = pulsePublishingOwner();
    $publisher = pulsePublishingTeamMember($owner, ['social.publish']);
    $approverPublisher = pulsePublishingTeamMember($owner, ['social.publish', 'social.approve']);
    $manager = pulsePublishingTeamMember($owner, ['social.manage']);
    $connection = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_X);
    $draft = pulsePublishingDraft($owner, $owner, [$connection], [
        'scheduled_for' => Carbon::parse('2026-04-24 16:45:00'),
    ]);

    $this->actingAs($manager)
        ->postJson(route('social.posts.publish', $draft))
        ->assertForbidden();

    $this->actingAs($manager)
        ->postJson(route('social.posts.schedule', $draft))
        ->assertForbidden();

    $this->actingAs($publisher)
        ->postJson(route('social.posts.publish', $draft))
        ->assertForbidden();

    $this->actingAs($publisher)
        ->postJson(route('social.posts.schedule', $draft))
        ->assertForbidden();

    $this->actingAs($approverPublisher)
        ->postJson(route('social.posts.publish', $draft))
        ->assertStatus(202)
        ->assertJsonPath('draft.status', SocialPost::STATUS_PUBLISHING);

    Queue::assertPushed(PublishSocialPostTargetJob::class);
});

it('blocks pulse publish and schedule routes when the social feature is disabled', function () {
    pulsePublishingBindRegistry();

    $owner = pulsePublishingOwner([
        'company_features' => [
            'social' => false,
        ],
    ]);
    $connection = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $draft = pulsePublishingDraft($owner, $owner, [$connection], [
        'scheduled_for' => Carbon::parse('2026-04-24 09:15:00'),
    ]);

    $this->actingAs($owner)
        ->postJson(route('social.posts.publish', $draft))
        ->assertForbidden();

    $this->actingAs($owner)
        ->postJson(route('social.posts.schedule', $draft))
        ->assertForbidden();
});
