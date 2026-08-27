<?php

use App\Exceptions\Social\RetryableSocialPublishingException;
use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Jobs\PublishSocialPostTargetJob;
use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\SocialApprovalRequest;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Social\Contracts\PlatformPublisherInterface;
use App\Services\Social\Providers\LinkedInPagePlatformPublisher;
use App\Services\Social\SocialProviderRegistry;
use App\Services\Social\SocialPublishingService;
use App\Support\QueueWorkload;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

class PulsePublishingFakePublisher implements PlatformPublisherInterface
{
    public int $publishCalls = 0;

    /**
     * @param  array<int, string>  $failingPlatforms
     * @param  array<int, string>  $retryablePlatforms
     */
    public function __construct(
        private readonly string $platform,
        private readonly array $failingPlatforms = [],
        private readonly array $retryablePlatforms = [],
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
        $this->publishCalls++;

        if (in_array($this->platform, $this->retryablePlatforms, true)) {
            throw new RetryableSocialPublishingException(sprintf(
                '%s temporary transport failure.',
                Str::headline($this->platform)
            ));
        }

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
 * @param  array<int, string>  $retryablePlatforms
 * @return array<string, PulsePublishingFakePublisher>
 */
function pulsePublishingBindRegistry(array $failingPlatforms = [], array $retryablePlatforms = []): array
{
    $publishers = [];

    foreach (SocialAccountConnection::allowedPlatforms() as $platform) {
        $publishers[$platform] = new PulsePublishingFakePublisher(
            $platform,
            $failingPlatforms,
            $retryablePlatforms
        );
    }

    app()->instance(SocialProviderRegistry::class, new PulsePublishingFakeRegistry($publishers));

    return $publishers;
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
    $scheduledFor = Carbon::now()->addDays(2)->setTime(14, 30);
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

it('does not redispatch a pulse post that is already scheduled for publication', function () {
    Queue::fake();
    pulsePublishingBindRegistry();

    $owner = pulsePublishingOwner();
    $connection = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_INSTAGRAM);
    $draft = pulsePublishingDraft($owner, $owner, [$connection], [
        'scheduled_for' => Carbon::now()->addDays(2)->setTime(14, 30),
    ]);

    $this->actingAs($owner)
        ->postJson(route('social.posts.schedule', $draft))
        ->assertStatus(202);

    $queuedPost = $draft->fresh();
    $queuedTarget = $draft->targets()->sole();
    $queuedPostAttributes = $queuedPost->getAttributes();
    $queuedTargetAttributes = $queuedTarget->getAttributes();

    $this->actingAs($owner)
        ->postJson(route('social.posts.schedule', $draft))
        ->assertStatus(422)
        ->assertJsonValidationErrors('post')
        ->assertJsonPath(
            'errors.post.0',
            'This Pulse post is already scheduled for publication.'
        );

    Queue::assertPushed(PublishSocialPostTargetJob::class, 1);

    expect($draft->fresh()->getAttributes())->toBe($queuedPostAttributes)
        ->and($queuedTarget->fresh()->getAttributes())->toBe($queuedTargetAttributes);
});

it('returns 422 without mutations when a pending approval is published or scheduled directly', function (
    string $actorType,
    string $routeName
) {
    Queue::fake([PublishSocialPostTargetJob::class]);

    $owner = pulsePublishingOwner();
    $actor = $actorType === 'owner'
        ? $owner
        : pulsePublishingTeamMember($owner, ['social.publish', 'social.approve']);
    $requester = pulsePublishingTeamMember($owner, ['social.publish']);
    $connection = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_LINKEDIN);
    $draft = pulsePublishingDraft($owner, $requester, [$connection], [
        'scheduled_for' => Carbon::now()->addDays(2)->setTime(15, 30),
    ]);
    $approvalRequest = $draft->approvalRequests()->create([
        'requested_by_user_id' => $requester->id,
        'status' => SocialApprovalRequest::STATUS_PENDING,
        'note' => 'Awaiting an explicit approval decision.',
        'requested_at' => now(),
        'metadata' => [
            'requested_mode' => 'scheduled',
            'scheduled_for' => $draft->scheduled_for?->toIso8601String(),
        ],
    ]);
    $draft->forceFill([
        'status' => SocialPost::STATUS_PENDING_APPROVAL,
        'metadata' => array_merge((array) $draft->metadata, [
            'approval' => [
                'status' => SocialApprovalRequest::STATUS_PENDING,
                'request_id' => $approvalRequest->id,
            ],
        ]),
    ])->save();
    $postBeforePublication = $draft->fresh()->getAttributes();
    $target = $draft->targets()->sole();
    $targetBeforePublication = $target->getAttributes();
    $approvalRequestBeforePublication = $approvalRequest->fresh()->getAttributes();

    $this->actingAs($actor)
        ->postJson(route($routeName, $draft))
        ->assertStatus(422)
        ->assertJsonValidationErrors('post')
        ->assertJsonPath(
            'errors.post.0',
            'This Pulse post is waiting for approval and cannot be queued directly.'
        );

    Queue::assertNothingPushed();

    expect($draft->fresh()->getAttributes())->toBe($postBeforePublication)
        ->and($target->fresh()->getAttributes())->toBe($targetBeforePublication)
        ->and($approvalRequest->fresh()->getAttributes())->toBe($approvalRequestBeforePublication)
        ->and($draft->approvalRequests()->count())->toBe(1);
})->with([
    'owner publish' => ['owner', 'social.posts.publish'],
    'owner schedule' => ['owner', 'social.posts.schedule'],
    'approver publisher publish' => ['approver_publisher', 'social.posts.publish'],
    'approver publisher schedule' => ['approver_publisher', 'social.posts.schedule'],
]);

it('does not run a queued target while its pulse post is waiting for approval', function () {
    $publishers = pulsePublishingBindRegistry();

    $owner = pulsePublishingOwner();
    $connection = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_LINKEDIN);
    $draft = pulsePublishingDraft($owner, $owner, [$connection]);
    $approvalRequest = $draft->approvalRequests()->create([
        'requested_by_user_id' => $owner->id,
        'status' => SocialApprovalRequest::STATUS_PENDING,
        'requested_at' => now(),
        'metadata' => [
            'requested_mode' => 'immediate',
        ],
    ]);
    $draft->forceFill([
        'status' => SocialPost::STATUS_PENDING_APPROVAL,
    ])->save();
    $postBeforePublication = $draft->fresh()->getAttributes();
    $target = $draft->targets()->sole();
    $targetBeforePublication = $target->getAttributes();
    $approvalRequestBeforePublication = $approvalRequest->fresh()->getAttributes();

    app(SocialPublishingService::class)->handleTargetPublication($target->id);

    expect($publishers[SocialAccountConnection::PLATFORM_LINKEDIN]->publishCalls)->toBe(0)
        ->and($draft->fresh()->getAttributes())->toBe($postBeforePublication)
        ->and($target->fresh()->getAttributes())->toBe($targetBeforePublication)
        ->and($approvalRequest->fresh()->getAttributes())->toBe($approvalRequestBeforePublication);
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

it('keeps retryable publication failures non-terminal so the queue can retry', function () {
    pulsePublishingBindRegistry([], [SocialAccountConnection::PLATFORM_FACEBOOK]);

    $owner = pulsePublishingOwner();
    $connection = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $draft = pulsePublishingDraft($owner, $owner, [$connection]);
    $target = $draft->targets->sole();
    $service = app(SocialPublishingService::class);

    expect(fn () => $service->handleTargetPublication($target->id))
        ->toThrow(RetryableSocialPublishingException::class, 'Facebook temporary transport failure.');

    $retryableTarget = $target->fresh();
    $retryablePost = $draft->fresh();

    expect($retryableTarget->status)->toBe(SocialPostTarget::STATUS_PUBLISHING)
        ->and($retryableTarget->failed_at)->toBeNull()
        ->and($retryableTarget->failure_reason)->toBeNull()
        ->and($retryablePost->status)->toBe(SocialPost::STATUS_PUBLISHING)
        ->and($retryablePost->failed_at)->toBeNull()
        ->and($retryablePost->failure_reason)->toBeNull();
});

it('marks a publishing target failed when the queue invokes the job failure callback', function () {
    $owner = pulsePublishingOwner();
    $connection = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $draft = pulsePublishingDraft($owner, $owner, [$connection]);
    $target = $draft->targets->sole();
    $draft->forceFill([
        'status' => SocialPost::STATUS_PUBLISHING,
    ])->save();
    $target->forceFill([
        'status' => SocialPostTarget::STATUS_PUBLISHING,
    ])->save();

    $exception = new RetryableSocialPublishingException('Facebook queue failure callback.');
    (new PublishSocialPostTargetJob($target->id))->failed($exception);

    $failedTarget = $target->fresh();
    $failedPost = $draft->fresh();

    expect($failedTarget->status)->toBe(SocialPostTarget::STATUS_FAILED)
        ->and($failedTarget->failed_at)->not->toBeNull()
        ->and($failedTarget->failure_reason)->toBe('Facebook queue failure callback.')
        ->and($failedPost->status)->toBe(SocialPost::STATUS_FAILED)
        ->and($failedPost->failed_at)->not->toBeNull()
        ->and((string) $failedPost->failure_reason)->toContain('Facebook queue failure callback.')
        ->and($connection->fresh()->last_error)->toBe('Facebook queue failure callback.');
});

it('classifies HTTP 429 publication responses as retryable failures', function () {
    config()->set('services.social.linkedin.publish.fake', false);
    config()->set('services.social.linkedin.publish.url', 'https://linkedin.test/v2/posts');
    Http::preventStrayRequests();
    Http::fake([
        'https://linkedin.test/v2/posts' => Http::response([
            'error' => [
                'message' => 'LinkedIn rate limit reached.',
            ],
        ], 429),
    ]);

    $owner = pulsePublishingOwner();
    $connection = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_LINKEDIN);
    $publisher = app(LinkedInPagePlatformPublisher::class);

    expect(fn () => $publisher->publish($connection, [
        'text' => 'Pulse production transport contract',
    ]))->toThrow(RetryableSocialPublishingException::class, 'LinkedIn rate limit reached.');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://linkedin.test/v2/posts'
        && $request->hasHeader('Authorization', 'Bearer token-linkedin')
        && data_get($request->data(), 'target_id') === $connection->external_account_id);
});

