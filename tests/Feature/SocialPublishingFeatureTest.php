<?php

use App\Exceptions\Social\DefinitiveSocialPublishingRejectionException;
use App\Exceptions\Social\RetryableSocialPublishingException;
use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Jobs\ProcessSocialDeliveryOutboxJob;
use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\SocialApprovalRequest;
use App\Models\SocialDeliveryOutbox;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\SocialPublicationCompletedNotification;
use App\Services\Social\Contracts\PlatformPublisherInterface;
use App\Services\Social\Providers\LinkedInPagePlatformPublisher;
use App\Services\Social\SocialDeliveryOutboxService;
use App\Services\Social\SocialPostRevisionService;
use App\Services\Social\SocialPostService;
use App\Services\Social\SocialProviderRegistry;
use App\Services\Social\SocialPublishingService;
use App\Support\QueueWorkload;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

class PulsePublishingFakePublisher implements PlatformPublisherInterface
{
    public int $publishCalls = 0;

    /** @var array<int, array<string, mixed>> */
    public array $publishPayloads = [];

    /**
     * @param  array<int, string>  $failingPlatforms
     * @param  array<int, string>  $retryablePlatforms
     * @param  array<int, string>  $ambiguousPlatforms
     * @param  array<string, mixed>  $resultOverrides
     */
    public function __construct(
        private readonly string $platform,
        private readonly array $failingPlatforms = [],
        private readonly array $retryablePlatforms = [],
        private readonly array $ambiguousPlatforms = [],
        private readonly array $resultOverrides = [],
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
        $this->publishPayloads[] = $payload;

        if (in_array($this->platform, $this->retryablePlatforms, true)) {
            throw RetryableSocialPublishingException::provenSafeForCreateRetry(sprintf(
                '%s temporary transport failure.',
                Str::headline($this->platform)
            ));
        }

        if (in_array($this->platform, $this->ambiguousPlatforms, true)) {
            throw new ConnectionException(sprintf(
                '%s timed out after the request started.',
                Str::headline($this->platform)
            ));
        }

        if (in_array($this->platform, $this->failingPlatforms, true)) {
            throw new DefinitiveSocialPublishingRejectionException(sprintf(
                '%s rejected this Pulse publication without creating it.',
                Str::headline($this->platform),
            ));
        }

        return array_replace([
            'provider_post_id' => sprintf('%s-post-%d', $this->platform, $connection->id),
            'published_at' => now()->toIso8601String(),
            'metadata' => [
                'transport' => 'fake-test',
                'platform' => $this->platform,
                'text_preview' => Str::limit((string) ($payload['text'] ?? ''), 80),
            ],
            'message' => sprintf('%s published.', Str::headline($this->platform)),
        ], $this->resultOverrides);
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
    $attributes = array_merge([
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
    ], $overrides);

    return SocialAccountConnection::query()->create([
        ...$attributes,
        ...pulseDirectTransportIdentity($owner, $platform, (string) $attributes['external_account_id']),
    ]);
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
            'delivery_provider' => $connection->delivery_provider,
            'transport_generation' => $connection->transport_generation,
            'logical_destination_key' => $connection->logical_destination_key,
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
 * @param  array<int, string>  $ambiguousPlatforms
 * @param  array<string, array<string, mixed>>  $resultOverridesByPlatform
 * @return array<string, PulsePublishingFakePublisher>
 */
function pulsePublishingBindRegistry(
    array $failingPlatforms = [],
    array $retryablePlatforms = [],
    array $ambiguousPlatforms = [],
    array $resultOverridesByPlatform = [],
): array {
    $publishers = [];

    foreach (SocialAccountConnection::allowedPlatforms() as $platform) {
        $publishers[$platform] = new PulsePublishingFakePublisher(
            $platform,
            $failingPlatforms,
            $retryablePlatforms,
            $ambiguousPlatforms,
            $resultOverridesByPlatform[$platform] ?? [],
        );
    }

    app()->instance(SocialProviderRegistry::class, new PulsePublishingFakeRegistry($publishers));

    return $publishers;
}

function pulsePublishingProcessTargetOutbox(SocialPostTarget $target): SocialDeliveryOutbox
{
    $target->refresh();
    $outbox = SocialDeliveryOutbox::query()
        ->where('social_post_target_id', $target->id)
        ->where('social_post_revision_id', $target->last_submitted_revision_id)
        ->where('operation', SocialDeliveryOutbox::OPERATION_CREATE)
        ->orderByDesc('recovery_generation')
        ->sole();

    app(SocialPublishingService::class)->handleOutboxPublication($outbox->id);

    return $outbox->fresh();
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

it('queues immediate pulse publication and marks all targets as published after workers run', function () {
    Notification::fake();
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

    Queue::assertPushed(ProcessSocialDeliveryOutboxJob::class, 2);

    $this->actingAs($owner)
        ->postJson(route('social.posts.retry', $draft))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('post')
        ->assertJsonPath(
            'errors.post.0',
            'Only a failed or partially failed Pulse publication can be retried.',
        );

    Queue::assertPushed(ProcessSocialDeliveryOutboxJob::class, 2);

    $targets = SocialPostTarget::query()
        ->where('social_post_id', $draft->id)
        ->orderBy('id')
        ->get();
    $outboxes = SocialDeliveryOutbox::query()
        ->where('user_id', $owner->id)
        ->whereIn('social_post_target_id', $targets->modelKeys())
        ->orderBy('id')
        ->get();

    expect($outboxes)->toHaveCount(2)
        ->and($outboxes->pluck('social_post_target_id')->all())->toBe($targets->modelKeys())
        ->and($outboxes->every(
            fn (SocialDeliveryOutbox $outbox): bool => $outbox->social_post_revision_id > 0
                && $outbox->operation === SocialDeliveryOutbox::OPERATION_CREATE
                && $outbox->status === SocialDeliveryOutbox::STATUS_PENDING
        ))->toBeTrue();

    Queue::assertPushed(
        ProcessSocialDeliveryOutboxJob::class,
        fn (ProcessSocialDeliveryOutboxJob $job): bool => $outboxes->contains('id', $job->outboxId),
    );

    foreach ($targets as $target) {
        pulsePublishingProcessTargetOutbox($target);
    }

    Notification::assertSentToTimes($owner, SocialPublicationCompletedNotification::class, 1);
    Notification::assertSentTo($owner, SocialPublicationCompletedNotification::class,
        fn ($notification): bool => $notification->snapshot['outcome'] === 'success'
            && $notification->snapshot['counts']['published'] === 2);

    $freshPost = SocialPost::query()->with('targets.socialAccountConnection')->findOrFail($draft->id);

    expect($freshPost->status)->toBe(SocialPost::STATUS_PUBLISHED)
        ->and($freshPost->published_at)->not->toBeNull()
        ->and($freshPost->targets)->toHaveCount(2)
        ->and($freshPost->targets->every(
            fn (SocialPostTarget $target): bool => $target->status === SocialPostTarget::STATUS_PUBLISHED
                && filled($target->provider_post_id)
                && $target->submitted_at !== null
                && $target->last_synced_at !== null
                && $target->next_reconcile_at === null,
        ))->toBeTrue();

    $this->actingAs($owner)
        ->postJson(route('social.posts.retry', $draft))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('post');

    Queue::assertPushed(ProcessSocialDeliveryOutboxJob::class, 2);
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

    Queue::assertPushed(ProcessSocialDeliveryOutboxJob::class, function (ProcessSocialDeliveryOutboxJob $job) use ($draft, $scheduledFor) {
        $target = SocialPostTarget::query()
            ->where('social_post_id', $draft->id)
            ->first();
        $outbox = SocialDeliveryOutbox::query()->find($job->outboxId);

        return $target
            && $outbox
            && $outbox->social_post_target_id === $target->id
            && $outbox->social_post_revision_id === (int) $target->last_submitted_revision_id
            && $outbox->user_id === (int) $draft->user_id
            && $outbox->status === SocialDeliveryOutbox::STATUS_PENDING
            && $outbox->available_at?->equalTo($scheduledFor)
            && $job->delay instanceof Carbon
            && $job->delay->equalTo($scheduledFor);
    });

    $freshPost = SocialPost::query()->with('targets')->findOrFail($draft->id);

    expect($freshPost->status)->toBe(SocialPost::STATUS_SCHEDULED)
        ->and($freshPost->scheduled_for?->equalTo($scheduledFor))->toBeTrue()
        ->and($freshPost->targets->every(fn (SocialPostTarget $target) => $target->status === SocialPostTarget::STATUS_SCHEDULED))->toBeTrue();
});

it('publishes the submitted immutable revision after a delayed post is edited and remains idempotent', function () {
    Queue::fake();
    $publishers = pulsePublishingBindRegistry();

    $owner = pulsePublishingOwner();
    $connection = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $scheduledFor = Carbon::now()->addDays(2)->setTime(14, 30);
    $draft = pulsePublishingDraft($owner, $owner, [$connection], [
        'text' => 'Contenu approuvé original',
        'image_url' => 'https://example.com/assets/original.jpg',
        'link_url' => 'https://example.com/offers/original',
        'scheduled_for' => $scheduledFor,
        'metadata' => [
            'link_cta_label' => 'Réserver maintenant',
            'selected_target_count' => 1,
        ],
    ]);

    $this->actingAs($owner)
        ->postJson(route('social.posts.schedule', $draft))
        ->assertStatus(202);

    $capturedJob = null;
    Queue::assertPushed(
        ProcessSocialDeliveryOutboxJob::class,
        function (ProcessSocialDeliveryOutboxJob $job) use (&$capturedJob): bool {
            $capturedJob = $job;

            return true;
        }
    );
    expect($capturedJob)->toBeInstanceOf(ProcessSocialDeliveryOutboxJob::class);

    $target = $draft->targets()->sole();
    $submittedRevisionId = (int) $target->last_submitted_revision_id;
    $submittedOutbox = SocialDeliveryOutbox::query()->findOrFail($capturedJob->outboxId);
    $editedPost = $draft->fresh();
    $editedPost->forceFill([
        'content_payload' => ['text' => 'Contenu modifié après programmation'],
        'media_payload' => [[
            'type' => 'image',
            'url' => 'https://example.com/assets/edited.jpg',
        ]],
        'link_url' => 'https://example.com/offers/edited',
        'scheduled_for' => $scheduledFor->copy()->addDay(),
        'metadata' => array_merge((array) $editedPost->metadata, [
            'link_cta_label' => 'Acheter maintenant',
        ]),
    ])->save();
    $currentRevision = app(SocialPostRevisionService::class)->ensureCurrent($editedPost->fresh(), $owner);

    $this->travelTo($scheduledFor->copy()->addMinute());
    $capturedJob->handle(app(SocialPublishingService::class));

    $publisher = $publishers[SocialAccountConnection::PLATFORM_FACEBOOK];
    $payload = $publisher->publishPayloads[0] ?? [];
    $publishedTarget = $target->fresh();

    expect($submittedOutbox->social_post_target_id)->toBe($target->id)
        ->and($submittedOutbox->social_post_revision_id)->toBe($submittedRevisionId)
        ->and($submittedOutbox->editorial_revision)->toBeGreaterThan(0)
        ->and($currentRevision->id)->not->toBe($submittedRevisionId)
        ->and($publishedTarget->current_revision_id)->toBe($currentRevision->id)
        ->and($publishedTarget->last_submitted_revision_id)->toBe($submittedRevisionId)
        ->and($publishedTarget->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_PUBLISHED)
        ->and($payload['text'] ?? null)->toBe('Contenu approuvé original')
        ->and($payload['image_url'] ?? null)->toBe('https://example.com/assets/original.jpg')
        ->and($payload['link_url'] ?? null)->toBe('https://example.com/offers/original')
        ->and(data_get($payload, 'metadata.link_cta_label'))->toBe('Réserver maintenant')
        ->and($payload['scheduled_for'] ?? null)->toBe($scheduledFor->copy()->utc()->toIso8601String())
        ->and($publisher->publishCalls)->toBe(1);

    DB::table('social_post_targets')
        ->where('id', $target->id)
        ->update(['last_submitted_revision_id' => null]);

    $capturedJob->handle(app(SocialPublishingService::class));

    expect($publisher->publishCalls)->toBe(1)
        ->and($target->fresh()->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_PUBLISHED);
});

it('quarantines invalid submitted revisions without calling any publisher', function (string $corruption) {
    Queue::fake();
    $publishers = pulsePublishingBindRegistry();

    $owner = pulsePublishingOwner();
    $connection = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $queuedPost = app(SocialPublishingService::class)->publishNow(
        $owner,
        $owner,
        pulsePublishingDraft($owner, $owner, [$connection]),
    );
    $target = $queuedPost->targets->sole();
    $revisionId = (int) $target->last_submitted_revision_id;

    match ($corruption) {
        'unapproved revision' => DB::table('social_post_revisions')
            ->where('id', $revisionId)
            ->update([
                'approved_by_user_id' => null,
                'approved_at' => null,
                'approval_provenance' => null,
            ]),
        'cross tenant revision' => DB::table('social_post_revisions')
            ->where('id', $revisionId)
            ->update(['user_id' => pulsePublishingOwner()->id]),
        'forged revision payload hash' => DB::transaction(function () use (
            $queuedPost,
            $revisionId,
            $target,
        ): void {
            $forgedHash = str_repeat('f', 64);
            DB::table('social_post_revisions')->where('id', $revisionId)->update([
                'payload_hash' => $forgedHash,
            ]);
            DB::table('social_post_targets')->where('id', $target->id)->update([
                'payload_hash' => $forgedHash,
            ]);
            DB::table('social_posts')->where('id', $queuedPost->id)->update([
                'payload_hash' => $forgedHash,
            ]);
        }),
    };

    pulsePublishingProcessTargetOutbox($target);

    expect($publishers[SocialAccountConnection::PLATFORM_FACEBOOK]->publishCalls)->toBe(0)
        ->and($target->fresh()->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN)
        ->and($target->fresh()->sync_status)->toBe(SocialPost::SYNC_STATUS_ERROR)
        ->and($queuedPost->fresh()->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN)
        ->and(data_get($target->fresh()->metadata, 'delivery_integrity_error'))->not->toBeNull();
})->with([
    'unapproved revision',
    'cross tenant revision',
    'forged revision payload hash',
]);

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

    Queue::assertPushed(ProcessSocialDeliveryOutboxJob::class, 1);

    expect($draft->fresh()->getAttributes())->toBe($queuedPostAttributes)
        ->and($queuedTarget->fresh()->getAttributes())->toBe($queuedTargetAttributes);
});

it('returns 422 without mutations when a pending approval is published or scheduled directly', function (
    string $actorType,
    string $routeName
) {
    Queue::fake([ProcessSocialDeliveryOutboxJob::class]);

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
    Queue::fake();
    $publishers = pulsePublishingBindRegistry();

    $owner = pulsePublishingOwner();
    $connection = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_LINKEDIN);
    $draft = pulsePublishingDraft($owner, $owner, [$connection]);
    $queuedPost = app(SocialPublishingService::class)->publishNow($owner, $owner, $draft);
    $target = $queuedPost->targets->sole();
    $approvalRequest = $queuedPost->approvalRequests()->create([
        'requested_by_user_id' => $owner->id,
        'status' => SocialApprovalRequest::STATUS_PENDING,
        'requested_at' => now(),
        'metadata' => [
            'requested_mode' => 'immediate',
        ],
    ]);
    $queuedPost->forceFill([
        'status' => SocialPost::STATUS_PENDING_APPROVAL,
    ])->save();
    $postBeforePublication = $queuedPost->fresh()->getAttributes();
    $targetBeforePublication = $target->getAttributes();
    $approvalRequestBeforePublication = $approvalRequest->fresh()->getAttributes();

    $outbox = pulsePublishingProcessTargetOutbox($target);

    expect($publishers[SocialAccountConnection::PLATFORM_LINKEDIN]->publishCalls)->toBe(0)
        ->and($queuedPost->fresh()->getAttributes())->toBe($postBeforePublication)
        ->and($target->fresh()->getAttributes())->toBe($targetBeforePublication)
        ->and($approvalRequest->fresh()->getAttributes())->toBe($approvalRequestBeforePublication)
        ->and($outbox->status)->toBe(SocialDeliveryOutbox::STATUS_DEAD)
        ->and($outbox->last_error_code)->toBe('local_decision_is_terminal');
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

    $targets = SocialPostTarget::query()
        ->where('social_post_id', $draft->id)
        ->orderBy('id')
        ->get();

    foreach ($targets as $target) {
        pulsePublishingProcessTargetOutbox($target);
    }

    $freshPost = SocialPost::query()->with('targets.socialAccountConnection')->findOrFail($draft->id);
    $linkedinTarget = $freshPost->targets->firstWhere('socialAccountConnection.platform', SocialAccountConnection::PLATFORM_LINKEDIN);
    $facebookTarget = $freshPost->targets->firstWhere('socialAccountConnection.platform', SocialAccountConnection::PLATFORM_FACEBOOK);

    expect($freshPost->status)->toBe(SocialPost::STATUS_PARTIAL_FAILED)
        ->and($freshPost->failure_reason)->toContain('rejected this Pulse publication')
        ->and($facebookTarget?->status)->toBe(SocialPostTarget::STATUS_PUBLISHED)
        ->and($linkedinTarget?->status)->toBe(SocialPostTarget::STATUS_FAILED)
        ->and((string) $linkedinTarget?->failure_reason)->toContain('rejected this Pulse publication');

    $this->actingAs($owner)
        ->getJson(route('social.calendar'))
        ->assertOk()
        ->assertJsonPath('calendar_posts.0.can_retry', true);

    $viewer = pulsePublishingTeamMember($owner, ['social.view']);
    $this->actingAs($viewer)
        ->getJson(route('social.calendar'))
        ->assertOk()
        ->assertJsonPath('calendar_posts.0.can_retry', false);

    $publishedTargetAttributes = $facebookTarget?->fresh()->getAttributes();
    $failedOutbox = SocialDeliveryOutbox::query()
        ->where('social_post_target_id', $linkedinTarget?->id)
        ->where('status', SocialDeliveryOutbox::STATUS_DEAD)
        ->sole();

    pulsePublishingBindRegistry();

    $this->actingAs($owner)
        ->postJson(route('social.posts.retry', $draft))
        ->assertStatus(202)
        ->assertJsonPath('draft.status', SocialPost::STATUS_PUBLISHING)
        ->assertJsonPath('draft.can_retry', false)
        ->assertJsonPath('calendar_posts.0.can_retry', false)
        ->assertJsonPath('posts.0.can_retry', false);

    Queue::assertPushed(ProcessSocialDeliveryOutboxJob::class, 3);

    $retryOutbox = SocialDeliveryOutbox::query()
        ->where('social_post_target_id', $linkedinTarget?->id)
        ->orderByDesc('recovery_generation')
        ->firstOrFail();

    expect($retryOutbox->recovery_generation)->toBe(1)
        ->and($retryOutbox->supersedes_outbox_id)->toBe($failedOutbox->id)
        ->and($facebookTarget?->fresh()->getAttributes())->toBe($publishedTargetAttributes)
        ->and(SocialDeliveryOutbox::query()
            ->where('social_post_target_id', $facebookTarget?->id)
            ->count())->toBe(1);

    $this->actingAs($owner)
        ->postJson(route('social.posts.retry', $draft))
        ->assertStatus(202)
        ->assertJsonPath('draft.status', SocialPost::STATUS_PUBLISHING);

    Queue::assertPushed(ProcessSocialDeliveryOutboxJob::class, 3);
    expect(SocialDeliveryOutbox::query()
        ->where('social_post_target_id', $linkedinTarget?->id)
        ->count())->toBe(2);

    app(SocialPublishingService::class)->handleOutboxPublication($retryOutbox->id);

    expect($draft->fresh()->status)->toBe(SocialPost::STATUS_PUBLISHED)
        ->and(app(SocialPostService::class)->payload($draft->fresh(), true)['can_retry'])->toBeFalse();
});

