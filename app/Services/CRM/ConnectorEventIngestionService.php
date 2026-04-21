<?php

namespace App\Services\CRM;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class ConnectorEventIngestionService
{
    public function __construct(
        private readonly ConnectorActivityLogService $activityLogService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function ingest(User $actor, array $payload): ActivityLog
    {
        $subject = $this->resolveSubject(
            (string) ($payload['subject_type'] ?? ''),
            $payload['subject_id'] ?? null,
            $actor->accountOwnerId()
        );

        $family = strtolower(trim((string) ($payload['family'] ?? '')));
        $connectorKey = (string) ($payload['connector_key'] ?? '');
        $event = (string) ($payload['event'] ?? '');
        $eventPayload = (array) ($payload['payload'] ?? []);
        $context = (array) ($payload['context'] ?? []);
        $description = isset($payload['description']) ? trim((string) $payload['description']) : null;

        $activity = match ($family) {
            'message' => $this->activityLogService->logMessageEvent(
                $actor,
                $subject,
                $connectorKey,
                $event,
                $eventPayload,
                $context,
                $description
            ),
            'meeting' => $this->activityLogService->logMeetingEvent(
                $actor,
                $subject,
                $connectorKey,
                $event,
                $eventPayload,
                $context,
                $description
            ),
            default => abort(422, 'Unsupported CRM connector family.'),
        };

        $occurredAt = $this->resolveOccurredAt(
            $family,
            strtolower(trim($event)),
            $payload['occurred_at'] ?? null,
            $eventPayload
        );

        if ($occurredAt !== null) {
            $activity->timestamps = false;
            $activity->forceFill([
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ])->save();
            $activity->timestamps = true;
        }

        return $activity->fresh(['user:id,name']) ?? $activity;
    }

    private function resolveSubject(string $subjectType, mixed $subjectId, int $accountId): Model
    {
        $normalizedType = strtolower(trim($subjectType));
        $normalizedId = is_numeric($subjectId) ? (int) $subjectId : 0;

        abort_if($normalizedId <= 0, 404);

        return match ($normalizedType) {
            'customer' => Customer::query()
                ->where('user_id', $accountId)
                ->findOrFail($normalizedId),
            'request' => LeadRequest::query()
                ->where('user_id', $accountId)
                ->findOrFail($normalizedId),
            'quote' => Quote::query()
                ->where('user_id', $accountId)
                ->findOrFail($normalizedId),
            default => abort(404),
        };
    }

    /**
     * @param  array<string, mixed>  $eventPayload
     */
    private function resolveOccurredAt(string $family, string $event, mixed $explicitOccurredAt, array $eventPayload): ?Carbon
    {
        foreach ($this->occurredAtCandidates($family, $event, $explicitOccurredAt, $eventPayload) as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            return Carbon::parse($value);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $eventPayload
     * @return array<int, mixed>
     */
    private function occurredAtCandidates(string $family, string $event, mixed $explicitOccurredAt, array $eventPayload): array
    {
        if ($family === 'message') {
            return match ($event) {
                'received' => [$explicitOccurredAt, $eventPayload['received_at'] ?? null],
                'sent' => [$explicitOccurredAt, $eventPayload['sent_at'] ?? null],
                'failed' => [$explicitOccurredAt, $eventPayload['failed_at'] ?? null],
                default => [$explicitOccurredAt],
            };
        }

        if ($family === 'meeting') {
            return match ($event) {
                'completed' => [
                    $explicitOccurredAt,
                    $eventPayload['completed_at'] ?? null,
                    $eventPayload['ended_at'] ?? null,
                ],
                default => [
                    $explicitOccurredAt,
                    $eventPayload['start_at'] ?? null,
                    $eventPayload['scheduled_for'] ?? null,
                    $eventPayload['meeting_at'] ?? null,
                ],
            };
        }

        return [$explicitOccurredAt];
    }
}
