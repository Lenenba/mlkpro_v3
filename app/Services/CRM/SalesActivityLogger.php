<?php

namespace App\Services\CRM;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\CRM\SalesActivityTaxonomy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesActivityLogger
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(User $actor, Model $subject, array $payload): ActivityLog
    {
        $resolved = $this->resolveActionPayload($payload);
        $action = $resolved['action'];
        $definition = $resolved['definition'];
        $quickAction = $resolved['quick_action'];
        $occurredAt = $resolved['occurred_at'];
        $properties = $resolved['properties'];
        $description = $resolved['description'];

        $log = ActivityLog::record(
            $actor,
            $subject,
            $action,
            $properties,
            $description
        );

        if ($occurredAt !== null) {
            $log->timestamps = false;
            $log->forceFill([
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ])->save();
            $log->timestamps = true;
        }

        $freshLog = $log->fresh(['user:id,name']);

        if (! $freshLog) {
            throw ValidationException::withMessages([
                'activity' => ['Unable to persist sales activity.'],
            ]);
        }

        return $freshLog;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     action: string,
     *     definition: array<string, mixed>,
     *     quick_action: string|null,
     *     occurred_at: Carbon|null,
     *     description: string,
     *     properties: array<string, mixed>
     * }
     */
    private function resolveActionPayload(array $payload): array
    {
        $quickActionId = isset($payload['quick_action']) ? (string) $payload['quick_action'] : null;
        $quickAction = SalesActivityTaxonomy::quickAction($quickActionId);

        $action = $quickAction['action'] ?? (isset($payload['action']) ? (string) $payload['action'] : '');
        $definition = SalesActivityTaxonomy::definition($action);

        if (! $action || $definition === null || (bool) ($definition['legacy'] ?? false)) {
            throw ValidationException::withMessages([
                'action' => ['A canonical sales activity action is required.'],
            ]);
        }

        $occurredAt = ! empty($payload['occurred_at'])
            ? Carbon::parse((string) $payload['occurred_at'])
            : null;
        $dueAt = $this->resolveDueAt($payload, $quickAction);
        $note = isset($payload['note']) ? trim((string) $payload['note']) : '';
        $customDescription = isset($payload['description']) ? trim((string) $payload['description']) : '';
        $metadata = isset($payload['metadata']) && is_array($payload['metadata'])
            ? $payload['metadata']
            : [];

        $properties = array_merge($metadata, [
            'activity_source' => 'phase_4_sales_activity',
            'sales_activity_action' => $action,
            'sales_activity_type' => (string) ($definition['type'] ?? ''),
            'logged_via' => 'crm_sales_activity',
        ]);

        if ($quickActionId) {
            $properties['quick_action'] = $quickActionId;
        }

        if ($note !== '') {
            $properties['note'] = $note;
        }

        if ($definition['type'] === SalesActivityTaxonomy::TYPE_MEETING && $dueAt !== null) {
            $properties['meeting_at'] = $dueAt->toIso8601String();
        }

        if (
            $dueAt !== null
            && (
                $definition['type'] === SalesActivityTaxonomy::TYPE_NEXT_ACTION
                || (bool) ($definition['opens_next_action'] ?? false)
            )
        ) {
            $properties['next_follow_up_at'] = $dueAt->toIso8601String();
        }

        $description = $this->resolveDescription($action, $definition, $customDescription, $note);

        return [
            'action' => $action,
            'definition' => $definition,
            'quick_action' => $quickActionId,
            'occurred_at' => $occurredAt,
            'description' => $description,
            'properties' => $properties,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $quickAction
     */
    private function resolveDueAt(array $payload, ?array $quickAction): ?Carbon
    {
        if (! empty($payload['due_at'])) {
            return Carbon::parse((string) $payload['due_at']);
        }

        $offsetDays = (int) ($quickAction['default_offset_days'] ?? 0);

        if ($offsetDays > 0) {
            return now()->addDays($offsetDays);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function resolveDescription(string $action, array $definition, string $customDescription, string $note): string
    {
        if ($customDescription !== '') {
            return Str::limit($customDescription, 255);
        }

        if ($action === 'sales_note_added' && $note !== '') {
            return Str::limit($note, 255);
        }

        return Str::limit((string) ($definition['label'] ?? 'Sales activity logged'), 255);
    }
}