it('hides retry when the failed target outbox is not safely recoverable', function (string $outboxStatus) {
    Queue::fake();
    pulsePublishingBindRegistry();

    $owner = pulsePublishingOwner();
    $connection = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $draft = pulsePublishingDraft($owner, $owner, [$connection]);

    $this->actingAs($owner)
        ->postJson(route('social.posts.publish', $draft))
        ->assertStatus(202);

    $target = $draft->fresh('targets')->targets->sole();
    $outbox = SocialDeliveryOutbox::query()
        ->where('social_post_target_id', $target->id)
        ->sole();
    $observedAt = now();

    $outbox->forceFill([
        'status' => $outboxStatus,
        'attempts' => 1,
        'request_started_at' => $observedAt->copy()->subMinute(),
        'submitted_at' => $observedAt->copy()->subMinute(),
        'processed_at' => $observedAt,
        'provider_post_id' => 'buffer-definitive-error-'.$outbox->id,
        'reconciliation_resolved_at' => $observedAt,
        'reconciliation_observed_at' => $observedAt,
        'reconciliation_resolution' => SocialDeliveryOutbox::RECONCILIATION_RESOLUTION_ERROR,
        'reconciliation_resolution_source' => SocialDeliveryOutbox::RECONCILIATION_SOURCE_STATUS_READ,
    ])->save();
    $newerNonCreateOutbox = SocialDeliveryOutbox::query()->create([
        'user_id' => $outbox->user_id,
        'social_post_target_id' => $outbox->social_post_target_id,
        'social_post_revision_id' => $outbox->social_post_revision_id,
        'social_provider_connection_id' => $outbox->social_provider_connection_id,
        'operation' => SocialDeliveryOutbox::OPERATION_UPDATE,
        'delivery_provider' => $outbox->delivery_provider,
        'transport_generation' => $outbox->transport_generation,
        'logical_destination_key' => $outbox->logical_destination_key,
        'external_organization_id_snapshot' => $outbox->external_organization_id_snapshot,
        'external_channel_id_snapshot' => $outbox->external_channel_id_snapshot,
        'editorial_revision' => $outbox->editorial_revision,
        'recovery_generation' => 0,
        'idempotency_key' => hash('sha256', 'newer-update-'.$outbox->id),
        'correlation_key' => hash('sha256', 'newer-update-correlation-'.$outbox->id),
        'payload_hash' => $outbox->payload_hash,
        'payload' => $outbox->payload,
        'status' => SocialDeliveryOutbox::STATUS_DEAD,
        'attempts' => 0,
        'available_at' => $observedAt,
        'processed_at' => $observedAt,
        'last_error_category' => 'validation',
        'last_error_code' => 'update_failed_without_effect',
        'last_error_message' => 'A later non-create operation failed.',
    ]);
    $target->fresh()->forceFill([
        'status' => SocialPostTarget::STATUS_FAILED,
        'delivery_status' => SocialPost::DELIVERY_STATUS_FAILED,
        'sync_status' => SocialPost::SYNC_STATUS_ERROR,
        'provider_post_id' => 'buffer-definitive-error-'.$outbox->id,
        'failed_at' => $observedAt,
        'failure_reason' => 'The remote delivery failed definitively.',
    ])->save();
    $draft->fresh()->forceFill([
        'status' => SocialPost::STATUS_FAILED,
        'delivery_status' => SocialPost::DELIVERY_STATUS_FAILED,
        'sync_status' => SocialPost::SYNC_STATUS_ERROR,
        'failed_at' => $observedAt,
        'failure_reason' => 'The remote delivery failed definitively.',
    ])->save();

    expect($newerNonCreateOutbox->id)->toBeGreaterThan($outbox->id)
        ->and(app(SocialPostService::class)->payload($draft->fresh(), true)['can_retry'])->toBeFalse();

    $this->actingAs($owner)
        ->postJson(route('social.posts.retry', $draft))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('post')
        ->assertJsonPath(
            'errors.post.0',
            'This Pulse publication has no failed target that can be retried safely.',
        );

    Queue::assertPushed(ProcessSocialDeliveryOutboxJob::class, 1);
    expect(SocialDeliveryOutbox::query()->count())->toBe(2)
        ->and(SocialDeliveryOutbox::query()
            ->where('operation', SocialDeliveryOutbox::OPERATION_CREATE)
            ->count())->toBe(1);
})->with([
    'completed then reconciled as remote error' => SocialDeliveryOutbox::STATUS_COMPLETED,
    'unknown then resolved as remote error' => SocialDeliveryOutbox::STATUS_UNKNOWN,
]);

