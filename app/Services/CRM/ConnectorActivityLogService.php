<?php

namespace App\Services\CRM;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\CRM\Connectors\CrmConnectorRegistry;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class ConnectorActivityLogService
{
    public function __construct(
        private readonly CrmConnectorRegistry $registry,
        private readonly CrmActivityContextResolver $contextResolver,
    ) {}

    public function logMessageEvent(
        ?User $actor,
        Model $subject,
        string $connectorKey,
        string $event,
        array $payload = [],
        array $context = [],
        ?string $description = null
    ): ActivityLog {
        $adapter = $this->registry->adapter($connectorKey);
        $normalized = $adapter->normalizeMessageEvent($event, $payload);

        return ActivityLog::record(
            $actor,
            $subject,
            $normalized['action'],
            $this->normalizeMessageProperties($subject, $normalized['properties'], $context),
            $description ?? $normalized['description']
        );
    }

    public function logMeetingEvent(
        ?User $actor,
        Model $subject,
        string $connectorKey,
        string $event,
        array $payload = [],
        array $context = [],
        ?string $description = null
    ): ActivityLog {
        $adapter = $this->registry->adapter($connectorKey);
        $normalized = $adapter->normalizeMeetingEvent($event, $payload);

        return ActivityLog::record(
            $actor,
            $subject,
            $normalized['action'],
            $this->normalizeMeetingProperties($subject, $normalized['properties'], $context),
            $description ?? $normalized['description']
        );
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function normalizeMessageProperties(Model $subject, array $properties, array $context): array
    {
        $normalized = $properties;

        foreach ([
            'email',
            'source',
            'event',
            'notification',
            'message_id',
            'provider_message_id',
            'provider',
            'external_message_id',
            'scheduled_for',
        ] as $key) {
            $value = $this->resolveString($context[$key] ?? null);

            if ($value !== null) {
                $normalized[$key] = $value;
            }
        }

        $assistant = $context['assistant'] ?? null;
        if (is_bool($assistant)) {
            $normalized['assistant'] = $assistant;
        }

        $retryAttempt = $this->resolveNullableInt($context['retry_attempt'] ?? $context['attempt'] ?? null);
        if ($retryAttempt !== null) {
            $normalized['retry_attempt'] = $retryAttempt;
        }

        $delayMinutes = $this->resolveNullableInt($context['delay_minutes'] ?? null);
        if ($delayMinutes !== null) {
            $normalized['delay_minutes'] = $delayMinutes;
        }

        foreach ($this->contextResolver->resolve($subject, array_merge($normalized, $context)) as $key => $value) {
            if ($value !== null) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function normalizeMeetingProperties(Model $subject, array $properties, array $context): array
    {
        $normalized = $properties;

        foreach ([
            'provider',
            'source',
            'external_meeting_id',
            'start_at',
            'end_at',
            'completed_at',
            'location',
            'conference_url',
            'organizer_email',
        ] as $key) {
            $value = $this->resolveString($context[$key] ?? null);

            if ($value !== null) {
                $normalized[$key] = $value;
            }
        }

        $allDay = $context['all_day'] ?? $context['is_all_day'] ?? null;
        if (is_bool($allDay)) {
            $normalized['all_day'] = $allDay;
        }

        foreach ($this->contextResolver->resolve($subject, array_merge($normalized, $context)) as $key => $value) {
            if ($value !== null) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function resolveNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function resolveString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
