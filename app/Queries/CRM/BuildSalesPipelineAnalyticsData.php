<?php

namespace App\Queries\CRM;

use Illuminate\Support\Collection;

class BuildSalesPipelineAnalyticsData
{
    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function execute(Collection $items): array
    {
        $openItems = $items->where('stage_state', 'open');
        $wonItems = $items->where('stage_state', 'won');
        $lostItems = $items->where('stage_state', 'lost');

        $byStage = [];
        foreach (BuildSalesPipelineIndexData::STAGES as $stage) {
            $byStage[$stage['key']] = $items->where('stage_key', $stage['key'])->count();
        }

        return [
            'total' => $items->count(),
            'open' => $openItems->count(),
            'won' => $wonItems->count(),
            'lost' => $lostItems->count(),
            'with_quote' => $items->filter(fn (array $item): bool => (bool) data_get($item, 'signals.has_quote', false))->count(),
            'without_quote' => $items->filter(fn (array $item): bool => ! data_get($item, 'signals.has_quote', false))->count(),
            'overdue_next_actions' => $items->filter(fn (array $item): bool => (bool) data_get($item, 'signals.has_overdue_next_action', false))->count(),
            'total_amount' => round((float) $items->sum(fn (array $item): float => (float) ($item['amount_total'] ?? 0)), 2),
            'open_amount' => round((float) $openItems->sum(fn (array $item): float => (float) ($item['amount_total'] ?? 0)), 2),
            'weighted_open_amount' => round((float) $openItems->sum(fn (array $item): float => (float) ($item['weighted_amount'] ?? 0)), 2),
            'closed_won_amount' => round((float) $wonItems->sum(fn (array $item): float => (float) ($item['amount_total'] ?? 0)), 2),
            'by_stage' => $byStage,
        ];
    }
}
