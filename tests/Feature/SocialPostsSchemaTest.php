<?php

use App\Models\SocialAccountConnection;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds pulse social posts and targets tables with expected columns', function () {
    expect(Schema::hasTable('social_posts'))->toBeTrue()
        ->and(Schema::hasColumns('social_posts', [
            'user_id',
            'created_by_user_id',
            'updated_by_user_id',
            'source_type',
            'source_id',
            'content_payload',
            'media_payload',
            'link_url',
            'status',
            'scheduled_for',
            'published_at',
            'failed_at',
            'failure_reason',
            'metadata',
        ]))->toBeTrue()
        ->and(Schema::hasTable('social_post_targets'))->toBeTrue()
        ->and(Schema::hasColumns('social_post_targets', [
            'social_post_id',
            'social_account_connection_id',
            'status',
            'published_at',
            'failed_at',
            'failure_reason',
            'metadata',
        ]))->toBeTrue();
});

it('persists and casts pulse social post and target fields', function () {
    $owner = User::factory()->create(['company_type' => 'services']);
    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'North page',
        'display_name' => 'Malikia North',
        'external_account_id' => 'fb-page-001',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
    ]);

    $scheduledFor = Carbon::parse('2026-04-23 14:30:00');
    $publishedAt = Carbon::parse('2026-04-23 15:10:00');

    $post = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'source_type' => 'promotion',
        'source_id' => 42,
        'content_payload' => [
            'text' => 'Spring sale this week.',
            'caption' => 'Save 20 percent on selected services.',
        ],
        'media_payload' => [
            [
                'disk' => 'public',
                'path' => 'social/promo-spring-sale.png',
            ],
        ],
        'link_url' => 'https://example.com/offers/spring-sale',
        'status' => SocialPost::STATUS_SCHEDULED,
        'scheduled_for' => $scheduledFor,
        'metadata' => [
            'origin' => 'pulse_manual',
            'target_count' => 1,
        ],
    ]);

    $target = SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'social_account_connection_id' => $connection->id,
        'status' => SocialPostTarget::STATUS_PUBLISHED,
        'published_at' => $publishedAt,
        'metadata' => [
            'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
            'snapshot_label' => 'North page',
        ],
    ]);

    $freshPost = $post->fresh()->load(['targets', 'user', 'createdBy', 'updatedBy']);
    $freshTarget = $target->fresh()->load(['socialPost', 'socialAccountConnection']);

    expect($freshPost)->not->toBeNull()
        ->and($freshPost->source_type)->toBe('promotion')
        ->and($freshPost->source_id)->toBe(42)
        ->and($freshPost->content_payload)->toBe([
            'text' => 'Spring sale this week.',
            'caption' => 'Save 20 percent on selected services.',
        ])
        ->and($freshPost->media_payload)->toBe([
            [
                'disk' => 'public',
                'path' => 'social/promo-spring-sale.png',
            ],
        ])
        ->and($freshPost->link_url)->toBe('https://example.com/offers/spring-sale')
        ->and($freshPost->status)->toBe(SocialPost::STATUS_SCHEDULED)
        ->and($freshPost->scheduled_for)->toBeInstanceOf(Carbon::class)
        ->and($freshPost->scheduled_for?->equalTo($scheduledFor))->toBeTrue()
        ->and($freshPost->metadata)->toBe([
            'origin' => 'pulse_manual',
            'target_count' => 1,
        ])
        ->and($freshPost->targets)->toHaveCount(1)
        ->and($freshPost->targets->first()?->is($target))->toBeTrue()
        ->and($freshPost->user->is($owner))->toBeTrue()
        ->and($freshPost->createdBy->is($owner))->toBeTrue()
        ->and($freshPost->updatedBy->is($owner))->toBeTrue()
        ->and($owner->socialPosts->first()?->is($post))->toBeTrue()
        ->and($freshTarget)->not->toBeNull()
        ->and($freshTarget->status)->toBe(SocialPostTarget::STATUS_PUBLISHED)
        ->and($freshTarget->published_at)->toBeInstanceOf(Carbon::class)
        ->and($freshTarget->published_at?->equalTo($publishedAt))->toBeTrue()
        ->and($freshTarget->metadata)->toBe([
            'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
            'snapshot_label' => 'North page',
        ])
        ->and($freshTarget->socialPost->is($post))->toBeTrue()
        ->and($freshTarget->socialAccountConnection->is($connection))->toBeTrue()
        ->and($connection->socialPostTargets->first()?->is($target))->toBeTrue();
});

it('supports multi-target pulse posts with independent target statuses', function () {
    $owner = User::factory()->create(['company_type' => 'services']);

    $facebook = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'North page',
        'external_account_id' => 'fb-page-001',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
    ]);

    $linkedin = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_LINKEDIN,
        'label' => 'Corporate page',
        'external_account_id' => 'li-org-009',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
    ]);

    $post = SocialPost::query()->create([
        'user_id' => $owner->id,
        'status' => SocialPost::STATUS_PARTIAL_FAILED,
        'content_payload' => [
            'text' => 'New service launch available now.',
        ],
    ]);

    $publishedTarget = SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'social_account_connection_id' => $facebook->id,
        'status' => SocialPostTarget::STATUS_PUBLISHED,
        'published_at' => now(),
    ]);

    $failedTarget = SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'social_account_connection_id' => $linkedin->id,
        'status' => SocialPostTarget::STATUS_FAILED,
        'failed_at' => now(),
        'failure_reason' => 'provider_rejected_media',
    ]);

    $freshPost = $post->fresh()->load('targets.socialAccountConnection');

    expect($freshPost->status)->toBe(SocialPost::STATUS_PARTIAL_FAILED)
        ->and($freshPost->targets)->toHaveCount(2)
        ->and($freshPost->targets->pluck('status')->all())->toBe([
            SocialPostTarget::STATUS_PUBLISHED,
            SocialPostTarget::STATUS_FAILED,
        ])
        ->and($freshPost->targets->pluck('socialAccountConnection.platform')->all())->toBe([
            SocialAccountConnection::PLATFORM_FACEBOOK,
            SocialAccountConnection::PLATFORM_LINKEDIN,
        ])
        ->and($publishedTarget->socialPost->is($post))->toBeTrue()
        ->and($failedTarget->socialPost->is($post))->toBeTrue();
});

it('prevents duplicate targets for the same connection on one pulse post', function () {
    $owner = User::factory()->create(['company_type' => 'services']);
    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_X,
        'label' => 'Launch profile',
        'external_account_id' => 'x-profile-001',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
    ]);

    $post = SocialPost::query()->create([
        'user_id' => $owner->id,
        'status' => SocialPost::STATUS_DRAFT,
    ]);

    SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'social_account_connection_id' => $connection->id,
        'status' => SocialPostTarget::STATUS_PENDING,
    ]);

    expect(fn () => SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'social_account_connection_id' => $connection->id,
        'status' => SocialPostTarget::STATUS_PENDING,
    ]))->toThrow(QueryException::class);
});
