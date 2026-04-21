<?php

namespace App\Queries\CRM;

use Illuminate\Support\Collection;

class BuildSalesInboxAnalyticsData
{
    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function execute(Collection $items): array
    {
        $byQueue = [];

        foreach (BuildSalesInboxIndexData::QUEUES as $queue) {
            $queueItems = $items->where('queue', $queue);

            $byQueue[$queue] = [
                'count' => $queueItems->count(),
                'amount_total' => round((float) $queueItems->sum(fn (array $item): float => (float) ($item['amount_total'] ?? 0)), 2),
                'weighted_amount' => round((float) $queueItems->sum(fn (array $item): float => (float) ($item['weighted_amount'] ?? 0)), 2),
            ];
        }

        return [
            'total' => $items->count(),
            'overdue' => data_get($byQueue, BuildSalesInboxIndexData::QUEUE_OVERDUE.'.count', 0),
            'no_next_action' => data_get($byQueue, BuildSalesInboxIndexData::QUEUE_NO_NEXT_ACTION.'.count', 0),
            'quoted' => data_get($byQueue, BuildSalesInboxIndexData::QUEUE_QUOTED.'.count', 0),
            'needs_quote' => data_get($byQueue, BuildSalesInboxIndexData::QUEUE_NEEDS_QUOTE.'.count', 0),
            'active' => data_get($byQueue, BuildSalesInboxIndexData::QUEUE_ACTIVE.'.count', 0),
            'open_amount' => round((float) $items->sum(fn (array $item): float => (float) ($item['amount_total'] ?? 0)), 2),
            'weighted_open_amount' => round((float) $items->sum(fn (array $item): float => (float) ($item['weighted_amount'] ?? 0)), 2),
            'by_queue' => $byQueue,
        ];
    }
}
