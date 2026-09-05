<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Jobs\ProcessSocialDeliveryOutboxJob;
use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\SocialApprovalRequest;
use App\Models\SocialPost;
use App\Models\SocialPostRevision;
use App\Models\SocialPostTarget;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\SocialApprovalRequestedNotification;
use App\Services\Social\SocialApprovalService;
use App\Services\Social\SocialPostRevisionService;
use App\Services\Social\SocialPostService;
use App\Services\Social\SocialPublishingService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
    $attributes = array_merge([
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
    ], $overrides);

    return SocialAccountConnection::query()->create([
        ...$attributes,
        ...pulseDirectTransportIdentity($owner, $platform, (string) $attributes['external_account_id']),
    ]);
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

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
    Notification::fake();
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

    $pendingRequest = SocialApprovalRequest::query()
        ->with('socialPostRevision')
        ->where('social_post_id', $draft->id)
        ->first();
    $submittedDraft = $draft->fresh(['revisions', 'targets.currentRevision']);

    expect($pendingRequest)->not->toBeNull()
        ->and((string) $pendingRequest?->status)->toBe(SocialApprovalRequest::STATUS_PENDING)
        ->and((int) $pendingRequest?->requested_by_user_id)->toBe((int) $publisher->id)
        ->and($pendingRequest?->socialPostRevision?->social_post_id)->toBe($draft->id)
        ->and($pendingRequest?->social_post_revision_id)->toBe($submittedDraft?->revisions->sole()->id)
        ->and($submittedDraft?->current_editorial_revision)->toBe(1)
        ->and($submittedDraft?->targets->sole()->current_revision_id)->toBe($pendingRequest?->social_post_revision_id);

    $this->actingAs($publisher)
        ->putJson(route('social.posts.update', $draft), [
            'text' => 'Trying to edit after approval submission',
            'target_connection_ids' => [$connection->id],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('post');

    $this->actingAs($publisher)
        ->putJson(route('social.posts.reschedule', $draft), [
            'scheduled_for' => now()->addDays(3)->toIso8601String(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('post');
});

it('returns 422 without mutations when a queued pulse post is submitted for approval', function () {
    Queue::fake([ProcessSocialDeliveryOutboxJob::class]);

    $owner = pulseApprovalOwner();
    $publisher = pulseApprovalTeamMember($owner, ['social.publish']);
    $connection = pulseApprovalConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $draft = pulseApprovalDraft($owner, $publisher, [$connection]);
    $draft->forceFill([
        'metadata' => array_merge((array) $draft->metadata, [
            'publish_mode' => 'immediate',
            'publish_requested_at' => '2026-08-27T15:30:00+00:00',
            'publish_requested_by_user_id' => $owner->id,
            'queued_targets_count' => 1,
        ]),
    ])->save();
    $previousApprovalRequest = $draft->approvalRequests()->create([
        'requested_by_user_id' => $publisher->id,
        'resolved_by_user_id' => $owner->id,
        'status' => SocialApprovalRequest::STATUS_REJECTED,
        'note' => 'Previous review completed.',
        'requested_at' => now()->subDay(),
        'rejected_at' => now()->subHours(23),
        'metadata' => [
            'requested_mode' => 'immediate',
        ],
    ]);
    $postBeforeSubmission = $draft->fresh()->getAttributes();
    $target = $draft->targets()->sole();
    $targetBeforeSubmission = $target->getAttributes();
    $approvalRequestBeforeSubmission = $previousApprovalRequest->fresh()->getAttributes();

    $this->actingAs($publisher)
        ->postJson(route('social.posts.submit-approval', $draft), [
            'note' => 'This request must not replace the queued publication.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('post')
        ->assertJsonPath('errors.post.0', 'This Pulse post has already been queued for publication.');

    Queue::assertNothingPushed();
    Notification::assertNothingSent();

    expect($draft->fresh()->getAttributes())->toBe($postBeforeSubmission)
        ->and($target->fresh()->getAttributes())->toBe($targetBeforeSubmission)
        ->and($previousApprovalRequest->fresh()->getAttributes())->toBe($approvalRequestBeforeSubmission)
        ->and($draft->approvalRequests()->count())->toBe(1);
});

it('rejects stale draft edits and rescheduling after the post enters approval', function () {
    $owner = pulseApprovalOwner();
    $publisher = pulseApprovalTeamMember($owner, ['social.publish']);
    $connection = pulseApprovalConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $scheduledFor = now()->addDays(2)->startOfHour();
    $draft = pulseApprovalDraft($owner, $publisher, [$connection], [
        'scheduled_for' => $scheduledFor,
        'text' => 'Frozen approval content',
    ]);
    $staleEditSnapshot = SocialPost::query()
        ->with('targets.socialAccountConnection')
        ->findOrFail($draft->id);
    $staleRescheduleSnapshot = SocialPost::query()
        ->with('targets.socialAccountConnection')
        ->findOrFail($draft->id);

    $this->actingAs($publisher)
        ->postJson(route('social.posts.submit-approval', $draft))
        ->assertStatus(202);

    $postService = app(SocialPostService::class);

    $editException = null;
    try {
        $postService->updateDraft($owner, $publisher, $staleEditSnapshot, [
            'text' => 'Stale content must not replace the approved revision',
            'target_connection_ids' => [$connection->id],
        ]);
    } catch (ValidationException $exception) {
        $editException = $exception;
    }

    expect($editException)->toBeInstanceOf(ValidationException::class)
        ->and($editException?->errors())->toBe([
            'post' => [
                'This Pulse post is waiting for approval. Reject it first if you need to edit the content again.',
            ],
        ]);

    $rescheduleException = null;
    try {
        $postService->rescheduleDraft($owner, $publisher, $staleRescheduleSnapshot, [
            'scheduled_for' => now()->addDays(5)->toIso8601String(),
        ]);
    } catch (ValidationException $exception) {
        $rescheduleException = $exception;
    }

    expect($rescheduleException)->toBeInstanceOf(ValidationException::class)
        ->and($rescheduleException?->errors())->toBe([
            'post' => [
                'This Pulse post is waiting for approval. Reject it first if you need to edit the content again.',
            ],
        ]);

    $frozenPost = $draft->fresh();

    expect($frozenPost->status)->toBe(SocialPost::STATUS_PENDING_APPROVAL)
        ->and(data_get($frozenPost->content_payload, 'text'))->toBe('Frozen approval content')
        ->and($frozenPost->scheduled_for?->equalTo($scheduledFor))->toBeTrue();
});

it('emails approvers a sober visual preview of the pending pulse post', function () {
    $owner = pulseApprovalOwner([
        'company_name' => 'Studio Pulse',
        'company_logo' => 'https://assets.example.test/studio-pulse.png',
    ]);
    $publisher = pulseApprovalTeamMember($owner, ['social.publish']);
    $approver = pulseApprovalTeamMember($owner, ['social.approve']);
    $facebook = pulseApprovalConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK, [
        'display_name' => 'Studio Pulse Facebook',
    ]);
    $instagram = pulseApprovalConnection($owner, SocialAccountConnection::PLATFORM_INSTAGRAM, [
        'display_name' => 'Studio Pulse Instagram',
    ]);
    $draft = pulseApprovalDraft($owner, $publisher, [$facebook, $instagram], [
        'text' => "Decouvrez notre soin signature.\n\nReservez votre moment.",
        'image_url' => 'https://example.com/assets/social-preview.jpg',
        'link_url' => 'https://example.com/book',
    ]);

    $this->actingAs($publisher)
        ->postJson(route('social.posts.submit-approval', $draft), [
            'note' => 'Ready for review.',
        ])
        ->assertStatus(202);

    Notification::assertSentTo($owner, SocialApprovalRequestedNotification::class);
    Notification::assertSentTo($approver, SocialApprovalRequestedNotification::class);
    Notification::assertNotSentTo($publisher, SocialApprovalRequestedNotification::class);

    $captured = null;
    Notification::assertSentTo(
        $owner,
        SocialApprovalRequestedNotification::class,
        function (SocialApprovalRequestedNotification $notification) use ($owner, &$captured): bool {
            $captured = unserialize(serialize($notification))->toMail($owner);

            return true;
        }
    );

    expect($captured)->not->toBeNull()
        ->and($captured->subject)->toContain('Pulse')
        ->and($captured->viewData['companyName'])->toBe('Studio Pulse')
        ->and($captured->viewData['companyLogo'])->toBe('https://assets.example.test/studio-pulse.png');

    $view = is_array($captured->view) ? $captured->view[0] : $captured->view;
    $html = view($view, $captured->viewData)->render();

    expect($html)->toContain('Post a valider')
        ->and($html)->toContain('Facebook')
        ->and($html)->toContain('Instagram')
        ->and($html)->toContain('Decouvrez notre soin signature.')
        ->and($html)->toContain('https://example.com/assets/social-preview.jpg')
        ->and($html)->toContain('https://assets.example.test/studio-pulse.png')
        ->and(substr_count($html, __('mail.layout.powered_by', ['platform' => 'Malikia Pro'])))->toBe(1)
        ->and($html)->not->toContain('detail_metric')
        ->and($html)->not->toContain('platform_tagline');
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

    Queue::assertPushed(ProcessSocialDeliveryOutboxJob::class, 1);

    $freshPost = SocialPost::query()
        ->with(['approvedRevision', 'latestApprovalRequest.socialPostRevision', 'targets.lastSubmittedRevision'])
        ->findOrFail($draft->id);

    expect($freshPost->status)->toBe(SocialPost::STATUS_PUBLISHING)
        ->and((string) $freshPost->latestApprovalRequest?->status)->toBe(SocialApprovalRequest::STATUS_APPROVED)
        ->and((int) $freshPost->latestApprovalRequest?->resolved_by_user_id)->toBe((int) $approver->id)
        ->and($freshPost->approvedRevision?->approval_provenance)->toBe(SocialPostRevision::APPROVAL_TYPE_EXPLICIT)
        ->and($freshPost->latestApprovalRequest?->socialPostRevision?->is($freshPost->approvedRevision))->toBeTrue()
        ->and($freshPost->targets->sole()->lastSubmittedRevision?->is($freshPost->approvedRevision))->toBeTrue();
});

it('rejects a second approval from a stale pending snapshot without queuing twice', function () {
    Queue::fake([ProcessSocialDeliveryOutboxJob::class]);

    $owner = pulseApprovalOwner();
    $publisher = pulseApprovalTeamMember($owner, ['social.publish']);
    $firstApprover = pulseApprovalTeamMember($owner, ['social.approve']);
    $secondApprover = pulseApprovalTeamMember($owner, ['social.approve']);
    $connection = pulseApprovalConnection($owner, SocialAccountConnection::PLATFORM_LINKEDIN);
    $draft = pulseApprovalDraft($owner, $publisher, [$connection]);

    $this->actingAs($publisher)
        ->postJson(route('social.posts.submit-approval', $draft))
        ->assertStatus(202);

    $relations = [
        'targets.socialAccountConnection',
        'latestApprovalRequest.requestedBy',
        'latestApprovalRequest.resolvedBy',
    ];
    $firstSnapshot = SocialPost::query()->with($relations)->findOrFail($draft->id);
    $secondSnapshot = SocialPost::query()->with($relations)->findOrFail($draft->id);
    $approvalService = app(SocialApprovalService::class);

    $approvalService->approve($owner, $firstApprover, $firstSnapshot);

    $secondApprovalException = null;
    try {
        $approvalService->approve($owner, $secondApprover, $secondSnapshot);
    } catch (ValidationException $exception) {
        $secondApprovalException = $exception;
    }

    expect($secondApprovalException)->toBeInstanceOf(ValidationException::class)
        ->and($secondApprovalException?->errors())->toBe([
            'post' => ['This Pulse post has no pending approval request.'],
        ]);

    Queue::assertPushed(ProcessSocialDeliveryOutboxJob::class, 1);

    $approvalRequest = SocialApprovalRequest::query()
        ->where('social_post_id', $draft->id)
        ->sole();

    expect($approvalRequest->status)->toBe(SocialApprovalRequest::STATUS_APPROVED)
        ->and((int) $approvalRequest->resolved_by_user_id)->toBe((int) $firstApprover->id);
});

it('rejects an approval whose immutable revision was superseded', function () {
    Queue::fake([ProcessSocialDeliveryOutboxJob::class]);

    $owner = pulseApprovalOwner();
    $publisher = pulseApprovalTeamMember($owner, ['social.publish']);
    $approver = pulseApprovalTeamMember($owner, ['social.approve']);
    $connection = pulseApprovalConnection($owner, SocialAccountConnection::PLATFORM_LINKEDIN);
    $draft = pulseApprovalDraft($owner, $publisher, [$connection]);
    $approvalService = app(SocialApprovalService::class);

    $approvalService->submit($owner, $publisher, $draft);
    $pendingRequest = $draft->approvalRequests()->sole();
    $pendingRevisionId = (int) $pendingRequest->social_post_revision_id;
    $pendingPost = $draft->fresh(['targets.socialAccountConnection']);
    $pendingPost->forceFill([
        'content_payload' => ['text' => 'A concurrent writer created a newer revision.'],
        'updated_by_user_id' => $publisher->id,
    ])->save();
    $currentRevision = app(SocialPostRevisionService::class)->capture($pendingPost, $publisher);

    expect(fn () => $approvalService->approve($owner, $approver, $pendingPost->fresh()))
        ->toThrow(ValidationException::class);

    Queue::assertNothingPushed();

    $pendingRequest->refresh();
    $pendingPost->refresh();

    expect($currentRevision->id)->not->toBe($pendingRevisionId)
        ->and($pendingPost->current_editorial_revision)->toBe($currentRevision->revision_number)
        ->and($pendingPost->approved_revision_id)->toBeNull()
        ->and($pendingRequest->social_post_revision_id)->toBe($pendingRevisionId)
        ->and($pendingRequest->status)->toBe(SocialApprovalRequest::STATUS_PENDING)
        ->and($pendingRequest->resolved_by_user_id)->toBeNull();
});

it('returns 404 when an actor from another workspace resolves an approval', function () {
    $owner = pulseApprovalOwner();
    $publisher = pulseApprovalTeamMember($owner, ['social.publish']);
    $approverFromAnotherWorkspace = pulseApprovalOwner();
    $connection = pulseApprovalConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $draft = pulseApprovalDraft($owner, $publisher, [$connection]);
    $approvalService = app(SocialApprovalService::class);

    $approvalService->submit($owner, $publisher, $draft);

    expect(fn () => $approvalService->reject($owner, $approverFromAnotherWorkspace, $draft->fresh()))
        ->toThrow(NotFoundHttpException::class);

    $approvalRequest = $draft->approvalRequests()->sole();

    expect($approvalRequest->status)->toBe(SocialApprovalRequest::STATUS_PENDING)
        ->and($approvalRequest->resolved_by_user_id)->toBeNull();
});

it('types direct implicit approval and binds the submitted target to that revision', function () {
    Queue::fake([ProcessSocialDeliveryOutboxJob::class]);

    $owner = pulseApprovalOwner();
    $connection = pulseApprovalConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $draft = pulseApprovalDraft($owner, $owner, [$connection]);

    app(SocialPublishingService::class)->publishNow($owner, $owner, $draft);

    $publishedDraft = $draft->fresh([
        'approvedRevision',
        'latestApprovalRequest.socialPostRevision',
        'targets.lastSubmittedRevision',
    ]);

    Queue::assertPushed(ProcessSocialDeliveryOutboxJob::class, 1);

    expect($publishedDraft->approvedRevision?->approval_provenance)
        ->toBe(SocialPostRevision::APPROVAL_TYPE_DIRECT_IMPLICIT)
        ->and($publishedDraft->latestApprovalRequest?->status)->toBe(SocialApprovalRequest::STATUS_APPROVED)
        ->and($publishedDraft->latestApprovalRequest?->socialPostRevision?->is($publishedDraft->approvedRevision))->toBeTrue()
        ->and($publishedDraft->targets->sole()->lastSubmittedRevision?->is($publishedDraft->approvedRevision))->toBeTrue();
});

it('preserves retained target ids and refuses to remove a submitted destination', function () {
    $owner = pulseApprovalOwner();
    $facebook = pulseApprovalConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $linkedin = pulseApprovalConnection($owner, SocialAccountConnection::PLATFORM_LINKEDIN);
    $x = pulseApprovalConnection($owner, SocialAccountConnection::PLATFORM_X);
    $postService = app(SocialPostService::class);
    $draft = $postService->createDraft($owner, $owner, [
        'text' => 'Initial differential target selection.',
        'target_connection_ids' => [$facebook->id, $linkedin->id],
    ]);
    $initialTargets = $draft->targets->keyBy('social_account_connection_id');

    $updatedDraft = $postService->updateDraft($owner, $owner, $draft, [
        'text' => 'Updated differential target selection.',
        'target_connection_ids' => [$linkedin->id, $x->id],
    ]);
    $updatedTargets = $updatedDraft->targets->keyBy('social_account_connection_id');

    expect($updatedDraft->revisions()->count())->toBe(2)
        ->and($updatedTargets->get($linkedin->id)?->id)->toBe($initialTargets->get($linkedin->id)?->id)
        ->and($updatedTargets->get($x->id)?->id)->not->toBeNull()
        ->and(SocialPostTarget::query()->whereKey($initialTargets->get($facebook->id)?->id)->exists())->toBeFalse()
        ->and($updatedTargets->every(
            fn (SocialPostTarget $target): bool => $target->current_editorial_revision === 2
                && $target->current_revision_id !== null,
        ))->toBeTrue();

    app(SocialPostRevisionService::class)->approveDirectly($updatedDraft, $owner, now());
    $submittedTarget = $updatedTargets->get($linkedin->id)?->fresh();
    $submittedTarget?->forceFill([
        'last_submitted_revision_id' => $submittedTarget->current_revision_id,
    ])->save();
    $postAttributesBeforeRejectedEdit = $updatedDraft->fresh()->getAttributes();
    $targetIdsBeforeRejectedEdit = $updatedDraft->targets()->pluck('id')->all();

    try {
        $postService->updateDraft($owner, $owner, $updatedDraft, [
            'text' => 'This edit must roll back with its target removal.',
            'target_connection_ids' => [$x->id],
        ]);
        $this->fail('A submitted Pulse target was removed.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe([
            'target_connection_ids' => [
                'A submitted Pulse destination cannot be removed from its historical post.',
            ],
        ]);
    }

    expect($updatedDraft->fresh()->getAttributes())->toBe($postAttributesBeforeRejectedEdit)
        ->and($updatedDraft->revisions()->count())->toBe(2)
        ->and($updatedDraft->targets()->pluck('id')->all())->toBe($targetIdsBeforeRejectedEdit);
});

it('rolls back an approval resolution when publication queue preparation fails', function () {
    $owner = pulseApprovalOwner();
    $publisher = pulseApprovalTeamMember($owner, ['social.publish']);
    $approver = pulseApprovalTeamMember($owner, ['social.approve']);
    $connection = pulseApprovalConnection($owner, SocialAccountConnection::PLATFORM_X);
    $scheduledFor = now()->addDays(4)->startOfHour();
    $draft = pulseApprovalDraft($owner, $publisher, [$connection], [
        'scheduled_for' => $scheduledFor,
    ]);

    $this->actingAs($publisher)
        ->postJson(route('social.posts.submit-approval', $draft))
        ->assertStatus(202);

    $publishingService = \Mockery::mock(SocialPublishingService::class);
    $publishingService->shouldReceive('publishNow')
        ->once()
        ->andThrow(new RuntimeException('Pulse queue preparation failed.'));
    $publishingService->shouldReceive('schedule')->never();
    $approvalService = new SocialApprovalService(
        $publishingService,
        app(SocialPostRevisionService::class),
        app(\App\Services\Social\SocialScheduledTimeResolver::class),
    );
    $pendingPost = $draft->fresh([
        'targets.socialAccountConnection',
        'latestApprovalRequest.requestedBy',
        'latestApprovalRequest.resolvedBy',
    ]);

    expect(fn () => $approvalService->approve($owner, $approver, $pendingPost, [
        'mode' => 'immediate',
    ]))->toThrow(RuntimeException::class, 'Pulse queue preparation failed.');

    $rolledBackPost = $draft->fresh(['targets', 'latestApprovalRequest']);

    expect($rolledBackPost->status)->toBe(SocialPost::STATUS_PENDING_APPROVAL)
        ->and($rolledBackPost->scheduled_for?->equalTo($scheduledFor))->toBeTrue()
        ->and($rolledBackPost->targets->sole()->status)->toBe(SocialPostTarget::STATUS_SCHEDULED)
        ->and($rolledBackPost->latestApprovalRequest?->status)->toBe(SocialApprovalRequest::STATUS_PENDING)
        ->and($rolledBackPost->latestApprovalRequest?->resolved_by_user_id)->toBeNull()
        ->and($rolledBackPost->latestApprovalRequest?->approved_at)->toBeNull();
});

it('fully rolls back a scheduled approval when scheduled queue preparation fails', function () {
    Queue::fake([ProcessSocialDeliveryOutboxJob::class]);

    $owner = pulseApprovalOwner();
    $publisher = pulseApprovalTeamMember($owner, ['social.publish']);
    $approver = pulseApprovalTeamMember($owner, ['social.approve']);
    $connection = pulseApprovalConnection($owner, SocialAccountConnection::PLATFORM_X);
    $draft = pulseApprovalDraft($owner, $publisher, [$connection]);

    $this->actingAs($publisher)
        ->postJson(route('social.posts.submit-approval', $draft))
        ->assertStatus(202);

    $postBeforeApproval = $draft->fresh()->getAttributes();
    $target = $draft->targets()->sole();
    $targetBeforeApproval = $target->getAttributes();
    $approvalRequest = $draft->approvalRequests()->sole();
    $approvalRequestBeforeApproval = $approvalRequest->getAttributes();
    $scheduledFor = now()->addDays(4)->startOfHour();

    $publishingService = \Mockery::mock(SocialPublishingService::class);
    $publishingService->shouldReceive('schedule')
        ->once()
        ->withArgs(function (User $queuedOwner, User $queuedActor, SocialPost $queuedPost) use (
            $owner,
            $approver,
            $scheduledFor
        ): bool {
            return $queuedOwner->is($owner)
                && $queuedActor->is($approver)
                && $queuedPost->status === SocialPost::STATUS_SCHEDULED
                && $queuedPost->scheduled_for?->equalTo($scheduledFor);
        })
        ->andThrow(new RuntimeException('Pulse scheduled queue preparation failed.'));
    $publishingService->shouldReceive('publishNow')->never();
    $approvalService = new SocialApprovalService(
        $publishingService,
        app(SocialPostRevisionService::class),
        app(\App\Services\Social\SocialScheduledTimeResolver::class),
    );
    $pendingPost = $draft->fresh([
        'targets.socialAccountConnection',
        'latestApprovalRequest.requestedBy',
        'latestApprovalRequest.resolvedBy',
    ]);

    expect(fn () => $approvalService->approve($owner, $approver, $pendingPost, [
        'mode' => 'scheduled',
        'scheduled_for' => $scheduledFor->toIso8601String(),
    ]))->toThrow(RuntimeException::class, 'Pulse scheduled queue preparation failed.');

    Queue::assertNothingPushed();

    expect($draft->fresh()->getAttributes())->toBe($postBeforeApproval)
        ->and($target->fresh()->getAttributes())->toBe($targetBeforeApproval)
        ->and($approvalRequest->fresh()->getAttributes())->toBe($approvalRequestBeforeApproval)
        ->and($draft->approvalRequests()->count())->toBe(1);
});

it('does not notify approvers when the surrounding submission transaction rolls back', function () {
    $owner = pulseApprovalOwner();
    $publisher = pulseApprovalTeamMember($owner, ['social.publish']);
    pulseApprovalTeamMember($owner, ['social.approve']);
    $connection = pulseApprovalConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $draft = pulseApprovalDraft($owner, $publisher, [$connection]);
    $postBeforeSubmission = $draft->fresh()->getAttributes();
    $target = $draft->targets()->sole();
    $targetBeforeSubmission = $target->getAttributes();
    $approvalService = app(SocialApprovalService::class);

    expect(fn () => DB::transaction(function () use ($approvalService, $owner, $publisher, $draft): void {
        $approvalService->submit($owner, $publisher, $draft);

        throw new RuntimeException('Rollback the outer Pulse submission transaction.');
    }))->toThrow(RuntimeException::class, 'Rollback the outer Pulse submission transaction.');

    Notification::assertNothingSent();

    expect($draft->fresh()->getAttributes())->toBe($postBeforeSubmission)
        ->and($target->fresh()->getAttributes())->toBe($targetBeforeSubmission)
        ->and($draft->approvalRequests()->count())->toBe(0);
});

it('marks unqueued draft and scheduled pulse posts as editable', function () {
    $owner = pulseApprovalOwner();
    $publisher = pulseApprovalTeamMember($owner, ['social.publish']);
    $connection = pulseApprovalConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $draft = pulseApprovalDraft($owner, $publisher, [$connection]);
    $scheduled = pulseApprovalDraft($owner, $publisher, [$connection], [
        'scheduled_for' => now()->addDays(2)->startOfHour(),
    ]);
    $postService = app(SocialPostService::class);
    $draftPayload = $postService->payload($draft);
    $scheduledPayload = $postService->payload($scheduled);

    expect($draftPayload['is_queued_publication'])->toBeFalse()
        ->and($draftPayload['is_editable'])->toBeTrue()
        ->and($scheduledPayload['is_queued_publication'])->toBeFalse()
        ->and($scheduledPayload['is_editable'])->toBeTrue();
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
