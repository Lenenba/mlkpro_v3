<?php

namespace App\Support;

use App\Models\Invoice;

class TipAssigneeResolver
{
    public static function resolveForInvoice(Invoice $invoice): ?int
    {
        $invoice->loadMissing([
            'items.assignee:id,user_id',
            'work.teamMembers:id,user_id',
        ]);

        $itemAssigneeUserIds = collect($invoice->items ?? [])
            ->map(fn($item) => $item->assignee?->user_id)
            ->filter(fn($userId) => is_numeric($userId) && (int) $userId > 0)
            ->map(fn($userId) => (int) $userId);

        if ($itemAssigneeUserIds->isNotEmpty()) {
            return (int) $itemAssigneeUserIds
                ->countBy()
                ->sortDesc()
                ->keys()
                ->first();
        }

        $workAssigneeUserId = collect($invoice->work?->teamMembers ?? [])
            ->map(fn($teamMember) => (int) ($teamMember->user_id ?? 0))
            ->first(fn($userId) => $userId > 0);

        return $workAssigneeUserId ?: null;
    }
}