it('retries only recoverable failed targets when another failed target is unsafe', function () {
    Queue::fake();
    pulsePublishingBindRegistry();

    $owner = pulsePublishingOwner();
    $facebook = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $linkedin = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_LINKEDIN);
    $draft = pulsePublishingDraft($owner, $owner, [$facebook, $linkedin]);

    $this->actingAs($owner)
        ->postJson(route('social.posts.publish', $draft))
        ->assertStatus(202);

    $targets = $draft->fresh('targets')->targets->sortBy('id')->values();
    $recoverableTarget = $targets->firstOrFail();
    $unsafeTarget = $targets->last();
    $recoverableOutbox = SocialDeliveryOutbox::query()
        ->where('social_post_target_id', $recoverableTarget->id)
        ->sole();
    $unsafeOutbox = SocialDeliveryOutbox::query()
        ->where('social_post_target_id', $unsafeTarget->id)
        ->sole();
    $observedAt = now();

    $recoverableOutbox->forceFill([
        'status' => SocialDeliveryOutbox::STATUS_DEAD,
        'attempts' => 1,
        'processed_at' => $observedAt,
        'last_error_category' => 'validation',
        'last_error_code' => 'provider_rejected_without_effect',
        'last_error_message' => 'The provider rejected this delivery.',
    ])->save();
    $unsafeOutbox->forceFill([
        'status' => SocialDeliveryOutbox::STATUS_COMPLETED,
        'attempts' => 1,
        'request_started_at' => $observedAt->copy()->subMinute(),
        'submitted_at' => $observedAt->copy()->subMinute(),
        'processed_at' => $observedAt,
        'provider_post_id' => 'buffer-unsafe-'.$unsafeOutbox->id,
        'reconciliation_resolved_at' => $observedAt,
        'reconciliation_observed_at' => $observedAt,
        'reconciliation_resolution' => SocialDeliveryOutbox::RECONCILIATION_RESOLUTION_ERROR,
        'reconciliation_resolution_source' => SocialDeliveryOutbox::RECONCILIATION_SOURCE_STATUS_READ,
    ])->save();

    foreach ($targets as $target) {
        $target->forceFill([
            'status' => SocialPostTarget::STATUS_FAILED,
            'delivery_status' => SocialPost::DELIVERY_STATUS_FAILED,
            'sync_status' => SocialPost::SYNC_STATUS_ERROR,
            'failed_at' => $observedAt,
            'failure_reason' => 'The target failed.',
            ...((int) $target->id === (int) $unsafeTarget->id ? [
                'provider_post_id' => 'buffer-unsafe-'.$unsafeOutbox->id,
            ] : []),
        ])->save();
    }
    $draft->fresh()->forceFill([
        'status' => SocialPost::STATUS_FAILED,
        'delivery_status' => SocialPost::DELIVERY_STATUS_FAILED,
        'sync_status' => SocialPost::SYNC_STATUS_ERROR,
        'failed_at' => $observedAt,
        'failure_reason' => 'Both targets failed.',
    ])->save();

    expect(app(SocialPostService::class)->payload($draft->fresh(), true)['can_retry'])->toBeTrue();

    $this->actingAs($owner)
        ->postJson(route('social.posts.retry', $draft))
        ->assertStatus(202)
        ->assertJsonPath('draft.can_retry', false);

    $retryOutbox = SocialDeliveryOutbox::query()
        ->where('social_post_target_id', $recoverableTarget->id)
        ->orderByDesc('recovery_generation')
        ->firstOrFail();

    expect($retryOutbox->recovery_generation)->toBe(1)
        ->and($retryOutbox->supersedes_outbox_id)->toBe($recoverableOutbox->id)
        ->and(SocialDeliveryOutbox::query()
            ->where('social_post_target_id', $unsafeTarget->id)
            ->count())->toBe(1);

    $this->actingAs($owner)
        ->postJson(route('social.posts.retry', $draft))
        ->assertStatus(202);

    Queue::assertPushed(ProcessSocialDeliveryOutboxJob::class, 3);
    expect(SocialDeliveryOutbox::query()->count())->toBe(3);
});

