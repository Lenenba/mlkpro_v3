<?php

namespace App\Queries\Dashboard;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class OwnerDashboardKpiSeriesQuery
{
    /**
     * @param  array<string, string>  $semanticDirections
     * @return array<string, array<string, mixed>>
     */
    public function currentStateSeriesForKeys(
        Carbon $anchor,
        int $months,
        array $semanticDirections,
    ): array {
        $periods = $this->alignedMonthlyPeriods($anchor, $months);
        $period = $this->periodMetadata($periods, $anchor);
        $series = [];

        foreach ($semanticDirections as $key => $semanticDirection) {
            $series[$key] = $this->currentStateSeries(
                $period,
                ['type' => 'count'],
                $semanticDirection,
            );
        }

        return $series;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function execute(User $accountOwner, Carbon $anchor, int $months): array
    {
        $periods = $this->alignedMonthlyPeriods($anchor, $months);
        $currencyCode = $accountOwner->businessCurrencyCode();
        $period = $this->periodMetadata($periods, $anchor);

        $revenueValues = $this->sumByPeriods(
            Payment::query()
                ->where('user_id', $accountOwner->id)
                ->whereNotNull('invoice_id')
                ->whereNull('sale_id')
                ->where('currency_code', $currencyCode)
                ->settled(),
            'paid_at',
            'amount',
            $periods,
            true,
        );

        $series = [
            'revenue_paid' => $this->flowSeries(
                $periods,
                $period,
                $revenueValues,
                ['type' => 'currency', 'code' => $currencyCode],
                'higher_is_better',
            ),
            'revenue_outstanding' => $this->currentStateSeries(
                $period,
                ['type' => 'currency', 'code' => $currencyCode],
                'lower_is_better',
            ),
            'quotes_open' => $this->currentStateSeries($period, ['type' => 'count'], 'neutral'),
            'works_scheduled' => $this->currentStateSeries($period, ['type' => 'count'], 'neutral'),
            'works_in_progress' => $this->currentStateSeries($period, ['type' => 'count'], 'neutral'),
            'customers_total' => $this->currentStateSeries($period, ['type' => 'count'], 'higher_is_better'),
            'products_low_stock' => $this->currentStateSeries($period, ['type' => 'count'], 'lower_is_better'),
            'invoices_paid' => $this->currentStateSeries($period, ['type' => 'count'], 'higher_is_better'),
            'inventory_value' => $this->currentStateSeries(
                $period,
                ['type' => 'currency', 'code' => $currencyCode],
                'neutral',
            ),
        ];

        $series['expenses_paid'] = $accountOwner->hasCompanyFeature('expenses')
            ? $this->flowSeries(
                $periods,
                $period,
                $this->sumByPeriods(
                    Expense::query()
                        ->byAccount((int) $accountOwner->id)
                        ->where('currency_code', $currencyCode)
                        ->whereIn('status', [Expense::STATUS_PAID, Expense::STATUS_REIMBURSED]),
                    'paid_date',
                    'total',
                    $periods,
                    false,
                ),
                ['type' => 'currency', 'code' => $currencyCode],
                'lower_is_better',
            )
            : $this->unavailableSeries(
                $period,
                ['type' => 'currency', 'code' => $currencyCode],
                'lower_is_better',
                'feature_disabled',
            );

        return $series;
    }

    /**
     * @return array<int, array{label: string, localStart: Carbon, localEndExclusive: Carbon, utcStart: Carbon, utcEndExclusive: Carbon}>
     */
    private function alignedMonthlyPeriods(Carbon $anchor, int $months): array
    {
        $months = max(2, $months);
        $localAnchor = $anchor->copy();
        $periods = [];

        for ($offset = $months - 1; $offset >= 0; $offset -= 1) {
            $month = $localAnchor->copy()->subMonthsNoOverflow($offset);
            $localStart = $month->copy()->startOfMonth();
            $alignedDay = min($localAnchor->day, $month->daysInMonth);
            $localEnd = $month->copy()
                ->day($alignedDay)
                ->setTime(
                    $localAnchor->hour,
                    $localAnchor->minute,
                    $localAnchor->second,
                    $localAnchor->micro,
                );
            $localEndExclusive = $localEnd->copy()->addSecond()->startOfSecond();

            $periods[] = [
                'label' => $month->format('Y-m'),
                'localStart' => $localStart,
                'localEndExclusive' => $localEndExclusive,
                'utcStart' => $localStart->copy()->utc(),
                'utcEndExclusive' => $localEndExclusive->copy()->utc(),
            ];
        }

        return $periods;
    }

    /**
     * @param  array<int, array{label: string, localStart: Carbon, localEndExclusive: Carbon, utcStart: Carbon, utcEndExclusive: Carbon}>  $periods
     * @return array<int, float>
     */
    private function sumByPeriods(
        Builder $query,
        string $dateColumn,
        string $valueColumn,
        array $periods,
        bool $usesTimestamp,
    ): array {
        $grammar = $query->getQuery()->getGrammar();
        $wrappedDate = $grammar->wrap($dateColumn);
        $wrappedValue = $grammar->wrap($valueColumn);
        $rangeStart = $usesTimestamp
            ? $periods[0]['utcStart']
            : $periods[0]['localStart']->toDateString();
        $rangeEnd = $usesTimestamp
            ? $periods[array_key_last($periods)]['utcEndExclusive']
            : $this->dateEndExclusive($periods[array_key_last($periods)]['localEndExclusive']);

        $query->where($dateColumn, '>=', $rangeStart)
            ->where($dateColumn, '<', $rangeEnd);

        foreach ($periods as $index => $period) {
            $start = $usesTimestamp ? $period['utcStart'] : $period['localStart']->toDateString();
            $end = $usesTimestamp
                ? $period['utcEndExclusive']
                : $this->dateEndExclusive($period['localEndExclusive']);

            $query->selectRaw(
                "COALESCE(SUM(CASE WHEN {$wrappedDate} >= ? AND {$wrappedDate} < ? THEN {$wrappedValue} ELSE 0 END), 0) AS period_{$index}",
                [$start, $end],
            );
        }

        $row = $query->toBase()->first();

        return array_map(
            fn (int $index): float => round((float) ($row->{"period_{$index}"} ?? 0), 2),
            array_keys($periods),
        );
    }

    private function dateEndExclusive(Carbon $localEndExclusive): string
    {
        return $localEndExclusive->copy()
            ->subSecond()
            ->startOfDay()
            ->addDay()
            ->toDateString();
    }

    /**
     * @param  array<int, array{label: string, localStart: Carbon, localEndExclusive: Carbon, utcStart: Carbon, utcEndExclusive: Carbon}>  $periods
     * @param  array<string, mixed>  $period
     * @param  array<int, float>  $values
     * @param  array<string, string>  $unit
     * @return array<string, mixed>
     */
    private function flowSeries(
        array $periods,
        array $period,
        array $values,
        array $unit,
        string $semanticDirection,
    ): array {
        return [
            'labels' => array_column($periods, 'label'),
            'values' => $values,
            'granularity' => 'month',
            'period' => $period,
            'unit' => $unit,
            'measurement' => 'flow',
            'isTemporal' => true,
            'semanticDirection' => $semanticDirection,
            'historyStatus' => 'available',
            'comparison' => $this->comparison($values, $semanticDirection),
        ];
    }

    /**
     * @param  array<string, mixed>  $period
     * @param  array<string, string>  $unit
     * @return array<string, mixed>
     */
    private function currentStateSeries(array $period, array $unit, string $semanticDirection): array
    {
        return $this->unavailableSeries(
            $period,
            $unit,
            $semanticDirection,
            'historical_snapshots_not_recorded',
        );
    }

    /**
     * @param  array<string, mixed>  $period
     * @param  array<string, string>  $unit
     * @return array<string, mixed>
     */
    private function unavailableSeries(
        array $period,
        array $unit,
        string $semanticDirection,
        string $reason,
    ): array {
        return [
            'labels' => [],
            'values' => [],
            'granularity' => 'month',
            'period' => $period,
            'unit' => $unit,
            'measurement' => 'current_state',
            'isTemporal' => false,
            'semanticDirection' => $semanticDirection,
            'historyStatus' => $reason === 'feature_disabled' ? 'unavailable' : 'requires_snapshot',
            'unavailableReason' => $reason,
            'comparison' => null,
        ];
    }

    /**
     * @param  array<int, float>  $values
     * @return array<string, bool|float|string|null>|null
     */
    private function comparison(array $values, string $semanticDirection): ?array
    {
        if (count($values) < 2) {
            return null;
        }

        $current = (float) $values[array_key_last($values)];
        $previous = (float) $values[array_key_last($values) - 1];
        $delta = round($current - $previous, 2);
        $direction = $delta === 0.0 ? 'flat' : ($delta > 0 ? 'up' : 'down');
        $percent = $previous === 0.0
            ? ($current === 0.0 ? 0.0 : null)
            : round(abs($delta / $previous) * 100, 1);
        $isFavorable = $semanticDirection === 'lower_is_better'
            ? $delta <= 0
            : $delta >= 0;

        return [
            'current' => $current,
            'previous' => $previous,
            'delta' => $delta,
            'percent' => $percent,
            'direction' => $direction,
            'isFavorable' => $isFavorable,
        ];
    }

    /**
     * @param  array<int, array{label: string, localStart: Carbon, localEndExclusive: Carbon, utcStart: Carbon, utcEndExclusive: Carbon}>  $periods
     * @return array<string, bool|string>
     */
    private function periodMetadata(array $periods, Carbon $anchor): array
    {
        return [
            'start' => $periods[0]['localStart']->toDateString(),
            'end' => $anchor->toDateString(),
            'timezone' => $anchor->getTimezone()->getName(),
            'isPartial' => $anchor->lt($anchor->copy()->endOfMonth()),
            'comparisonMode' => 'aligned_month_to_date',
        ];
    }
}
