<?php

use App\Models\SocialAccountConnection;
use App\Models\SocialDeliveryOutbox;
use App\Models\SocialPost;
use App\Models\SocialPostRevision;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Services\Observability\ObservabilityReportService;
use App\Services\Social\SocialDeliveryHealthService;
use App\Services\Social\SocialDeliveryOutboxService;
use App\Services\Social\SocialPostRevisionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @return array{owner:User,outbox:SocialDeliveryOutbox,target:SocialPostTarget}
 */
function pulseDeliveryHealthFixture(string $content): array
{
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_timezone' => 'America/Toronto',
    ]);
    $externalAccountId = 'health-page-'.$owner->id;
    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Pulse health Facebook page',
        'external_account_id' => $externalAccountId,
        ...pulseDirectTransportIdentity(
            $owner,
            SocialAccountConnection::PLATFORM_FACEBOOK,
            $externalAccountId,
        ),
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
    ]);
    $post = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'content_payload' => ['text' => $content],
        'media_payload' => [],
        'status' => SocialPost::STATUS_DRAFT,
    ]);
    $target = SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'social_account_connection_id' => $connection->id,
        'delivery_provider' => $connection->delivery_provider,
        'transport_generation' => $connection->transport_generation,
        'logical_destination_key' => $connection->logical_destination_key,
        'status' => SocialPostTarget::STATUS_PENDING,
    ]);
    $revision = app(SocialPostRevisionService::class)->approveDirectly($post, $owner, now());
    $target->refresh()->forceFill([
        'last_submitted_revision_id' => $revision->id,
        'delivery_status' => SocialPost::DELIVERY_STATUS_QUEUED,
        'sync_status' => SocialPost::SYNC_STATUS_PENDING,
        'status' => SocialPostTarget::STATUS_PENDING,
    ])->save();
    $outbox = DB::transaction(fn (): SocialDeliveryOutbox => app(SocialDeliveryOutboxService::class)
        ->createForTarget(
            $owner,
            $target->fresh(),
            SocialPostRevision::query()->findOrFail($revision->id),
            $connection,
            [
                'post_id' => $post->id,
                'target_id' => $target->id,
                'revision_id' => $revision->id,
                'platform' => $connection->platform,
                'text' => $content,
            ],
            now(),
        ));

    return ['owner' => $owner, 'outbox' => $outbox, 'target' => $target->fresh()];
}

it('reports aggregate outbox health without exposing tenant or delivery identities', function () {
    $this->travelTo(Carbon::parse('2026-08-28 20:00:00', 'UTC'));
    $pending = pulseDeliveryHealthFixture('Oldest actionable health entry');
    $unknown = pulseDeliveryHealthFixture('Ambiguous health entry');
    $dead = pulseDeliveryHealthFixture('Terminal health entry');
    $expiredClaim = pulseDeliveryHealthFixture('Expired claim health entry');

    $pending['outbox']->forceFill(['available_at' => now()->subMinutes(15)])->save();
    $unknown['outbox']->forceFill([
        'status' => SocialDeliveryOutbox::STATUS_UNKNOWN,
        'processed_at' => now()->subMinute(),
    ])->save();
    $dead['outbox']->forceFill([
        'status' => SocialDeliveryOutbox::STATUS_DEAD,
        'processed_at' => now()->subMinute(),
    ])->save();
    $expiredClaim['outbox']->forceFill([
        'status' => SocialDeliveryOutbox::STATUS_CLAIMED,
        'attempts' => 1,
        'claimed_at' => now()->subMinutes(5),
        'claim_expires_at' => now()->subMinute(),
        'claimed_by' => 'health-test-worker',
        'claim_token' => 'b3be8067-1e7e-4e40-9481-9dafebf7e549',
        'claim_version' => 1,
    ])->save();

    $summary = app(SocialDeliveryHealthService::class)->summary();

    expect($summary)->toMatchArray([
        'total' => 4,
        'actionable' => 1,
        'oldest_actionable_age_seconds' => 900,
        'expired_claims' => 1,
        'aggregate_repairs_pending' => 2,
    ])->and($summary['status_counts'])->toBe([
        SocialDeliveryOutbox::STATUS_PENDING => 1,
        SocialDeliveryOutbox::STATUS_CLAIMED => 1,
        SocialDeliveryOutbox::STATUS_SUBMITTING => 0,
        SocialDeliveryOutbox::STATUS_RETRYABLE => 0,
        SocialDeliveryOutbox::STATUS_SUSPENDED => 0,
        SocialDeliveryOutbox::STATUS_UNKNOWN => 1,
        SocialDeliveryOutbox::STATUS_COMPLETED => 0,
        SocialDeliveryOutbox::STATUS_DEAD => 1,
    ])->and(array_sum($summary['status_counts']))->toBe($summary['total'])
        ->and($summary['active_status_counts'])->toBe([
            SocialDeliveryOutbox::STATUS_UNKNOWN => 1,
            SocialDeliveryOutbox::STATUS_DEAD => 1,
            SocialDeliveryOutbox::STATUS_SUSPENDED => 0,
        ])->and(array_keys($summary))->not->toContain(
            'user_id',
            'social_post_target_id',
            'provider_post_id',
            'payload',
        );
});