it('loads retry eligibility for the Pulse calendar without an outbox query per post', function () {
    $owner = pulsePublishingOwner();
    $connection = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);

    foreach (range(1, 5) as $index) {
        $post = pulsePublishingDraft($owner, $owner, [$connection], [
            'text' => 'Failed publication '.$index,
        ]);
        $post->forceFill([
            'status' => SocialPost::STATUS_FAILED,
        ])->save();
        $post->targets()->update([
            'status' => SocialPostTarget::STATUS_FAILED,
        ]);
    }

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = $this->actingAs($owner)->getJson(route('social.calendar'));
    $outboxQueries = collect($queries)
        ->filter(fn (string $sql): bool => str_contains($sql, 'social_delivery_outbox'));

    $response->assertOk()
        ->assertJsonCount(5, 'calendar_posts')
        ->assertJsonPath('calendar_posts.0.can_retry', true);
    expect($outboxQueries)->toHaveCount(1);
});

it('fails closed to unknown when any provider-neutral target delivery is ambiguous', function () {
    Queue::fake();
    $publishers = pulsePublishingBindRegistry();

    $owner = pulsePublishingOwner();
    $facebook = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $linkedin = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_LINKEDIN);
    $queuedPost = app(SocialPublishingService::class)->publishNow(
        $owner,
        $owner,
        pulsePublishingDraft($owner, $owner, [$facebook, $linkedin]),
    );
    [$ambiguousTarget, $publishedTarget] = $queuedPost->targets->values()->all();

    $ambiguousTarget->forceFill([
        'status' => SocialPostTarget::STATUS_FAILED,
        'delivery_status' => SocialPost::DELIVERY_STATUS_UNKNOWN,
        'sync_status' => SocialPost::SYNC_STATUS_PENDING,
    ])->save();
    $publishedTarget->forceFill([
        'status' => SocialPostTarget::STATUS_PUBLISHED,
        'delivery_status' => SocialPost::DELIVERY_STATUS_PUBLISHED,
        'sync_status' => SocialPost::SYNC_STATUS_SYNCED,
        'published_at' => now(),
    ])->save();

    pulsePublishingProcessTargetOutbox($ambiguousTarget);

    $aggregatedPost = $queuedPost->fresh();

    expect($aggregatedPost->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN)
        ->and($aggregatedPost->sync_status)->toBe(SocialPost::SYNC_STATUS_PENDING)
        ->and($aggregatedPost->delivery_status_source)->toBe(SocialPost::STATUS_SOURCE_DERIVED)
        ->and($aggregatedPost->sync_status_source)->toBe(SocialPost::STATUS_SOURCE_DERIVED)
        ->and($aggregatedPost->status)->toBe(SocialPost::STATUS_FAILED)
        ->and($publishers[SocialAccountConnection::PLATFORM_FACEBOOK]->publishCalls)->toBe(0)
        ->and($publishers[SocialAccountConnection::PLATFORM_LINKEDIN]->publishCalls)->toBe(0);

    $this->actingAs($owner)
        ->postJson(route('social.posts.retry', $queuedPost))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('post')
        ->assertJsonPath(
            'errors.post.0',
            'This Pulse post has an ambiguous delivery outcome and must be reconciled before any retry.',
        );

    expect(app(SocialPostService::class)->payload($aggregatedPost, true)['can_retry'])->toBeFalse();
});

