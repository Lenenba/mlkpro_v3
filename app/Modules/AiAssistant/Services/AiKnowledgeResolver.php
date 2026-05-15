<?php

namespace App\Modules\AiAssistant\Services;

use App\Modules\AiAssistant\Models\AiKnowledgeItem;
use Illuminate\Support\Collection;

class AiKnowledgeResolver
{
    /**
     * @return Collection<int, AiKnowledgeItem>
     */
    public function activeForTenant(int $tenantId, int $limit = 12): Collection
    {
        return AiKnowledgeItem::query()
            ->forTenant($tenantId)
            ->active()
            ->latest()
            ->limit($limit)
            ->get();
    }
}