it('refuses to publish a target through a connection owned by another pulse tenant', function () {
    $publishers = pulsePublishingBindRegistry();

    $owner = pulsePublishingOwner();
    $foreignOwner = pulsePublishingOwner();
    $foreignConnection = pulsePublishingConnection(
        $foreignOwner,
        SocialAccountConnection::PLATFORM_LINKEDIN
    );
    $draft = pulsePublishingDraft($owner, $owner, [$foreignConnection]);
    $target = $draft->targets->sole();

    app(SocialPublishingService::class)->handleTargetPublication($target->id);

    $failedTarget = $target->fresh();
    $failedPost = $draft->fresh();

    expect($publishers[SocialAccountConnection::PLATFORM_LINKEDIN]->publishCalls)->toBe(0)
        ->and($failedTarget->status)->toBe(SocialPostTarget::STATUS_FAILED)
        ->and(trim((string) $failedTarget->failure_reason))->not->toBe('')
        ->and($failedPost->status)->toBe(SocialPost::STATUS_FAILED)
        ->and(trim((string) $failedPost->failure_reason))->not->toBe('')
        ->and($foreignConnection->fresh()->last_error)->toBeNull();
});

it('rejects a cross-tenant target before dispatch and leaves the foreign connection untouched', function () {
    $owner = pulsePublishingOwner();
    $foreignOwner = pulsePublishingOwner();
    $foreignConnection = pulsePublishingConnection(
        $foreignOwner,
        SocialAccountConnection::PLATFORM_LINKEDIN
    );
    $draft = pulsePublishingDraft($owner, $owner, [$foreignConnection]);
    $target = $draft->targets->sole();
    $foreignConnectionBeforeQueue = $foreignConnection->fresh()->getAttributes();
    Queue::fake();

    $this->actingAs($owner)
        ->postJson(route('social.posts.publish', $draft))
        ->assertStatus(202)
        ->assertJsonPath('draft.status', SocialPost::STATUS_FAILED)
        ->assertJsonPath('draft.targets.0.status', SocialPostTarget::STATUS_FAILED);

    Queue::assertNothingPushed();

    $failedTarget = $target->fresh();
    $failedPost = $draft->fresh();

    expect($failedTarget->status)->toBe(SocialPostTarget::STATUS_FAILED)
        ->and($failedTarget->failed_at)->not->toBeNull()
        ->and($failedTarget->failure_reason)->toBe('This Pulse target is not valid for this workspace.')
        ->and(data_get($failedTarget->metadata, 'dispatch_mode'))->toBeNull()
        ->and($failedPost->status)->toBe(SocialPost::STATUS_FAILED)
        ->and($failedPost->failed_at)->not->toBeNull()
        ->and((int) data_get($failedPost->metadata, 'queued_targets_count'))->toBe(0)
        ->and($foreignConnection->fresh()->getAttributes())->toBe($foreignConnectionBeforeQueue);
});

