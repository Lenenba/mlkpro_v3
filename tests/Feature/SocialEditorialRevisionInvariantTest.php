<?php

use App\Models\SocialAccountConnection;
use App\Models\SocialApprovalRequest;
use App\Models\SocialPost;
use App\Models\SocialPostRevision;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Services\Social\SocialPostRevisionService;
use Illuminate\Support\Carbon;

function pulseRevisionPost(User $owner): array
{
    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Revision test page',
        'external_account_id' => 'revision-page-'.$owner->id,
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
    ]);
    $post = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'source_type' => 'service',
        'source_id' => 73,
        'content_payload' => ['text' => 'Version one'],
        'media_payload' => [],
        'link_url' => 'https://example.test/service',
        'status' => SocialPost::STATUS_DRAFT,
        'metadata' => ['link_cta_label' => 'Réserver'],
    ]);
    $target = SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'social_account_connection_id' => $connection->id,
        'status' => SocialPostTarget::STATUS_PENDING,
    ]);

    return [$post, $target];
}

it('keeps a submitted revision immutable when a new revision becomes current', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_timezone' => 'America/Toronto',
    ]);
    [$post, $target] = pulseRevisionPost($owner);
    $revisions = app(SocialPostRevisionService::class);
    $firstRevision = $revisions->capture($post, $owner);
    $revisions->approveDirectly($post, $owner, now());

    $target->refresh()->forceFill([
        'last_submitted_revision_id' => $firstRevision->id,
        'delivery_status' => SocialPost::DELIVERY_STATUS_PUBLISHED,
        'sync_status' => SocialPost::SYNC_STATUS_SYNCED,
        'status' => SocialPostTarget::STATUS_PUBLISHED,
        'published_at' => now(),
    ])->save();
    $post->forceFill([
        'content_payload' => ['text' => 'Version two'],
        'updated_by_user_id' => $owner->id,
    ])->save();

    $secondRevision = $revisions->capture($post, $owner);
    $freshTarget = $target->fresh();

    expect($secondRevision->revision_number)->toBe(2)
        ->and($secondRevision->payload_hash)->not->toBe($firstRevision->payload_hash)
        ->and($freshTarget?->id)->toBe($target->id)
        ->and($freshTarget?->current_revision_id)->toBe($secondRevision->id)
        ->and($freshTarget?->last_submitted_revision_id)->toBe($firstRevision->id)
        ->and($freshTarget?->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_NOT_SUBMITTED)
        ->and($freshTarget?->payload_hash)->toBe($secondRevision->payload_hash)
        ->and($post->current_editorial_revision)->toBe(2)
        ->and($post->approved_revision_id)->toBeNull();

    $firstRevision->forceFill(['base_content' => ['text' => 'tampered']]);

    expect(fn () => $firstRevision->save())
        ->toThrow(\LogicException::class, 'snapshot is immutable');
});

it('rejects revision actors from another workspace without writing a revision', function () {
    $owner = User::factory()->create(['company_type' => 'services']);
    $otherOwner = User::factory()->create(['company_type' => 'services']);
    [$post] = pulseRevisionPost($owner);

    expect(fn () => app(SocialPostRevisionService::class)->capture($post, $otherOwner))
        ->toThrow(\LogicException::class, 'actor must belong to the post workspace');

    expect(SocialPostRevision::query()->where('social_post_id', $post->id)->count())->toBe(0);
});

it('rejects an approval actor from another workspace', function () {
    $owner = User::factory()->create(['company_type' => 'services']);
    $otherOwner = User::factory()->create(['company_type' => 'services']);
    [$post] = pulseRevisionPost($owner);
    $revision = app(SocialPostRevisionService::class)->capture($post, $owner);

    expect(fn () => SocialApprovalRequest::query()->create([
        'social_post_id' => $post->id,
        'social_post_revision_id' => $revision->id,
        'requested_by_user_id' => $otherOwner->id,
        'status' => SocialApprovalRequest::STATUS_PENDING,
        'requested_at' => Carbon::parse('2026-08-28 12:00:00'),
    ]))->toThrow(\LogicException::class, 'approval actor must belong to its workspace');

    expect(SocialApprovalRequest::query()->where('social_post_id', $post->id)->count())->toBe(0);
});
