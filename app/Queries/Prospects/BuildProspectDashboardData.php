<?php

namespace App\Queries\Prospects;

use App\Models\Prospect;
use App\Models\Request as LeadRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BuildProspectDashboardData
{
    public function execute(int $accountId): array
    {
        $referenceTime = now();
        $windowDays = 30;
        $windowStart = $referenceTime->copy()->subDays($windowDays)->startOfDay();

        $prospects = Prospect::query()
            ->where('user_id', $accountId)
            ->whereNull('archived_at')
            ->with([
                'assignee:id,user_id,account_id,role,title',
                'assignee.user:id,name',
            ])
            ->get([
                'id',
                'user_id',
                'customer_id',
                'assigned_team_member_id',
                'channel',
                'status',
                'created_at',
                'converted_at',
                'next_follow_up_at',
                'archived_at',
            ]);

        return [
            'kind' => 'prospect_dashboard_v1',
            'reference_at' => $referenceTime->toJSON(),
            'window_days' => $windowDays,
            'summary' => $this->buildSummary($prospects, $referenceTime, $windowStart),
            'by_status' => $this->buildStatusBreakdown($prospects),
            'by_source' => $this->buildSourceBreakdown($prospects),
            'by_assignee' => $this->buildAssigneeBreakdown($prospects, $referenceTime),
        ];
    }

    private function buildSummary(Collection $prospects, Carbon $referenceTime, Carbon $windowStart): array
    {
        $convertedInWindow = $prospects
            ->filter(fn (Prospect $prospect): bool => $this->isConvertedToCustomer($prospect))
            ->filter(fn (Prospect $prospect): bool => $prospect->converted_at instanceof Carbon)
            ->filter(fn (Prospect $prospect): bool => $prospect->converted_at->gte($windowStart))
            ->values();

        $createdInWindowCount = $prospects
            ->filter(fn (Prospect $prospect): bool => $prospect->created_at instanceof Carbon)
            ->filter(fn (Prospect $prospect): bool => $prospect->created_at->gte($windowStart))
            ->count();
        $convertedInWindowCount = $convertedInWindow->count();

        return [
            'total' => $prospects->count(),
            'new_this_week' => $prospects
                ->filter(fn (Prospect $prospect): bool => $prospect->created_at instanceof Carbon)
                ->filter(fn (Prospect $prospect): bool => $prospect->created_at->gte($referenceTime->copy()->startOfWeek()))
                ->count(),
            'new_this_month' => $prospects
                ->filter(fn (Prospect $prospect): bool => $prospect->created_at instanceof Carbon)
                ->filter(fn (Prospect $prospect): bool => $prospect->created_at->gte($referenceTime->copy()->startOfMonth()))
                ->count(),
            'due_today' => $prospects->filter(
                fn (Prospect $prospect): bool => $this->isFollowUpDueToday($prospect, $referenceTime)
            )->count(),
            'overdue' => $prospects->filter(
                fn (Prospect $prospect): bool => $this->isFollowUpOverdue($prospect, $referenceTime)
            )->count(),
            'won' => $prospects->where('status', LeadRequest::STATUS_WON)->count(),
            'lost' => $prospects->where('status', LeadRequest::STATUS_LOST)->count(),
            'converted' => $prospects->filter(
                fn (Prospect $prospect): bool => $this->isConvertedToCustomer($prospect)
            )->count(),
            'conversion_created_count' => $createdInWindowCount,
            'conversion_converted_count' => $convertedInWindowCount,
            'conversion_rate' => $createdInWindowCount > 0
                ? round(($convertedInWindowCount / $createdInWindowCount) * 100, 1)
                : 0,
            'avg_conversion_days' => $this->averageConversionDays($convertedInWindow),
        ];
    }

    private function buildStatusBreakdown(Collection $prospects): array
    {
        $counts = $prospects
            ->groupBy(fn (Prospect $prospect): string => (string) $prospect->status)
            ->map(fn (Collection $items): int => $items->count());

        return collect(Prospect::statusOptions())
            ->map(fn (array $option): array => [
                'status' => $option['id'],
                'label' => $option['name'],
                'total' => (int) ($counts->get($option['id']) ?? 0),
            ])
            ->values()
            ->all();
    }

    private function buildSourceBreakdown(Collection $prospects): array
    {
        return $prospects
            ->groupBy(fn (Prospect $prospect): string => $this->normalizeSource($prospect->channel))
            ->map(function (Collection $items, string $source): array {
                $total = $items->count();
                $converted = $items->filter(
                    fn (Prospect $prospect): bool => $this->isConvertedToCustomer($prospect)
                )->count();

                return [
                    'source' => $source,
                    'total' => $total,
                    'converted' => $converted,
                    'won' => $items->where('status', LeadRequest::STATUS_WON)->count(),
                    'lost' => $items->where('status', LeadRequest::STATUS_LOST)->count(),
                    'rate' => $total > 0 ? round(($converted / $total) * 100, 1) : 0,
                ];
            })
            ->sort(function (array $left, array $right): int {
                $totalComparison = $right['total'] <=> $left['total'];
                if ($totalComparison !== 0) {
                    return $totalComparison;
                }

                $rateComparison = $right['rate'] <=> $left['rate'];
                if ($rateComparison !== 0) {
                    return $rateComparison;
                }

                return strcmp((string) $left['source'], (string) $right['source']);
            })
            ->values()
            ->all();
    }

    private function buildAssigneeBreakdown(Collection $prospects, Carbon $referenceTime): array
    {
        return $prospects
            ->groupBy(fn (Prospect $prospect): string => (string) ($prospect->assigned_team_member_id ?? 'unassigned'))
            ->map(function (Collection $items, string $key) use ($referenceTime): array {
                $first = $items->first();
                $assigneeId = $first?->assigned_team_member_id;
                $name = $first?->assignee?->user?->name
                    ?? $first?->assignee?->title
                    ?? ($assigneeId ? 'Team member' : null);

                return [
                    'assignee_id' => $assigneeId ? (int) $assigneeId : null,
                    'name' => $name,
                    'total' => $items->count(),
                    'due_today' => $items->filter(
                        fn (Prospect $prospect): bool => $this->isFollowUpDueToday($prospect, $referenceTime)
                    )->count(),
                    'overdue' => $items->filter(
                        fn (Prospect $prospect): bool => $this->isFollowUpOverdue($prospect, $referenceTime)
                    )->count(),
                    'won' => $items->where('status', LeadRequest::STATUS_WON)->count(),
                    'lost' => $items->where('status', LeadRequest::STATUS_LOST)->count(),
                    'converted' => $items->filter(
                        fn (Prospect $prospect): bool => $this->isConvertedToCustomer($prospect)
                    )->count(),
                ];
            })
            ->sort(function (array $left, array $right): int {
                $overdueComparison = $right['overdue'] <=> $left['overdue'];
                if ($overdueComparison !== 0) {
                    return $overdueComparison;
                }

                $dueTodayComparison = $right['due_today'] <=> $left['due_today'];
                if ($dueTodayComparison !== 0) {
                    return $dueTodayComparison;
                }

                $totalComparison = $right['total'] <=> $left['total'];
                if ($totalComparison !== 0) {
                    return $totalComparison;
                }

                return strcmp(
                    Str::lower((string) ($left['name'] ?? 'zzzz')),
                    Str::lower((string) ($right['name'] ?? 'zzzz'))
                );
            })
            ->values()
            ->all();
    }

    private function averageConversionDays(Collection $prospects): ?float
    {
        $durations = $prospects
            ->map(function (Prospect $prospect): ?float {
                if (! $prospect->created_at instanceof Carbon || ! $prospect->converted_at instanceof Carbon) {
                    return null;
                }

                if ($prospect->converted_at->lt($prospect->created_at)) {
                    return null;
                }

                return round($prospect->created_at->diffInSeconds($prospect->converted_at) / 86400, 1);
            })
            ->filter(fn (?float $duration): bool => $duration !== null)
            ->values();

        if ($durations->isEmpty()) {
            return null;
        }

        return round($durations->average(), 1);
    }

    private function isFollowUpDueToday(Prospect $prospect, Carbon $referenceTime): bool
    {
        return $prospect->next_follow_up_at instanceof Carbon
            && ! $this->isClosed($prospect)
            && $prospect->next_follow_up_at->isSameDay($referenceTime);
    }

    private function isFollowUpOverdue(Prospect $prospect, Carbon $referenceTime): bool
    {
        return $prospect->next_follow_up_at instanceof Carbon
            && ! $this->isClosed($prospect)
            && $prospect->next_follow_up_at->lt($referenceTime);
    }

    private function isClosed(Prospect $prospect): bool
    {
        return in_array($prospect->status, [
            LeadRequest::STATUS_WON,
            LeadRequest::STATUS_LOST,
            LeadRequest::STATUS_CONVERTED,
        ], true);
    }

    private function isConvertedToCustomer(Prospect $prospect): bool
    {
        return $prospect->status === LeadRequest::STATUS_CONVERTED;
    }

    private function normalizeSource(?string $source): string
    {
        $normalized = Str::lower(trim((string) $source));

        return match ($normalized) {
            '', 'unknown' => 'unknown',
            'web', 'website', 'form' => 'web_form',
            default => $normalized,
        };
    }
}
