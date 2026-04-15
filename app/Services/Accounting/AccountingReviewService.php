<?php

namespace App\Services\Accounting;

use App\Models\AccountingEntry;
use App\Models\AccountingEntryBatch;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AccountingReviewService
{
    public function __construct(
        private readonly AccountingReadService $readService
    ) {}

    public function transitionEntry(User $actor, int $accountId, AccountingEntry $entry, string $targetStatus): AccountingEntry
    {
        $this->guardOwnership($accountId, (int) $entry->user_id);
        $this->guardStatus($targetStatus);

        $entry->update($this->entryPayload($actor, $entry->meta ?? [], $targetStatus));
        $entry->refresh();

        if ($entry->batch) {
            $this->syncBatchReviewStatus($entry->batch->fresh(['entries']));
        }

        ActivityLog::record(
            $actor,
            $entry,
            'accounting.entry.review_status_changed',
            [
                'to' => $targetStatus,
                'batch_id' => $entry->batch_id,
            ],
            'Accounting entry review status updated'
        );

        return $entry->fresh(['account', 'batch']);
    }

    public function transitionBatch(User $actor, int $accountId, AccountingEntryBatch $batch, string $targetStatus): AccountingEntryBatch
    {
        $this->guardOwnership($accountId, (int) $batch->user_id);
        $this->guardStatus($targetStatus);

        $batch->loadMissing('entries');

        foreach ($batch->entries as $entry) {
            $entry->update($this->entryPayload($actor, $entry->meta ?? [], $targetStatus));
        }

        $meta = $this->batchMetaForStatus($actor, $batch->meta ?? [], $targetStatus);
        $batch->update(['meta' => $meta]);

        ActivityLog::record(
            $actor,
            $batch,
            'accounting.batch.review_status_changed',
            [
                'to' => $targetStatus,
                'entry_count' => $batch->entries->count(),
            ],
            'Accounting batch review status updated'
        );

        return $batch->fresh(['entries']);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function workspace(int $accountId, array $filters): array
    {
        $entries = $this->readService->query($accountId, $filters)
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->get();

        $batches = $entries
            ->groupBy('batch_id')
            ->map(function (Collection $group): array {
                /** @var \App\Models\AccountingEntry $first */
                $first = $group->first();
                $batch = $first->batch;
                $reviewStatus = $this->batchReviewStatus($batch, $group);

                return [
                    'id' => $batch?->id,
                    'entry_date' => optional($batch?->entry_date)->toDateString(),
                    'source_type' => $batch?->source_type,
                    'source_event_key' => $batch?->source_event_key,
                    'source_reference' => $batch?->source_reference,
                    'source_url' => data_get($batch?->meta, 'source_url'),
                    'status' => $batch?->status,
                    'review_status' => $reviewStatus,
                    'entry_count' => $group->count(),
                    'unreviewed_entry_count' => $group->where('review_status', AccountingEntry::REVIEW_STATUS_UNREVIEWED)->count(),
                    'reviewed_entry_count' => $group->where('review_status', AccountingEntry::REVIEW_STATUS_REVIEWED)->count(),
                    'reconciled_entry_count' => $group->where('review_status', AccountingEntry::REVIEW_STATUS_RECONCILED)->count(),
                    'debit_total' => round((float) $group->where('direction', AccountingEntry::DIRECTION_DEBIT)->sum('amount'), 2),
                    'credit_total' => round((float) $group->where('direction', AccountingEntry::DIRECTION_CREDIT)->sum('amount'), 2),
                    'tax_total' => round((float) $group->sum('tax_amount'), 2),
                    'actions' => [
                        'mark_unreviewed' => $reviewStatus !== AccountingEntry::REVIEW_STATUS_UNREVIEWED,
                        'mark_reviewed' => in_array($reviewStatus, [AccountingEntry::REVIEW_STATUS_UNREVIEWED, AccountingEntry::REVIEW_STATUS_RECONCILED], true),
                        'mark_reconciled' => $reviewStatus !== AccountingEntry::REVIEW_STATUS_RECONCILED,
                    ],
                ];
            })
            ->filter(fn (array $batch): bool => ($batch['status'] ?? null) === AccountingEntryBatch::STATUS_REVIEW_REQUIRED
                || ($batch['review_status'] ?? AccountingEntry::REVIEW_STATUS_UNREVIEWED) !== AccountingEntry::REVIEW_STATUS_RECONCILED)
            ->sortByDesc('entry_date')
            ->values()
            ->take(8)
            ->all();

        return [
            'entry_status_counts' => [
                'unreviewed' => $entries->where('review_status', AccountingEntry::REVIEW_STATUS_UNREVIEWED)->count(),
                'reviewed' => $entries->where('review_status', AccountingEntry::REVIEW_STATUS_REVIEWED)->count(),
                'reconciled' => $entries->where('review_status', AccountingEntry::REVIEW_STATUS_RECONCILED)->count(),
            ],
            'pending_batch_count' => count($batches),
            'batches' => $batches,
        ];
    }

    public function batchReviewStatus(?AccountingEntryBatch $batch, ?Collection $entries = null): string
    {
        $metaStatus = data_get($batch?->meta, 'review_status');
        if (in_array($metaStatus, self::statuses(), true)) {
            return $metaStatus;
        }

        $entries ??= $batch?->entries ?? collect();

        if ($entries->isEmpty()) {
            return AccountingEntry::REVIEW_STATUS_UNREVIEWED;
        }

        if ($entries->every(fn (AccountingEntry $entry): bool => $entry->review_status === AccountingEntry::REVIEW_STATUS_RECONCILED)) {
            return AccountingEntry::REVIEW_STATUS_RECONCILED;
        }

        if ($entries->every(fn (AccountingEntry $entry): bool => in_array($entry->review_status, [AccountingEntry::REVIEW_STATUS_REVIEWED, AccountingEntry::REVIEW_STATUS_RECONCILED], true))) {
            return AccountingEntry::REVIEW_STATUS_REVIEWED;
        }

        return AccountingEntry::REVIEW_STATUS_UNREVIEWED;
    }

    public function syncBatchReviewStatus(AccountingEntryBatch $batch): void
    {
        $batch->loadMissing('entries');
        $status = $this->batchReviewStatus($batch, $batch->entries);
        $batch->update([
            'meta' => $this->batchMetaForStatus(null, $batch->meta ?? [], $status),
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function batchMetaForStatus(?User $actor, array $meta, string $status): array
    {
        $payload = [
            'review_status' => $status,
        ];

        if ($status === AccountingEntry::REVIEW_STATUS_REVIEWED) {
            $payload['reviewed_at'] = now()->toIso8601String();
            $payload['reviewed_by'] = $actor?->id;
        }

        if ($status === AccountingEntry::REVIEW_STATUS_RECONCILED) {
            $payload['reviewed_at'] = data_get($meta, 'reviewed_at') ?: now()->toIso8601String();
            $payload['reviewed_by'] = data_get($meta, 'reviewed_by') ?: $actor?->id;
            $payload['reconciled_at'] = now()->toIso8601String();
            $payload['reconciled_by'] = $actor?->id;
        }

        if ($status === AccountingEntry::REVIEW_STATUS_UNREVIEWED) {
            unset($meta['reviewed_at'], $meta['reviewed_by'], $meta['reconciled_at'], $meta['reconciled_by']);
        }

        return array_merge($meta, $payload);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function entryPayload(User $actor, array $meta, string $targetStatus): array
    {
        $payload = [
            'review_status' => $targetStatus,
            'reconciliation_status' => $targetStatus,
        ];

        if ($targetStatus === AccountingEntry::REVIEW_STATUS_REVIEWED) {
            $payload['meta'] = array_merge($meta, [
                'reviewed_at' => now()->toIso8601String(),
                'reviewed_by' => $actor->id,
            ]);

            return $payload;
        }

        if ($targetStatus === AccountingEntry::REVIEW_STATUS_RECONCILED) {
            $payload['meta'] = array_merge($meta, [
                'reviewed_at' => data_get($meta, 'reviewed_at') ?: now()->toIso8601String(),
                'reviewed_by' => data_get($meta, 'reviewed_by') ?: $actor->id,
                'reconciled_at' => now()->toIso8601String(),
                'reconciled_by' => $actor->id,
            ]);

            return $payload;
        }

        unset($meta['reviewed_at'], $meta['reviewed_by'], $meta['reconciled_at'], $meta['reconciled_by']);
        $payload['meta'] = $meta;

        return $payload;
    }

    private function guardOwnership(int $accountId, int $recordAccountId): void
    {
        if ($accountId !== $recordAccountId) {
            abort(404);
        }
    }

    private function guardStatus(string $targetStatus): void
    {
        if (! in_array($targetStatus, self::statuses(), true)) {
            throw ValidationException::withMessages([
                'status' => 'Invalid accounting review status.',
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private static function statuses(): array
    {
        return [
            AccountingEntry::REVIEW_STATUS_UNREVIEWED,
            AccountingEntry::REVIEW_STATUS_REVIEWED,
            AccountingEntry::REVIEW_STATUS_RECONCILED,
        ];
    }
}
