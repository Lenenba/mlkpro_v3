<?php

namespace App\Services;

use App\Models\ProspectStatusHistory;
use App\Models\Request as LeadRequest;
use App\Models\User;

class ProspectStatusHistoryService
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(LeadRequest $lead, ?User $actor, array $context = []): ?ProspectStatusHistory
    {
        $fromStatus = $context['from_status'] ?? null;
        $toStatus = $context['to_status'] ?? $lead->status;

        if ($toStatus === null) {
            return null;
        }

        if ($fromStatus !== null && $fromStatus === $toStatus) {
            return null;
        }

        $comment = $this->normalizeComment($context['comment'] ?? null);
        $metadata = $context['metadata'] ?? null;

        return ProspectStatusHistory::create([
            'request_id' => $lead->id,
            'user_id' => $actor?->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'comment' => $comment,
            'metadata' => is_array($metadata) && $metadata !== [] ? $metadata : null,
        ]);
    }

    private function normalizeComment(mixed $comment): ?string
    {
        $value = trim((string) $comment);

        return $value !== '' ? $value : null;
    }
}