it('keeps tenant delivery health strictly scoped', function () {
    $this->travelTo(Carbon::parse('2026-08-28 20:00:00', 'UTC'));
    $firstTenant = pulseDeliveryHealthFixture('First tenant health entry');
    $secondTenant = pulseDeliveryHealthFixture('Second tenant health entry');
    $secondTenant['outbox']->forceFill([
        'status' => SocialDeliveryOutbox::STATUS_UNKNOWN,
        'processed_at' => now(),
    ])->save();

    $summary = app(SocialDeliveryHealthService::class)
        ->summaryForTenant((int) $firstTenant['owner']->id);

    expect($summary['total'])->toBe(1)
        ->and($summary['status_counts'][SocialDeliveryOutbox::STATUS_PENDING])->toBe(1)
        ->and($summary['status_counts'][SocialDeliveryOutbox::STATUS_UNKNOWN])->toBe(0)
        ->and($summary['active_status_counts'][SocialDeliveryOutbox::STATUS_UNKNOWN])->toBe(0)
        ->and(fn () => app(SocialDeliveryHealthService::class)->summaryForTenant(0))
        ->toThrow(InvalidArgumentException::class, 'tenant ID must be positive');
});

it('counts only the active dead operation across an explicit recovery chain', function () {
    $fixture = pulseDeliveryHealthFixture('Explicit recovery health entry');
    $fixture['outbox']->forceFill([
        'status' => SocialDeliveryOutbox::STATUS_DEAD,
        'processed_at' => now(),
    ])->save();
    $target = $fixture['target']->fresh();
    $revision = SocialPostRevision::query()->findOrFail($target->last_submitted_revision_id);
    $connection = SocialAccountConnection::query()->findOrFail(
        $target->social_account_connection_id,
    );
    $recovery = DB::transaction(fn (): SocialDeliveryOutbox => app(SocialDeliveryOutboxService::class)
        ->createForTarget(
            $fixture['owner'],
            $target,
            $revision,
            $connection,
            [
                'post_id' => $target->social_post_id,
                'target_id' => $target->id,
                'revision_id' => $revision->id,
                'platform' => $connection->platform,
                'text' => 'Explicit recovery health entry',
            ],
            now(),
            recoveryGeneration: 1,
            supersedes: $fixture['outbox'],
        ));
    $health = app(SocialDeliveryHealthService::class);

    $summary = $health->summaryForTenant($fixture['owner']->id);

    expect($summary['status_counts'][SocialDeliveryOutbox::STATUS_PENDING])->toBe(1)
        ->and($summary['status_counts'][SocialDeliveryOutbox::STATUS_DEAD])->toBe(1)
        ->and($summary['active_status_counts'][SocialDeliveryOutbox::STATUS_DEAD])->toBe(0);

    $recovery->forceFill([
        'status' => SocialDeliveryOutbox::STATUS_DEAD,
        'processed_at' => now(),
    ])->save();

    $summary = $health->summaryForTenant($fixture['owner']->id);

    expect($summary['status_counts'])->toMatchArray([
        SocialDeliveryOutbox::STATUS_PENDING => 0,
        SocialDeliveryOutbox::STATUS_DEAD => 2,
    ])->and($summary['active_status_counts'][SocialDeliveryOutbox::STATUS_DEAD])->toBe(1);
});