it('configures pulse publication jobs for after commit dispatch bounded execution and per target overlap protection', function () {
    $job = new PublishSocialPostTargetJob(101);
    $sameTargetJob = new PublishSocialPostTargetJob(101);
    $otherTargetJob = new PublishSocialPostTargetJob(202);

    expect($job)->toBeInstanceOf(ShouldQueueAfterCommit::class)
        ->and($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([30, 120, 300])
        ->and($job->timeout)->toBe(60)
        ->and(QueueWorkload::timeout('social_publish'))->toBe(60)
        ->and($job->failOnTimeout)->toBeTrue();

    expect($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'));

    $middleware = collect($job->middleware())
        ->first(fn (object $item): bool => $item instanceof WithoutOverlapping);
    $sameTargetMiddleware = collect($sameTargetJob->middleware())
        ->first(fn (object $item): bool => $item instanceof WithoutOverlapping);
    $otherTargetMiddleware = collect($otherTargetJob->middleware())
        ->first(fn (object $item): bool => $item instanceof WithoutOverlapping);

    expect($middleware)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($sameTargetMiddleware)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($otherTargetMiddleware)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware->key)->toBe('social-post-target:101')
        ->and($middleware->key)->toBe($sameTargetMiddleware->key)
        ->and($middleware->key)->not->toBe($otherTargetMiddleware->key)
        ->and($middleware->releaseAfter)->toBeNull()
        ->and($middleware->expiresAfter)->toBe(120)
        ->and($middleware->shareKey)->toBeTrue();
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
        'scheduled_for' => Carbon::now()->addDays(2)->setTime(16, 45),
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
        'scheduled_for' => Carbon::now()->addDays(2)->setTime(9, 15),
    ]);

    $this->actingAs($owner)
        ->postJson(route('social.posts.publish', $draft))
        ->assertForbidden();

    $this->actingAs($owner)
        ->postJson(route('social.posts.schedule', $draft))
        ->assertForbidden();
});
