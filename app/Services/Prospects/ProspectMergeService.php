<?php

namespace App\Services\Prospects;

use App\Models\ActivityLog;
use App\Models\Request as LeadRequest;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProspectMergeService
{
    /**
     * @return array{lead: LeadRequest, source: LeadRequest, summary: array<string, mixed>}
     */
    public function execute(LeadRequest $primary, LeadRequest $secondary, ?User $actor = null): array
    {
        if ($primary->is($secondary)) {
            throw ValidationException::withMessages([
                'source_id' => ['Cannot merge the same request.'],
            ]);
        }

        if ((int) $primary->user_id !== (int) $secondary->user_id) {
            throw ValidationException::withMessages([
                'source_id' => ['Prospects from different accounts cannot be merged.'],
            ]);
        }

        $primary->loadMissing('quote');
        $secondary->loadMissing('quote');

        $this->ensureCompatible($primary, $secondary);

        return DB::transaction(function () use ($primary, $secondary, $actor) {
            $mergedAt = now();
            $summary = $this->buildSummary($secondary);
            $sourceSnapshot = $this->snapshot($secondary);

            $primary->update($this->buildPrimaryUpdates($primary, $secondary, $mergedAt));

            if ($secondary->quote && ! $primary->quote) {
                $secondary->quote->update(['request_id' => $primary->id]);
                $summary['quote_transferred'] = true;
                $summary['quote_id'] = $secondary->quote->id;
            }

            $secondary->notes()->update(['request_id' => $primary->id]);
            $secondary->media()->update(['request_id' => $primary->id]);
            $secondary->prospectInteractions()->update(['request_id' => $primary->id]);
            $secondary->tasks()
                ->whereIn('status', Task::OPEN_STATUSES)
                ->update(['request_id' => $primary->id]);

            $secondaryMeta = $secondary->meta ?? [];
            $secondaryMeta['merge'] = [
                'merged_into_prospect_id' => $primary->id,
                'merged_at' => $mergedAt->toIso8601String(),
                'merged_by_user_id' => $actor?->id,
                'summary' => $summary,
            ];

            $secondary->update([
                'duplicate_of_prospect_id' => $primary->id,
                'merged_into_prospect_id' => $primary->id,
                'archived_at' => $mergedAt,
                'archived_by_user_id' => $actor?->id,
                'archive_reason' => sprintf('Merged into prospect #%d', $primary->id),
                'last_activity_at' => $mergedAt,
                'meta' => $secondaryMeta,
            ]);

            $primary = $primary->fresh();
            $secondary = $secondary->fresh();

            ActivityLog::record($actor, $primary, 'merged', [
                'source_id' => $sourceSnapshot['id'],
                'summary' => $summary,
                'source_snapshot' => $sourceSnapshot,
            ], 'Prospect merged');

            ActivityLog::record($actor, $secondary, 'merged_into', [
                'target_id' => $primary->id,
                'summary' => $summary,
                'target_snapshot' => $this->snapshot($primary),
            ], 'Prospect merged into another');

            return [
                'lead' => $primary,
                'source' => $secondary,
                'summary' => $summary,
            ];
        });
    }

    private function ensureCompatible(LeadRequest $primary, LeadRequest $secondary): void
    {
        if ($primary->quote && $secondary->quote) {
            throw ValidationException::withMessages([
                'source_id' => ['Both prospects already have quotes. Merge is blocked.'],
            ]);
        }

        $primaryCustomerId = $this->resolvedCustomerId($primary);
        $secondaryCustomerId = $this->resolvedCustomerId($secondary);

        if (
            $primaryCustomerId !== null
            && $secondaryCustomerId !== null
            && $primaryCustomerId !== $secondaryCustomerId
        ) {
            throw ValidationException::withMessages([
                'source_id' => ['Prospects linked to different customers cannot be merged automatically.'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPrimaryUpdates(LeadRequest $primary, LeadRequest $secondary, $mergedAt): array
    {
        $fields = [
            'assigned_team_member_id',
            'external_customer_id',
            'channel',
            'service_type',
            'urgency',
            'title',
            'description',
            'contact_name',
            'contact_email',
            'contact_phone',
            'country',
            'state',
            'city',
            'street1',
            'street2',
            'postal_code',
            'lat',
            'lng',
            'is_serviceable',
            'converted_at',
            'first_response_at',
            'sla_due_at',
            'triage_priority',
            'risk_level',
            'stale_since_at',
            'status_updated_at',
            'next_follow_up_at',
            'lost_reason',
        ];

        $updates = [
            'customer_id' => $this->resolvedCustomerId($primary) ?? $this->resolvedCustomerId($secondary),
            'last_activity_at' => $mergedAt,
            'meta' => array_replace($secondary->meta ?? [], $primary->meta ?? []),
        ];

        foreach ($fields as $field) {
            $updates[$field] = $this->preferPrimaryValue(
                $primary->getAttribute($field),
                $secondary->getAttribute($field)
            );
        }

        return $updates;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSummary(LeadRequest $secondary): array
    {
        return [
            'notes_transferred' => $secondary->notes()->count(),
            'documents_transferred' => $secondary->media()->count(),
            'interactions_transferred' => $secondary->prospectInteractions()->count(),
            'open_tasks_transferred' => $secondary->tasks()->whereIn('status', Task::OPEN_STATUSES)->count(),
            'closed_tasks_retained' => $secondary->tasks()->whereIn('status', Task::CLOSED_STATUSES)->count(),
            'quote_transferred' => false,
        ];
    }

    /**
     * @return array{id: int, customer_id: int|null, quote_id: int|null, status: string|null, title: string|null, contact_name: string|null, contact_email: string|null, contact_phone: string|null}
     */
    private function snapshot(LeadRequest $lead): array
    {
        $lead->loadMissing('quote');

        return [
            'id' => (int) $lead->id,
            'customer_id' => $lead->customer_id,
            'quote_id' => $lead->quote?->id,
            'status' => $lead->status,
            'title' => $lead->title,
            'contact_name' => $lead->contact_name,
            'contact_email' => $lead->contact_email,
            'contact_phone' => $lead->contact_phone,
        ];
    }

    private function resolvedCustomerId(LeadRequest $lead): ?int
    {
        return $lead->customer_id ?? $lead->quote?->customer_id;
    }

    private function preferPrimaryValue(mixed $primaryValue, mixed $secondaryValue): mixed
    {
        if ($primaryValue === null) {
            return $secondaryValue;
        }

        if (is_string($primaryValue) && trim($primaryValue) === '') {
            return $secondaryValue;
        }

        return $primaryValue;
    }
}