it('does not let a corrupted cross tenant successor hide an active dead delivery', function () {
    $firstTenant = pulseDeliveryHealthFixture('First tenant active dead entry');
    $secondTenant = pulseDeliveryHealthFixture('Cross tenant corrupted successor');
    $firstTenant['outbox']->forceFill([
        'status' => SocialDeliveryOutbox::STATUS_DEAD,
        'processed_at' => now(),
    ])->save();

    DB::table('social_delivery_outbox')
        ->where('id', $secondTenant['outbox']->id)
        ->update([
            'supersedes_outbox_id' => $firstTenant['outbox']->id,
            'recovery_generation' => 1,
        ]);

    expect(app(SocialDeliveryHealthService::class)->summaryForTenant(
        $firstTenant['owner']->id,
    )['active_status_counts'][SocialDeliveryOutbox::STATUS_DEAD])->toBe(1);
});

it('adds Pulse delivery alerts to the existing operational report', function () {
    $this->travelTo(Carbon::parse('2026-08-28 20:00:00', 'UTC'));
    $fixture = pulseDeliveryHealthFixture('Operational report ambiguous entry');
    $fixture['outbox']->forceFill([
        'status' => SocialDeliveryOutbox::STATUS_UNKNOWN,
        'processed_at' => now(),
    ])->save();
    config()->set('observability.enabled', true);
    config()->set('observability.cache.store', 'array');
    config()->set('observability.release', 'pulse-health-test');
    config()->set('observability.alerts.pulse_delivery_unknown', 0);

    $report = app(ObservabilityReportService::class)->summary();
    $pulseAlert = collect($report['alerts'])->firstWhere('code', 'pulse_delivery_unknown');

    expect($report['pulse_delivery']['status_counts'][SocialDeliveryOutbox::STATUS_UNKNOWN])->toBe(1)
        ->and($report['pulse_delivery']['active_status_counts'][SocialDeliveryOutbox::STATUS_UNKNOWN])->toBe(1)
        ->and($pulseAlert)->not->toBeNull()
        ->and($pulseAlert['severity'])->toBe('critical')
        ->and(json_encode($report, JSON_THROW_ON_ERROR))
        ->not->toContain('Operational report ambiguous entry');
});

it('reports stopped reconciliation without exposing the remote identity', function () {
    $this->travelTo(Carbon::parse('2026-08-28 20:00:00', 'UTC'));
    $fixture = pulseDeliveryHealthFixture('Stopped reconciliation entry');
    $fixture['target']->forceFill([
        'delivery_status' => SocialPost::DELIVERY_STATUS_UNKNOWN,
        'sync_status' => SocialPost::SYNC_STATUS_ERROR,
        'provider_post_id' => null,
        'next_reconcile_at' => null,
        'provider_error_code' => 'remote_identifier_missing',
        'provider_error_message' => 'Operator review is required.',
    ])->save();
    config()->set('observability.enabled', true);
    config()->set('observability.cache.store', 'array');
    config()->set('observability.alerts.pulse_reconciliation_operator_review', 0);

    $summary = app(SocialDeliveryHealthService::class)->summaryForTenant(
        (int) $fixture['owner']->id,
    );
    $report = app(ObservabilityReportService::class)->summary();
    $encoded = json_encode($report, JSON_THROW_ON_ERROR);

    expect($summary['reconciliation'])->toBe([
        'due' => 0,
        'expired_claims' => 0,
        'operator_review' => 1,
        'unknown_without_remote_identity' => 1,
    ])->and(collect($report['alerts'])->contains(
        fn (array $alert): bool => $alert['code'] === 'pulse_reconciliation_operator_review',
    ))->toBeTrue()
        ->and($encoded)->not->toContain('Stopped reconciliation entry')
        ->and($encoded)->not->toContain('remote_identifier_missing')
        ->and($encoded)->not->toContain('Operator review is required.');
});
