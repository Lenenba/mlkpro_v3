<?php

namespace App\Services\Accounting;

use App\Models\AccountingEntry;
use App\Models\AccountingEntryBatch;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AccountingTaxService
{
    public function __construct(
        private readonly AccountingReadService $readService
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(int $accountId, array $filters): array
    {
        $entries = $this->readService->query($accountId, $filters)
            ->where('tax_amount', '>', 0)
            ->get();

        $collectedEntries = $entries->filter(
            fn (AccountingEntry $entry): bool => in_array($entry->batch?->source_type, ['invoice', 'sale'], true)
                && $entry->direction === AccountingEntry::DIRECTION_CREDIT
        );
        $paidEntries = $entries->filter(
            fn (AccountingEntry $entry): bool => $entry->batch?->source_type === 'expense'
                && $entry->direction === AccountingEntry::DIRECTION_DEBIT
        );
        $reviewRequiredEntries = $entries->filter(
            fn (AccountingEntry $entry): bool => ($entry->batch?->status ?? null) === AccountingEntryBatch::STATUS_REVIEW_REQUIRED
                || $entry->review_status !== AccountingEntry::REVIEW_STATUS_RECONCILED
        );

        [$startDate, $endDate] = $this->periodRange($filters['period'] ?? null);
        $activeFilters = collect($filters)
            ->except(['period', 'per_page'])
            ->filter(fn (mixed $value): bool => filled($value))
            ->keys()
            ->values()
            ->all();

        return [
            'period_key' => $this->normalizePeriodKey($filters['period'] ?? null),
            'period_label' => $this->periodLabel($filters['period'] ?? null),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'taxes_collected' => round((float) $collectedEntries->sum('tax_amount'), 2),
            'taxes_paid' => round((float) $paidEntries->sum('tax_amount'), 2),
            'net_tax_due' => round((float) $collectedEntries->sum('tax_amount') - (float) $paidEntries->sum('tax_amount'), 2),
            'taxable_entry_count' => $entries->count(),
            'review_required_count' => $reviewRequiredEntries->count(),
            'active_filter_keys' => $activeFilters,
            'source_breakdown' => [
                [
                    'source_type' => 'invoice',
                    'direction' => 'collected',
                    'amount' => round((float) $collectedEntries
                        ->filter(fn (AccountingEntry $entry): bool => $entry->batch?->source_type === 'invoice')
                        ->sum('tax_amount'), 2),
                ],
                [
                    'source_type' => 'sale',
                    'direction' => 'collected',
                    'amount' => round((float) $collectedEntries
                        ->filter(fn (AccountingEntry $entry): bool => $entry->batch?->source_type === 'sale')
                        ->sum('tax_amount'), 2),
                ],
                [
                    'source_type' => 'expense',
                    'direction' => 'paid',
                    'amount' => round((float) $paidEntries->sum('tax_amount'), 2),
                ],
            ],
            'review_required_sources' => $reviewRequiredEntries
                ->groupBy(fn (AccountingEntry $entry): string => ($entry->batch?->source_type ?? 'unknown').':'.($entry->batch?->source_id ?? 0).':'.($entry->batch?->source_event_key ?? 'unknown'))
                ->map(function (Collection $group): array {
                    /** @var \App\Models\AccountingEntry $first */
                    $first = $group->first();

                    return [
                        'source_type' => $first->batch?->source_type ?? 'unknown',
                        'source_event_key' => $first->batch?->source_event_key ?? 'unknown',
                        'source_reference' => $first->batch?->source_reference ?? $first->description,
                        'source_url' => data_get($first->batch?->meta, 'source_url'),
                        'tax_amount' => round((float) $group->sum('tax_amount'), 2),
                        'batch_status' => $first->batch?->status,
                        'review_status' => $first->review_status,
                    ];
                })
                ->values()
                ->take(5)
                ->all(),
        ];
    }

    private function normalizePeriodKey(mixed $period): ?string
    {
        return is_string($period) && preg_match('/^\d{4}-\d{2}$/', $period)
            ? $period
            : null;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function periodRange(mixed $period): array
    {
        $periodKey = $this->normalizePeriodKey($period);
        if (! $periodKey) {
            return [null, null];
        }

        $start = Carbon::parse($periodKey.'-01')->startOfMonth();
        $end = (clone $start)->endOfMonth();

        return [$start->toDateString(), $end->toDateString()];
    }

    private function periodLabel(mixed $period): string
    {
        $periodKey = $this->normalizePeriodKey($period);
        if (! $periodKey) {
            return 'All periods';
        }

        return Carbon::parse($periodKey.'-01')->translatedFormat('F Y');
    }
}