it('derives partial failed delivery and error sync independently of providers', function () {
    Queue::fake();
    pulsePublishingBindRegistry();

    $owner = pulsePublishingOwner();
    $facebook = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $linkedin = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_LINKEDIN);
    $queuedPost = app(SocialPublishingService::class)->publishNow(
        $owner,
        $owner,
        pulsePublishingDraft($owner, $owner, [$facebook, $linkedin]),
    );
    [$failedTarget, $publishedTarget] = $queuedPost->targets->values()->all();

    $failedTarget->forceFill([
        'status' => SocialPostTarget::STATUS_FAILED,
        'delivery_status' => SocialPost::DELIVERY_STATUS_FAILED,
        'sync_status' => SocialPost::SYNC_STATUS_ERROR,
        'failed_at' => now(),
        'failure_reason' => 'Provider-neutral failure.',
    ])->save();
    $publishedTarget->forceFill([
        'status' => SocialPostTarget::STATUS_PUBLISHED,
        'delivery_status' => SocialPost::DELIVERY_STATUS_PUBLISHED,
        'sync_status' => SocialPost::SYNC_STATUS_SYNCED,
        'published_at' => now(),
    ])->save();

    pulsePublishingProcessTargetOutbox($failedTarget);

    $aggregatedPost = $queuedPost->fresh();

    expect($aggregatedPost->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_PARTIAL_FAILED)
        ->and($aggregatedPost->sync_status)->toBe(SocialPost::SYNC_STATUS_ERROR)
        ->and($aggregatedPost->status)->toBe(SocialPost::STATUS_PARTIAL_FAILED)
        ->and($aggregatedPost->failure_reason)->toBe('Provider-neutral failure.')
        ->and($aggregatedPost->failed_at)->not->toBeNull()
        ->and($aggregatedPost->published_at)->not->toBeNull();
});

it('excludes canceled targets from delivery success and keeps cancellation distinct from failure', function () {
    Queue::fake();
    pulsePublishingBindRegistry();

    $owner = pulsePublishingOwner();
    $facebook = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $linkedin = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_LINKEDIN);
    $queuedPost = app(SocialPublishingService::class)->publishNow(
        $owner,
        $owner,
        pulsePublishingDraft($owner, $owner, [$facebook, $linkedin]),
    );
    [$publishedTarget, $canceledTarget] = $queuedPost->targets->values()->all();

    $publishedTarget->forceFill([
        'status' => SocialPostTarget::STATUS_PUBLISHED,
        'delivery_status' => SocialPost::DELIVERY_STATUS_PUBLISHED,
        'sync_status' => SocialPost::SYNC_STATUS_SYNCED,
        'published_at' => now(),
    ])->save();
    $canceledTarget->forceFill([
        'status' => SocialPostTarget::STATUS_CANCELED,
        'delivery_status' => SocialPost::DELIVERY_STATUS_CANCELED,
        'sync_status' => SocialPost::SYNC_STATUS_SYNCED,
        'failed_at' => null,
        'failure_reason' => null,
    ])->save();

    pulsePublishingProcessTargetOutbox($publishedTarget);

    $aggregatedPost = $queuedPost->fresh();

    expect($aggregatedPost->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_PUBLISHED)
        ->and($aggregatedPost->sync_status)->toBe(SocialPost::SYNC_STATUS_SYNCED)
        ->and($aggregatedPost->status)->toBe(SocialPost::STATUS_PUBLISHED)
        ->and($aggregatedPost->failed_at)->toBeNull()
        ->and($aggregatedPost->failure_reason)->toBeNull()
        ->and(data_get($aggregatedPost->metadata, 'status_summary.canceled'))->toBe(1);
});

it('aggregates all canceled targets as canceled without manufacturing a failure', function () {
    Queue::fake();
    pulsePublishingBindRegistry();

    $owner = pulsePublishingOwner();
    $facebook = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $linkedin = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_LINKEDIN);
    $queuedPost = app(SocialPublishingService::class)->publishNow(
        $owner,
        $owner,
        pulsePublishingDraft($owner, $owner, [$facebook, $linkedin]),
    );

    foreach ($queuedPost->targets as $target) {
        $target->forceFill([
            'status' => SocialPostTarget::STATUS_CANCELED,
            'delivery_status' => SocialPost::DELIVERY_STATUS_CANCELED,
            'sync_status' => SocialPost::SYNC_STATUS_SYNCED,
            'failed_at' => null,
            'failure_reason' => null,
        ])->save();
    }

    pulsePublishingProcessTargetOutbox($queuedPost->targets->first());

    $aggregatedPost = $queuedPost->fresh();

    expect($aggregatedPost->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_CANCELED)
        ->and($aggregatedPost->sync_status)->toBe(SocialPost::SYNC_STATUS_SYNCED)
        ->and($aggregatedPost->failed_at)->toBeNull()
        ->and($aggregatedPost->failure_reason)->toBeNull()
        ->and(data_get($aggregatedPost->metadata, 'status_summary.failed'))->toBe(0)
        ->and(data_get($aggregatedPost->metadata, 'status_summary.canceled'))->toBe(2);
});

