<?php

namespace App\Queries\Quotes;

use App\Models\Quote;
use Illuminate\Support\Collection;

class BuildQuoteRecoveryAnalyticsData
{
    public function execute(Collection $items): array
    {
        $totalCount = $items->count();
        $totalValue = round((float) $items->sum(fn (Quote $quote): float => (float) $quote->total), 2);
        $averageValue = $totalCount > 0 ? round($totalValue / $totalCount, 2) : 0;
        $acceptedCount = $items->where('status', 'accepted')->count();
        $declinedCount = $items->where('status', 'declined')->count();
        $sentPipelineCount = $items
            ->filter(fn (Quote $quote): bool => in_array((string) $quote->status, ['sent', 'accepted', 'declined'], true))
            ->count();

        return [
            'total' => $totalCount,
            'total_value' => $totalValue,
            'average_value' => $averageValue,
            'open' => $items->filter(fn (Quote $quote): bool => $quote->getAttribute('recovery_is_open') === true)->count(),
            'accepted' => $acceptedCount,
            'declined' => $declinedCount,
            'never_followed' => $items->where('recovery_queue', BuildQuoteRecoveryIndexData::QUEUE_NEVER_FOLLOWED)->count(),
            'due' => $items->where('recovery_queue', BuildQuoteRecoveryIndexData::QUEUE_DUE)->count(),
            'viewed_not_accepted' => $items->where('recovery_queue', BuildQuoteRecoveryIndexData::QUEUE_VIEWED_NOT_ACCEPTED)->count(),
            'expired' => $items->where('recovery_queue', BuildQuoteRecoveryIndexData::QUEUE_EXPIRED)->count(),
            'high_value' => $items->where('recovery_queue', BuildQuoteRecoveryIndexData::QUEUE_HIGH_VALUE)->count(),
            'sent_to_accepted_rate' => $sentPipelineCount > 0
                ? round(($acceptedCount / $sentPipelineCount) * 100, 1)
                : 0,
        ];
    }
}
