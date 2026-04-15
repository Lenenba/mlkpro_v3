<?php

namespace App\Services\Accounting;

use App\Models\AccountingEntry;
use App\Models\AccountingEntryBatch;
use App\Models\AccountingPeriod;
use App\Models\ActivityLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class AccountingPeriodService
{
    /**
     * @var array<int, array<string, string>>
     */
    private array $statusCache = [];

    /**
     * @return array<string, mixed>
     */
    public function timeline(int $accountId, ?string $selectedPeriodKey = null, int $months = 6): array
    {
        $selectedPeriodKey = $this->normalizePeriodKey($selectedPeriodKey);
        $range = $this->periodKeys($accountId, $selectedPeriodKey, $months);
        $storedPeriods = AccountingPeriod::query()
            ->forUser($accountId)
            ->whereIn('period_key', $range)
            ->with(['closedBy:id,name', 'reopenedBy:id,name'])
            ->get()
            ->keyBy('period_key');

        $oldestKey = collect($range)->last();
        $latestKey = collect($range)->first();
        $entryAggregates = $this->entryAggregates($accountId, $oldestKey, $latestKey);
        $batchAggregates = $this->batchAggregates($accountId, $oldestKey, $latestKey);

        $periods = collect($range)
            ->map(function (string $periodKey) use ($storedPeriods, $entryAggregates, $batchAggregates, $selectedPeriodKey): array {
                /** @var \App\Models\AccountingPeriod|null $period */
                $period = $storedPeriods->get($periodKey);
                $start = Carbon::parse($periodKey.'-01')->startOfMonth();
                $end = (clone $start)->endOfMonth();
                $entryAggregate = $entryAggregates[$periodKey] ?? [
                    'entry_count' => 0,
                    'debit_total' => 0.0,
                    'credit_total' => 0.0,
                ];

                return [
                    'period_key' => $periodKey,
                    'label' => $start->translatedFormat('F Y'),
                    'status' => $period?->status ?? AccountingPeriod::STATUS_OPEN,
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'entry_count' => (int) ($entryAggregate['entry_count'] ?? 0),
                    'batch_count' => (int) ($batchAggregates[$periodKey] ?? 0),
                    'debit_total' => round((float) ($entryAggregate['debit_total'] ?? 0), 2),
                    'credit_total' => round((float) ($entryAggregate['credit_total'] ?? 0), 2),
                    'closed_at' => optional($period?->closed_at)->toIso8601String(),
                    'closed_by_name' => $period?->closedBy?->name,
                    'reopened_at' => optional($period?->reopened_at)->toIso8601String(),
                    'reopened_by_name' => $period?->reopenedBy?->name,
                    'is_current_filter' => $selectedPeriodKey === $periodKey,
                    'is_locked' => ($period?->status ?? AccountingPeriod::STATUS_OPEN) === AccountingPeriod::STATUS_CLOSED,
                    'actions' => $this->availableActions($period?->status ?? AccountingPeriod::STATUS_OPEN),
                ];
            })
            ->values()
            ->all();

        return [
            'periods' => $periods,
            'summary' => [
                'total' => count($periods),
                'open_count' => collect($periods)->where('status', AccountingPeriod::STATUS_OPEN)->count(),
                'in_review_count' => collect($periods)->where('status', AccountingPeriod::STATUS_IN_REVIEW)->count(),
                'closed_count' => collect($periods)->where('status', AccountingPeriod::STATUS_CLOSED)->count(),
                'reopened_count' => collect($periods)->where('status', AccountingPeriod::STATUS_REOPENED)->count(),
                'current_period_key' => Carbon::now()->format('Y-m'),
                'selected_period_key' => $selectedPeriodKey,
            ],
        ];
    }

    public function isClosedForDate(int $accountId, string $date): bool
    {
        $periodKey = Carbon::parse($date)->format('Y-m');

        if (! array_key_exists($accountId, $this->statusCache)) {
            $this->statusCache[$accountId] = AccountingPeriod::query()
                ->forUser($accountId)
                ->pluck('status', 'period_key')
                ->all();
        }

        return ($this->statusCache[$accountId][$periodKey] ?? AccountingPeriod::STATUS_OPEN) === AccountingPeriod::STATUS_CLOSED;
    }

    public function transition(User $actor, int $accountId, string $periodKey, string $targetStatus): AccountingPeriod
    {
        $normalizedPeriodKey = $this->normalizePeriodKey($periodKey);
        if (! $normalizedPeriodKey) {
            throw ValidationException::withMessages([
                'period' => 'A valid accounting period is required.',
            ]);
        }

        if (! in_array($targetStatus, [
            AccountingPeriod::STATUS_OPEN,
            AccountingPeriod::STATUS_IN_REVIEW,
            AccountingPeriod::STATUS_CLOSED,
            AccountingPeriod::STATUS_REOPENED,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => 'Invalid accounting period status.',
            ]);
        }

        [$startDate, $endDate] = $this->periodRange($normalizedPeriodKey);
        $period = AccountingPeriod::query()->firstOrCreate(
            [
                'user_id' => $accountId,
                'period_key' => $normalizedPeriodKey,
            ],
            [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => AccountingPeriod::STATUS_OPEN,
            ]
        );

        $fromStatus = $period->status;
        if ($fromStatus === $targetStatus) {
            return $period->fresh(['closedBy:id,name', 'reopenedBy:id,name']);
        }

        if (! $this->canTransition($fromStatus, $targetStatus)) {
            throw ValidationException::withMessages([
                'status' => 'This accounting period cannot move to the requested state.',
            ]);
        }

        $updates = [
            'status' => $targetStatus,
        ];

        if ($targetStatus === AccountingPeriod::STATUS_CLOSED) {
            $updates['closed_at'] = now();
            $updates['closed_by'] = $actor->id;
        }

        if ($targetStatus === AccountingPeriod::STATUS_REOPENED) {
            $updates['reopened_at'] = now();
            $updates['reopened_by'] = $actor->id;
        }

        $period->update($updates);
        unset($this->statusCache[$accountId]);

        ActivityLog::record(
            $actor,
            $period,
            'accounting.period.status_changed',
            [
                'period_key' => $normalizedPeriodKey,
                'from' => $fromStatus,
                'to' => $targetStatus,
            ],
            'Accounting period status updated'
        );

        return $period->fresh(['closedBy:id,name', 'reopenedBy:id,name']);
    }

    public function forgetStatusCache(int $accountId): void
    {
        unset($this->statusCache[$accountId]);
    }

    public function normalizePeriodKey(?string $periodKey): ?string
    {
        return is_string($periodKey) && preg_match('/^\d{4}-\d{2}$/', $periodKey)
            ? $periodKey
            : null;
    }

    /**
     * @return array<int, string>
     */
    private function periodKeys(int $accountId, ?string $selectedPeriodKey, int $months): array
    {
        $latestEntryDate = AccountingEntryBatch::query()
            ->forUser($accountId)
            ->max('entry_date');
        $anchor = $latestEntryDate
            ? Carbon::parse($latestEntryDate)->startOfMonth()
            : Carbon::now()->startOfMonth();

        $keys = [];
        for ($i = 0; $i < $months; $i++) {
            $keys[] = (clone $anchor)->subMonths($i)->format('Y-m');
        }

        if ($selectedPeriodKey && ! in_array($selectedPeriodKey, $keys, true)) {
            $keys[] = $selectedPeriodKey;
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<string, array<string, int|float|string>>
     */
    private function entryAggregates(int $accountId, string $oldestPeriodKey, string $latestPeriodKey): array
    {
        $oldestStart = Carbon::parse($oldestPeriodKey.'-01')->startOfMonth()->toDateString();
        $latestEnd = Carbon::parse($latestPeriodKey.'-01')->endOfMonth()->toDateString();

        return AccountingEntry::query()
            ->forUser($accountId)
            ->whereBetween('entry_date', [$oldestStart, $latestEnd])
            ->get()
            ->groupBy(fn (AccountingEntry $entry): string => $entry->entry_date->format('Y-m'))
            ->map(fn ($group) => [
                'entry_count' => $group->count(),
                'debit_total' => (float) $group->where('direction', AccountingEntry::DIRECTION_DEBIT)->sum('amount'),
                'credit_total' => (float) $group->where('direction', AccountingEntry::DIRECTION_CREDIT)->sum('amount'),
            ])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function batchAggregates(int $accountId, string $oldestPeriodKey, string $latestPeriodKey): array
    {
        $oldestStart = Carbon::parse($oldestPeriodKey.'-01')->startOfMonth()->toDateString();
        $latestEnd = Carbon::parse($latestPeriodKey.'-01')->endOfMonth()->toDateString();

        return AccountingEntryBatch::query()
            ->forUser($accountId)
            ->whereBetween('entry_date', [$oldestStart, $latestEnd])
            ->get()
            ->groupBy(fn (AccountingEntryBatch $batch): string => $batch->entry_date->format('Y-m'))
            ->map(fn ($group) => $group->count())
            ->all();
    }

    /**
     * @return array<string, bool>
     */
    private function availableActions(string $status): array
    {
        return [
            'open' => in_array($status, [AccountingPeriod::STATUS_IN_REVIEW, AccountingPeriod::STATUS_REOPENED], true),
            'in_review' => in_array($status, [AccountingPeriod::STATUS_OPEN, AccountingPeriod::STATUS_REOPENED], true),
            'close' => in_array($status, [AccountingPeriod::STATUS_OPEN, AccountingPeriod::STATUS_IN_REVIEW, AccountingPeriod::STATUS_REOPENED], true),
            'reopen' => $status === AccountingPeriod::STATUS_CLOSED,
        ];
    }

    private function canTransition(string $fromStatus, string $toStatus): bool
    {
        $allowed = match ($fromStatus) {
            AccountingPeriod::STATUS_OPEN => [
                AccountingPeriod::STATUS_IN_REVIEW,
                AccountingPeriod::STATUS_CLOSED,
            ],
            AccountingPeriod::STATUS_IN_REVIEW => [
                AccountingPeriod::STATUS_OPEN,
                AccountingPeriod::STATUS_CLOSED,
            ],
            AccountingPeriod::STATUS_CLOSED => [
                AccountingPeriod::STATUS_REOPENED,
            ],
            AccountingPeriod::STATUS_REOPENED => [
                AccountingPeriod::STATUS_OPEN,
                AccountingPeriod::STATUS_IN_REVIEW,
                AccountingPeriod::STATUS_CLOSED,
            ],
            default => [],
        };

        return in_array($toStatus, $allowed, true);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function periodRange(string $periodKey): array
    {
        $start = Carbon::parse($periodKey.'-01')->startOfMonth();
        $end = (clone $start)->endOfMonth();

        return [$start->toDateString(), $end->toDateString()];
    }
}