it('treats incomplete legacy target axes as unknown and sync error', function () {
    Queue::fake();
    pulsePublishingBindRegistry();

    $owner = pulsePublishingOwner();
    $facebook = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $linkedin = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_LINKEDIN);
    $queuedPost = app(SocialPublishingService::class)->publishNow(
        $owner,
        $owner,
        pulsePublishingDraft($owner, $owner, [$facebook, $linkedin]),
    );
    [$legacyTarget, $publishedTarget] = $queuedPost->targets->values()->all();

    DB::table('social_post_targets')
        ->where('id', $legacyTarget->id)
        ->update([
            'status' => SocialPostTarget::STATUS_PUBLISHED,
            'delivery_status' => null,
            'sync_status' => null,
        ]);
    $publishedTarget->forceFill([
        'status' => SocialPostTarget::STATUS_PUBLISHED,
        'delivery_status' => SocialPost::DELIVERY_STATUS_PUBLISHED,
        'sync_status' => SocialPost::SYNC_STATUS_SYNCED,
        'published_at' => now(),
    ])->save();

    expect($publishedTarget->fresh()->current_revision_id)->not->toBeNull()
        ->and($publishedTarget->fresh()->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_PUBLISHED);

    pulsePublishingProcessTargetOutbox($publishedTarget);

    $aggregatedPost = $queuedPost->fresh();

    expect($aggregatedPost->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN)
        ->and($aggregatedPost->sync_status)->toBe(SocialPost::SYNC_STATUS_ERROR)
        ->and($aggregatedPost->status)->toBe(SocialPost::STATUS_FAILED);
});

it('keeps retryable publication failures non-terminal so the queue can retry', function () {
    Queue::fake();
    $retryablePublishers = pulsePublishingBindRegistry(
        retryablePlatforms: [SocialAccountConnection::PLATFORM_FACEBOOK],
    );

    $owner = pulsePublishingOwner();
    $connection = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $draft = pulsePublishingDraft($owner, $owner, [$connection]);
    $this->actingAs($owner)
        ->postJson(route('social.posts.publish', $draft))
        ->assertStatus(202);

    $target = $draft->targets()->sole();
    $retryableOutbox = pulsePublishingProcessTargetOutbox($target);

    $retryableTarget = $target->fresh();
    $retryablePost = $draft->fresh();

    expect($retryablePublishers[SocialAccountConnection::PLATFORM_FACEBOOK]->publishCalls)->toBe(1)
        ->and($retryableOutbox->status)->toBe(SocialDeliveryOutbox::STATUS_RETRYABLE)
        ->and($retryableOutbox->attempts)->toBe(1)
        ->and($retryableOutbox->request_started_at)->toBeNull()
        ->and($retryableOutbox->available_at)->not->toBeNull()
        ->and($retryableTarget->status)->toBe(SocialPostTarget::STATUS_PUBLISHING)
        ->and($retryableTarget->failed_at)->toBeNull()
        ->and($retryableTarget->failure_reason)->toBeNull()
        ->and($retryablePost->status)->toBe(SocialPost::STATUS_PUBLISHING)
        ->and($retryablePost->failed_at)->toBeNull()
        ->and($retryablePost->failure_reason)->toBeNull();

    $this->travelTo($retryableOutbox->available_at->copy()->addSecond());
    $successfulPublishers = pulsePublishingBindRegistry();
    $completedOutbox = pulsePublishingProcessTargetOutbox($target);

    expect($successfulPublishers[SocialAccountConnection::PLATFORM_FACEBOOK]->publishCalls)->toBe(1)
        ->and($completedOutbox->status)->toBe(SocialDeliveryOutbox::STATUS_COMPLETED)
        ->and($completedOutbox->attempts)->toBe(2)
        ->and($target->fresh()->status)->toBe(SocialPostTarget::STATUS_PUBLISHED)
        ->and($draft->fresh()->status)->toBe(SocialPost::STATUS_PUBLISHED);
});

it('quarantines ambiguous network outcomes and blocks duplicate retries', function () {
    Queue::fake();
    $publishers = pulsePublishingBindRegistry(
        ambiguousPlatforms: [SocialAccountConnection::PLATFORM_FACEBOOK],
    );

    $owner = pulsePublishingOwner();
    $connection = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $draft = pulsePublishingDraft($owner, $owner, [$connection]);
    $queuedPost = app(SocialPublishingService::class)->publishNow($owner, $owner, $draft);
    $target = $queuedPost->targets->sole();

    pulsePublishingProcessTargetOutbox($target);

    $ambiguousTarget = $target->fresh();
    $ambiguousPost = $draft->fresh();

    expect($publishers[SocialAccountConnection::PLATFORM_FACEBOOK]->publishCalls)->toBe(1)
        ->and($ambiguousTarget->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN)
        ->and($ambiguousTarget->sync_status)->toBe(SocialPost::SYNC_STATUS_ERROR)
        ->and($ambiguousTarget->failed_at)->toBeNull()
        ->and($ambiguousTarget->failure_reason)->toBeNull()
        ->and($ambiguousPost->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN)
        ->and($ambiguousPost->failed_at)->toBeNull();

    expect(fn () => app(SocialPublishingService::class)->publishNow(
        $owner,
        $owner,
        $ambiguousPost,
    ))->toThrow(ValidationException::class, 'must be reconciled before any retry');

    expect($publishers[SocialAccountConnection::PLATFORM_FACEBOOK]->publishCalls)->toBe(1);
});

it('recovers an exhausted pre-request worker only through its fenced lease', function () {
    Queue::fake();
    pulsePublishingBindRegistry();

    $owner = pulsePublishingOwner();
    $connection = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $draft = pulsePublishingDraft($owner, $owner, [$connection]);
    $queuedPost = app(SocialPublishingService::class)->publishNow($owner, $owner, $draft);
    $target = $queuedPost->targets->sole();
    $outbox = SocialDeliveryOutbox::query()
        ->where('social_post_target_id', $target->id)
        ->where('social_post_revision_id', $target->last_submitted_revision_id)
        ->sole();
    $outboxes = app(SocialDeliveryOutboxService::class);
    $claim = $outboxes->claim($outbox->id, 'failed-pre-request-worker', 60);
    $targetBefore = $target->fresh()->getAttributes();
    $postBefore = $draft->fresh()->getAttributes();

    expect($claim)->not->toBeNull();

    $this->travel(61)->seconds();
    $summary = app(SocialPublishingService::class)->maintainDeliveryOutbox();

    expect($summary)->toBe([
        'pending_recovered' => 1,
        'unknown_quarantined' => 0,
        'aggregates_repaired' => 0,
        'dispatched' => 1,
    ])->and($outbox->fresh()->status)->toBe(SocialDeliveryOutbox::STATUS_PENDING)
        ->and($outbox->fresh()->claim_version)->toBe(2)
        ->and($target->fresh()->getAttributes())->toBe($targetBefore)
        ->and($draft->fresh()->getAttributes())->toBe($postBefore)
        ->and($connection->fresh()->last_error)->toBeNull();
});

