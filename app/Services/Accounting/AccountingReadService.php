<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingEntry;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AccountingReadService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function journal(int $accountId, array $filters): LengthAwarePaginator
    {
        $perPage = max(10, (int) ($filters['per_page'] ?? 15));

        return $this->query($accountId, $filters)
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(int $accountId, array $filters): array
    {
        $entries = $this->query($accountId, $filters)->get();

        $debitTotal = round((float) $entries
            ->where('direction', AccountingEntry::DIRECTION_DEBIT)
            ->sum('amount'), 2);
        $creditTotal = round((float) $entries
            ->where('direction', AccountingEntry::DIRECTION_CREDIT)
            ->sum('amount'), 2);
        $reviewRequiredCount = $entries
            ->filter(fn (AccountingEntry $entry): bool => ($entry->batch?->status ?? null) === 'review_required')
            ->count();

        return [
            'entry_count' => $entries->count(),
            'batch_count' => $entries->pluck('batch_id')->filter()->unique()->count(),
            'debit_total' => $debitTotal,
            'credit_total' => $creditTotal,
            'review_required_count' => $reviewRequiredCount,
            'by_account' => $this->groupByAccount($entries),
            'by_source' => $this->groupBySource($entries),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function query(int $accountId, array $filters): Builder
    {
        return $this->baseQuery($accountId, $filters)
            ->with([
                'account:id,key,code,name,type',
                'batch:id,user_id,source_type,source_id,source_event_key,source_reference,status,meta',
            ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function accountOptions(int $accountId): array
    {
        return AccountingAccount::query()
            ->forUser($accountId)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (AccountingAccount $account) => [
                'id' => $account->id,
                'label' => trim($account->code.' '.$account->name),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function groupByAccount(Collection $entries): array
    {
        return $entries
            ->groupBy(fn (AccountingEntry $entry) => $entry->account?->id ?: 0)
            ->map(function (Collection $group): array {
                /** @var \App\Models\AccountingEntry $first */
                $first = $group->first();

                return [
                    'account_id' => $first->account?->id,
                    'account_code' => $first->account?->code ?? '----',
                    'account_name' => $first->account?->name ?? 'Unknown account',
                    'debit_total' => round((float) $group
                        ->where('direction', AccountingEntry::DIRECTION_DEBIT)
                        ->sum('amount'), 2),
                    'credit_total' => round((float) $group
                        ->where('direction', AccountingEntry::DIRECTION_CREDIT)
                        ->sum('amount'), 2),
                ];
            })
            ->sortBy('account_code')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function groupBySource(Collection $entries): array
    {
        return $entries
            ->groupBy(fn (AccountingEntry $entry) => ($entry->batch?->source_type ?? 'unknown').':'.($entry->batch?->source_event_key ?? 'unknown'))
            ->map(function (Collection $group): array {
                /** @var \App\Models\AccountingEntry $first */
                $first = $group->first();

                return [
                    'source_type' => $first->batch?->source_type ?? 'unknown',
                    'source_event_key' => $first->batch?->source_event_key ?? 'unknown',
                    'debit_total' => round((float) $group
                        ->where('direction', AccountingEntry::DIRECTION_DEBIT)
                        ->sum('amount'), 2),
                    'credit_total' => round((float) $group
                        ->where('direction', AccountingEntry::DIRECTION_CREDIT)
                        ->sum('amount'), 2),
                    'entry_count' => $group->count(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function baseQuery(int $accountId, array $filters): Builder
    {
        return AccountingEntry::query()
            ->forUser($accountId)
            ->when(
                filled($filters['period'] ?? null),
                function (Builder $query) use ($filters): void {
                    [$startDate, $endDate] = $this->periodRange((string) $filters['period']);
                    $query->whereBetween('entry_date', [$startDate, $endDate]);
                }
            )
            ->when(
                filled($filters['source_type'] ?? null),
                fn (Builder $query) => $query->whereHas(
                    'batch',
                    fn (Builder $batchQuery) => $batchQuery->where('source_type', $filters['source_type'])
                )
            )
            ->when(
                filled($filters['account_id'] ?? null),
                fn (Builder $query) => $query->where('account_id', (int) $filters['account_id'])
            )
            ->when(
                filled($filters['review_status'] ?? null),
                fn (Builder $query) => $query->where('review_status', (string) $filters['review_status'])
            )
            ->when(
                filled($filters['search'] ?? null),
                function (Builder $query) use ($filters): void {
                    $search = '%'.trim((string) $filters['search']).'%';

                    $query->where(function (Builder $builder) use ($search): void {
                        $builder->where('description', 'like', $search)
                            ->orWhereHas('account', function (Builder $accountQuery) use ($search): void {
                                $accountQuery->where('code', 'like', $search)
                                    ->orWhere('name', 'like', $search);
                            })
                            ->orWhereHas('batch', function (Builder $batchQuery) use ($search): void {
                                $batchQuery->where('source_reference', 'like', $search)
                                    ->orWhere('source_event_key', 'like', $search);
                            });
                    });
                }
            );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function periodRange(string $period): array
    {
        $normalized = preg_match('/^\d{4}-\d{2}$/', $period) ? $period.'-01' : now()->startOfMonth()->toDateString();
        $start = Carbon::parse($normalized)->startOfMonth();
        $end = (clone $start)->endOfMonth();

        return [
            $start->startOfDay()->toDateTimeString(),
            $end->endOfDay()->toDateTimeString(),
        ];
    }
}
