<?php

namespace App\Services\CRM;

use App\Models\ActivityLog;
use App\Models\Request as LeadRequest;
use App\Models\User;
use App\Services\ProspectInteractionLogger;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class OutgoingEmailLogService
{
    public function __construct(
        private readonly CrmActivityContextResolver $contextResolver,
    ) {}

    public function logSent(?User $actor, Model $subject, array $context = [], ?string $description = null): ActivityLog
    {
        return $this->record(
            $actor,
            $subject,
            'message_email_sent',
            $context,
            $description ?? 'Email sent'
        );
    }

    public function logFailed(?User $actor, Model $subject, array $context = [], ?string $description = null): ActivityLog
    {
        return $this->record(
            $actor,
            $subject,
            'message_email_failed',
            $context,
            $description ?? 'Email failed'
        );
    }

    public function logRetryScheduled(?User $actor, Model $subject, array $context = [], ?string $description = null): ActivityLog
    {
        $scheduledFor = $context['scheduled_for'] ?? null;
        $delayMinutes = $this->resolveNullableInt($context['delay_minutes'] ?? null);

        if ($scheduledFor === null && $delayMinutes !== null) {
            $context['scheduled_for'] = now()->addMinutes($delayMinutes)->toIso8601String();
        }

        return $this->record(
            $actor,
            $subject,
            'message_email_retry_scheduled',
            $context,
            $description ?? 'Email retry scheduled'
        );
    }

    private function record(?User $actor, Model $subject, string $action, array $context, string $description): ActivityLog
    {
        $log = ActivityLog::record(
            $actor,
            $subject,
            $action,
            $this->normalizeProperties($subject, $context),
            $description
        );

        if ($subject instanceof LeadRequest) {
            app(ProspectInteractionLogger::class)->recordActivity($subject, $actor, $log);
        }

        return $log;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function normalizeProperties(Model $subject, array $context): array
    {
        $properties = [];

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
                $properties[$key] = $value;
            }
        }

        $assistant = $context['assistant'] ?? null;
        if (is_bool($assistant)) {
            $properties['assistant'] = $assistant;
        }

        $retryAttempt = $this->resolveNullableInt($context['retry_attempt'] ?? $context['attempt'] ?? null);
        if ($retryAttempt !== null) {
            $properties['retry_attempt'] = $retryAttempt;
        }

        $delayMinutes = $this->resolveNullableInt($context['delay_minutes'] ?? null);
        if ($delayMinutes !== null) {
            $properties['delay_minutes'] = $delayMinutes;
        }

        foreach ($this->contextResolver->resolve($subject, $context) as $key => $value) {
            if ($value !== null) {
                $properties[$key] = $value;
            }
        }

        return $properties;
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

        return $value;
    }
}