it('quarantines an exhausted post-request worker through the durable lease sweeper', function () {
    Queue::fake();
    pulsePublishingBindRegistry();

    $owner = pulsePublishingOwner();
    $connection = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $draft = pulsePublishingDraft($owner, $owner, [$connection]);
    $queuedPost = app(SocialPublishingService::class)->publishNow($owner, $owner, $draft);
    $target = $queuedPost->targets->sole();
    $outbox = SocialDeliveryOutbox::query()
        ->where('social_post_target_id', $target->id)
        ->where('social_post_revision_id', $target->last_submitted_revision_id)
        ->sole();
    $outboxService = app(SocialDeliveryOutboxService::class);
    $claim = $outboxService->claim($outbox->id, 'timed-out-submitting-worker', 60);

    expect($claim)->not->toBeNull()
        ->and($outboxService->startSubmitting(
            $outbox->id,
            $claim['claim_token'],
            $claim['claim_version'],
        ))->toBeTrue();

    $target->forceFill([
        'status' => SocialPostTarget::STATUS_PUBLISHING,
        'delivery_status' => 'sending',
    ])->save();

    $this->travel(61)->seconds();
    $summary = app(SocialPublishingService::class)->maintainDeliveryOutbox();

    expect($summary['unknown_quarantined'])->toBe(1)
        ->and($summary['aggregates_repaired'])->toBe(1)
        ->and($target->fresh()->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN)
        ->and($target->fresh()->failed_at)->toBeNull()
        ->and($target->fresh()->failure_reason)->toBeNull()
        ->and($draft->fresh()->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN)
        ->and($connection->fresh()->last_error)->toBeNull()
        ->and($outbox->fresh()->status)->toBe(SocialDeliveryOutbox::STATUS_UNKNOWN)
        ->and($outbox->fresh()->last_error_code)->toBe('lease_expired_after_request_start')
        ->and($outbox->fresh()->aggregate_repaired_at)->not->toBeNull();
});

it('never retries a create after the provider returns an invalid success result', function (array $overrides) {
    Queue::fake();
    $publishers = pulsePublishingBindRegistry(
        resultOverridesByPlatform: [
            SocialAccountConnection::PLATFORM_FACEBOOK => $overrides,
        ],
    );

    $owner = pulsePublishingOwner();
    $connection = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $draft = pulsePublishingDraft($owner, $owner, [$connection]);
    $queuedPost = app(SocialPublishingService::class)->publishNow($owner, $owner, $draft);
    $target = $queuedPost->targets->sole();
    $outbox = pulsePublishingProcessTargetOutbox($target);

    expect($publishers[SocialAccountConnection::PLATFORM_FACEBOOK]->publishCalls)->toBe(1)
        ->and($outbox->status)->toBe(SocialDeliveryOutbox::STATUS_UNKNOWN)
        ->and($outbox->last_error_code)->toBe('invalid_result_after_request_start')
        ->and($outbox->aggregate_repaired_at)->not->toBeNull()
        ->and($target->fresh()->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN)
        ->and($draft->fresh()->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN);

    expect(fn () => app(SocialPublishingService::class)->publishNow(
        $owner,
        $owner,
        $draft->fresh(),
    ))->toThrow(ValidationException::class, 'must be reconciled before any retry');

    app(SocialPublishingService::class)->handleOutboxPublication($outbox->id);

    expect($publishers[SocialAccountConnection::PLATFORM_FACEBOOK]->publishCalls)->toBe(1);
})->with([
    'malformed published timestamp' => [['published_at' => 'not-a-provider-timestamp']],
    'oversized remote identifier' => [['provider_post_id' => str_repeat('p', 192)]],
]);

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
    $exception = null;

    try {
        $publisher->publish($connection, [
            'text' => 'Pulse production transport contract',
        ]);
    } catch (RetryableSocialPublishingException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(RetryableSocialPublishingException::class)
        ->and($exception?->getMessage())->toBe('LinkedIn rate limit reached.')
        ->and($exception?->remoteEffectIsImpossible())->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://linkedin.test/v2/posts'
        && $request->hasHeader('Authorization', 'Bearer token-linkedin')
        && data_get($request->data(), 'target_id') === $connection->external_account_id);
});

it('quarantines a create rejected with 429 when retry safety is not explicitly proven', function () {
    Queue::fake();
    config()->set('services.social.linkedin.publish.fake', false);
    config()->set('services.social.linkedin.publish.url', 'https://linkedin.test/v2/posts');
    Http::preventStrayRequests();
    Http::fake([
        'https://linkedin.test/v2/posts' => Http::response([
            'error' => ['message' => 'LinkedIn rate limit reached.'],
        ], 429),
    ]);

    $owner = pulsePublishingOwner();
    $connection = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_LINKEDIN);
    $draft = pulsePublishingDraft($owner, $owner, [$connection]);
    $queuedPost = app(SocialPublishingService::class)->publishNow($owner, $owner, $draft);
    $target = $queuedPost->targets->sole();
    $outbox = pulsePublishingProcessTargetOutbox($target);

    expect($outbox->status)->toBe(SocialDeliveryOutbox::STATUS_UNKNOWN)
        ->and($outbox->last_error_code)->toBe('create_retry_safety_not_proven')
        ->and($target->fresh()->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN)
        ->and($draft->fresh()->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN);

    pulsePublishingProcessTargetOutbox($target);

    Http::assertSentCount(1);
});

it('classifies provider server errors after create as ambiguous and blocks redispatch', function () {
    Queue::fake();
    config()->set('services.social.facebook.publish.fake', false);
    config()->set('services.social.facebook.publish.url', 'https://facebook.test/v1/posts');
    Http::preventStrayRequests();
    Http::fake([
        'facebook.test/*' => Http::response([
            'message' => 'Upstream create outcome unavailable',
        ], 502),
    ]);

    $owner = pulsePublishingOwner();
    $connection = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $draft = pulsePublishingDraft($owner, $owner, [$connection]);
    $queuedPost = app(SocialPublishingService::class)->publishNow($owner, $owner, $draft);
    $target = $queuedPost->targets->sole();

    pulsePublishingProcessTargetOutbox($target);

    expect($target->fresh()->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN)
        ->and($target->fresh()->failed_at)->toBeNull()
        ->and($draft->fresh()->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN);

    expect(fn () => app(SocialPublishingService::class)->publishNow(
        $owner,
        $owner,
        $draft->fresh(),
    ))->toThrow(ValidationException::class, 'must be reconciled before any retry');

    Http::assertSentCount(1);
});

