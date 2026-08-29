<?php

namespace App\Services\Social;

use App\Models\SocialDeliveryOutbox;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class SocialDeliveryHealthService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return $this->summarize(
            SocialDeliveryOutbox::query(),
            SocialPostTarget::query(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryForTenant(int $tenantId): array
    {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('The Pulse delivery health tenant ID must be positive.');
        }

        return $this->summarize(
            SocialDeliveryOutbox::query()->where('user_id', $tenantId),
            SocialPostTarget::query()->whereHas(
                'socialPost',
                fn (Builder $query): Builder => $query->where('user_id', $tenantId),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(Builder $query, Builder $targetQuery): array
    {
        $now = CarbonImmutable::now('UTC');
        $countsQuery = clone $query;
        $counts = $countsQuery
            ->toBase()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw(
                'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS pending',
                [SocialDeliveryOutbox::STATUS_PENDING],
            )
            ->selectRaw(
                'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS claimed',
                [SocialDeliveryOutbox::STATUS_CLAIMED],
            )
            ->selectRaw(
                'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS submitting',
                [SocialDeliveryOutbox::STATUS_SUBMITTING],
            )
            ->selectRaw(
                'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS retryable',
                [SocialDeliveryOutbox::STATUS_RETRYABLE],
            )
            ->selectRaw(
                'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS suspended',
                [SocialDeliveryOutbox::STATUS_SUSPENDED],
            )
            ->selectRaw(
                'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS unknown',
                [SocialDeliveryOutbox::STATUS_UNKNOWN],
            )
            ->selectRaw(
                'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS completed',
                [SocialDeliveryOutbox::STATUS_COMPLETED],
            )
            ->selectRaw(
                'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS dead',
                [SocialDeliveryOutbox::STATUS_DEAD],
            )
            ->first();
        $activeUnknown = (clone $query)
            ->where('status', SocialDeliveryOutbox::STATUS_UNKNOWN)
            ->whereNull('reconciliation_resolved_at')
            ->count();
        $activeDead = (clone $query)
            ->where(function (Builder $query): void {
                $query
                    ->where(function (Builder $query): void {
                        $query
                            ->where('status', SocialDeliveryOutbox::STATUS_DEAD)
                            ->where(function (Builder $query): void {
                                $query
                                    ->whereNull('reconciliation_resolved_at')
                                    ->orWhere('reconciliation_resolution', SocialDeliveryOutbox::RECONCILIATION_RESOLUTION_ERROR);
                            });
                    })
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->where('status', SocialDeliveryOutbox::STATUS_UNKNOWN)
                            ->where('reconciliation_resolution', SocialDeliveryOutbox::RECONCILIATION_RESOLUTION_ERROR)
                            ->where('reconciliation_resolution_source', SocialDeliveryOutbox::RECONCILIATION_SOURCE_STATUS_READ);
                    });
            })
            ->whereRaw(
                'NOT EXISTS (SELECT 1 FROM social_delivery_outbox AS recovery_outbox WHERE recovery_outbox.supersedes_outbox_id = social_delivery_outbox.id AND recovery_outbox.user_id = social_delivery_outbox.user_id)',
            )
            ->count();

        $actionableQuery = (clone $query)
            ->whereIn('status', [
                SocialDeliveryOutbox::STATUS_PENDING,
                SocialDeliveryOutbox::STATUS_RETRYABLE,
            ])
            ->whereNull('request_started_at')
            ->where('available_at', '<=', $now);
        $actionable = (clone $actionableQuery)->count();
        $oldestActionableAt = $actionableQuery
            ->oldest('available_at')
            ->value('available_at');
        $expiredClaims = (clone $query)
            ->whereIn('status', [
                SocialDeliveryOutbox::STATUS_CLAIMED,
                SocialDeliveryOutbox::STATUS_SUBMITTING,
            ])
            ->whereNotNull('claim_expires_at')
            ->where('claim_expires_at', '<=', $now)
            ->count();
        $aggregateRepairsPending = (clone $query)
            ->whereIn('status', [
                SocialDeliveryOutbox::STATUS_UNKNOWN,
                SocialDeliveryOutbox::STATUS_COMPLETED,
                SocialDeliveryOutbox::STATUS_DEAD,
            ])
            ->where(function (Builder $query): void {
                $query
                    ->where('status', SocialDeliveryOutbox::STATUS_COMPLETED)
                    ->orWhereNull('reconciliation_resolved_at');
            })
            ->whereNull('aggregate_repaired_at')
            ->count();
        $reconciliationDue = (clone $targetQuery)
            ->whereNotNull('next_reconcile_at')
            ->where('next_reconcile_at', '<=', $now)
            ->where(function (Builder $query) use ($now): void {
                $query
                    ->whereNull('reconcile_claim_expires_at')
                    ->orWhere('reconcile_claim_expires_at', '<=', $now);
            })
            ->count();
        $expiredReconciliationClaims = (clone $targetQuery)
            ->whereNotNull('reconcile_claim_expires_at')
            ->where('reconcile_claim_expires_at', '<=', $now)
            ->count();
        $operatorReview = (clone $targetQuery)
            ->whereNull('next_reconcile_at')
            ->where(function (Builder $query): void {
                $query
                    ->where('delivery_status', SocialPost::DELIVERY_STATUS_UNKNOWN)
                    ->orWhere('delivery_status', SocialPost::DELIVERY_STATUS_REMOTE_APPROVAL_REQUIRED)
                    ->orWhere('delivery_status', 'sending')
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->where('sync_status', SocialPost::SYNC_STATUS_ERROR)
                            ->whereNotIn('delivery_status', [
                                SocialPost::DELIVERY_STATUS_PUBLISHED,
                                SocialPost::DELIVERY_STATUS_FAILED,
                                SocialPost::DELIVERY_STATUS_CANCELED,
                            ]);
                    });
            })
            ->count();
        $unknownWithoutRemoteIdentity = (clone $targetQuery)
            ->where('delivery_status', SocialPost::DELIVERY_STATUS_UNKNOWN)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('provider_post_id')
                    ->orWhere('provider_post_id', '');
            })
            ->count();

        $statusCounts = collect(SocialDeliveryOutbox::allowedStatuses())
            ->mapWithKeys(fn (string $status): array => [
                $status => (int) ($counts->{$status} ?? 0),
            ])
            ->all();
        $oldestActionable = $oldestActionableAt === null
            ? null
            : CarbonImmutable::parse($oldestActionableAt)->utc();

        return [
            'total' => (int) ($counts->total ?? 0),
            'status_counts' => $statusCounts,
            'active_status_counts' => [
                SocialDeliveryOutbox::STATUS_UNKNOWN => $activeUnknown,
                SocialDeliveryOutbox::STATUS_DEAD => $activeDead,
                SocialDeliveryOutbox::STATUS_SUSPENDED => (clone $query)
                    ->where('status', SocialDeliveryOutbox::STATUS_SUSPENDED)
                    ->count(),
            ],
            'actionable' => $actionable,
            'oldest_actionable_age_seconds' => $oldestActionable === null
                ? null
                : max(0, (int) $oldestActionable->diffInSeconds($now)),
            'expired_claims' => $expiredClaims,
            'aggregate_repairs_pending' => $aggregateRepairsPending,
            'reconciliation' => [
                'due' => $reconciliationDue,
                'expired_claims' => $expiredReconciliationClaims,
                'operator_review' => $operatorReview,
                'unknown_without_remote_identity' => $unknownWithoutRemoteIdentity,
            ],
        ];
    }
}