it('refuses to publish a target through a connection owned by another pulse tenant', function () {
    Queue::fake();
    $publishers = pulsePublishingBindRegistry();

    $owner = pulsePublishingOwner();
    $foreignOwner = pulsePublishingOwner();
    $foreignConnection = pulsePublishingConnection(
        $foreignOwner,
        SocialAccountConnection::PLATFORM_LINKEDIN
    );
    $localConnection = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_LINKEDIN);
    $draft = pulsePublishingDraft($owner, $owner, [$localConnection]);
    $queuedPost = app(SocialPublishingService::class)->publishNow($owner, $owner, $draft);
    $target = $queuedPost->targets->sole();
    SocialPostTarget::withoutEvents(function () use ($foreignConnection, $target): void {
        $target->forceFill([
            'social_account_connection_id' => $foreignConnection->id,
        ])->save();
    });

    pulsePublishingProcessTargetOutbox($target);

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
    Notification::fake();
    $owner = pulsePublishingOwner();
    $foreignOwner = pulsePublishingOwner();
    $foreignConnection = pulsePublishingConnection(
        $foreignOwner,
        SocialAccountConnection::PLATFORM_LINKEDIN
    );
    $localConnection = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_LINKEDIN);
    $draft = pulsePublishingDraft($owner, $owner, [$localConnection]);
    $target = $draft->targets->sole();
    SocialPostTarget::withoutEvents(function () use ($foreignConnection, $target): void {
        $target->forceFill([
            'social_account_connection_id' => $foreignConnection->id,
        ])->save();
    });
    $foreignConnection->update(['label' => 'Private foreign account']);
    $foreignConnectionBeforeQueue = $foreignConnection->fresh()->getAttributes();
    Queue::fake();

    $this->actingAs($owner)
        ->postJson(route('social.posts.publish', $draft))
        ->assertStatus(202)
        ->assertJsonPath('draft.status', SocialPost::STATUS_FAILED)
        ->assertJsonPath('draft.targets.0.status', SocialPostTarget::STATUS_FAILED);

    Queue::assertNothingPushed();

    Notification::assertSentTo($owner, SocialPublicationCompletedNotification::class, function ($notification) use ($owner) {
        expect($notification->snapshot['outcome'])->toBe('failed');
        expect($notification->toArray($owner)['message'])->not->toContain('Private foreign account');

        return true;
    });
    Notification::assertNotSentTo($foreignOwner, SocialPublicationCompletedNotification::class);

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

it('configures pulse publication jobs for after commit dispatch bounded execution and per outbox overlap protection', function () {
    $job = new ProcessSocialDeliveryOutboxJob(101);
    $sameOutboxJob = new ProcessSocialDeliveryOutboxJob(101);
    $otherOutboxJob = new ProcessSocialDeliveryOutboxJob(202);

    expect($job)->toBeInstanceOf(ShouldQueueAfterCommit::class)
        ->and($job->outboxId)->toBe(101)
        ->and($job->uniqueId())->toBe('101')
        ->and($job->uniqueFor)->toBe(300)
        ->and($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([30, 120, 300])
        ->and($job->timeout)->toBe(60)
        ->and(QueueWorkload::timeout('social_publish'))->toBe(60)
        ->and($job->failOnTimeout)->toBeTrue();

    expect($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'));

    $middleware = collect($job->middleware())
        ->first(fn (object $item): bool => $item instanceof WithoutOverlapping);
    $sameOutboxMiddleware = collect($sameOutboxJob->middleware())
        ->first(fn (object $item): bool => $item instanceof WithoutOverlapping);
    $otherOutboxMiddleware = collect($otherOutboxJob->middleware())
        ->first(fn (object $item): bool => $item instanceof WithoutOverlapping);

    expect($middleware)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($sameOutboxMiddleware)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($otherOutboxMiddleware)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware->key)->toBe('social-delivery-outbox:101')
        ->and($middleware->key)->toBe($sameOutboxMiddleware->key)
        ->and($middleware->key)->not->toBe($otherOutboxMiddleware->key)
        ->and($middleware->releaseAfter)->toBeNull()
        ->and($middleware->expiresAfter)->toBe(120)
        ->and($middleware->shareKey)->toBeTrue();
});

it('never remaps an existing pulse outbox to a newer submitted revision', function () {
    Queue::fake();
    $publishers = pulsePublishingBindRegistry();

    $owner = pulsePublishingOwner();
    $connection = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $queuedPost = app(SocialPublishingService::class)->publishNow(
        $owner,
        $owner,
        pulsePublishingDraft($owner, $owner, [$connection]),
    );
    $target = $queuedPost->targets->sole();
    $originalOutbox = SocialDeliveryOutbox::query()
        ->where('social_post_target_id', $target->id)
        ->where('social_post_revision_id', $target->last_submitted_revision_id)
        ->sole();
    $originalRevisionId = (int) $originalOutbox->social_post_revision_id;
    $originalPayloadHash = (string) $originalOutbox->payload_hash;
    $originalIdempotencyKey = (string) $originalOutbox->idempotency_key;
    $queuedPost->forceFill([
        'content_payload' => ['text' => 'A newer revision replaced the original job snapshot.'],
    ])->save();
    $newRevision = app(SocialPostRevisionService::class)->capture($queuedPost, $owner);
    app(SocialPostRevisionService::class)->approveDirectly($queuedPost, $owner, now());
    $target->refresh()->forceFill([
        'last_submitted_revision_id' => $newRevision->id,
        'delivery_status' => SocialPost::DELIVERY_STATUS_QUEUED,
    ])->save();
    $postBeforeOriginalOutbox = $queuedPost->fresh()->getAttributes();
    $targetBeforeOriginalOutbox = $target->fresh()->getAttributes();
    $job = new ProcessSocialDeliveryOutboxJob($originalOutbox->id);

    $job->handle(app(SocialPublishingService::class));

    $rejectedOutbox = $originalOutbox->fresh();

    expect($job->outboxId)->toBe($originalOutbox->id)
        ->and($publishers[SocialAccountConnection::PLATFORM_FACEBOOK]->publishCalls)->toBe(0)
        ->and($rejectedOutbox->status)->toBe(SocialDeliveryOutbox::STATUS_DEAD)
        ->and($rejectedOutbox->last_error_code)->toBe('submitted_revision_replaced')
        ->and($rejectedOutbox->social_post_revision_id)->toBe($originalRevisionId)
        ->and($rejectedOutbox->social_post_revision_id)->not->toBe($newRevision->id)
        ->and($rejectedOutbox->payload_hash)->toBe($originalPayloadHash)
        ->and($rejectedOutbox->idempotency_key)->toBe($originalIdempotencyKey)
        ->and($queuedPost->fresh()->getAttributes())->toBe($postBeforeOriginalOutbox)
        ->and($target->fresh()->getAttributes())->toBe($targetBeforeOriginalOutbox);
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

    Queue::assertPushed(ProcessSocialDeliveryOutboxJob::class);
});

it('forbids retry without publish permission and hides posts from another Pulse workspace', function () {
    Queue::fake([ProcessSocialDeliveryOutboxJob::class]);

    $owner = pulsePublishingOwner();
    $foreignOwner = pulsePublishingOwner();
    $manager = pulsePublishingTeamMember($owner, ['social.manage']);
    $connection = pulsePublishingConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $draft = pulsePublishingDraft($owner, $owner, [$connection]);

    $this->actingAs($manager)
        ->postJson(route('social.posts.retry', $draft))
        ->assertForbidden();

    $this->actingAs($foreignOwner)
        ->postJson(route('social.posts.retry', $draft))
        ->assertNotFound();

    Queue::assertNothingPushed();
    expect(SocialDeliveryOutbox::query()->count())->toBe(0);
});

it('blocks pulse publish schedule and retry routes when the social feature is disabled', function () {
    Queue::fake([ProcessSocialDeliveryOutboxJob::class]);
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

    $this->actingAs($owner)
        ->postJson(route('social.posts.retry', $draft))
        ->assertForbidden();

    Queue::assertNothingPushed();
    expect(SocialDeliveryOutbox::query()->count())->toBe(0);
});
