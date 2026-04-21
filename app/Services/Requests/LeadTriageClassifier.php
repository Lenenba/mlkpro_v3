<?php

namespace App\Services\Requests;

use App\Models\ActivityLog;
use App\Models\Request as LeadRequest;
use Illuminate\Support\Carbon;

class LeadTriageClassifier
{
    public const QUEUE_ACTIVE = 'active';

    public const QUEUE_BREACHED = 'breached';

    public const QUEUE_CLOSED = 'closed';

    public const QUEUE_DUE_SOON = 'due_soon';

    public const QUEUE_NEW = 'new';

    public const QUEUE_STALE = 'stale';

    private const DUE_SOON_HOURS = 24;

    private const INITIAL_RESPONSE_SLA_HOURS = 24;

    private const STALE_DAYS = 7;

    public function classify(LeadRequest $lead, ?Carbon $referenceTime = null): array
    {
        $now = $referenceTime ? $referenceTime->copy() : now();
        $isOpen = $this->isOpen($lead);

        $firstResponseAt = $this->resolveFirstResponseAt($lead);
        $lastActivityAt = $this->resolveLastActivityAt($lead);
        $slaDueAt = $this->resolveSlaDueAt($lead, $firstResponseAt);
        $effectiveDueAt = $this->resolveEffectiveDueAt($lead, $slaDueAt);
        $staleSinceAt = $this->resolveStaleSinceAt($lead, $lastActivityAt, $isOpen);

        $isBreached = $isOpen && $effectiveDueAt !== null && $effectiveDueAt->lt($now);
        $isDueSoon = $isOpen
            && ! $isBreached
            && $effectiveDueAt !== null
            && $effectiveDueAt->lte($now->copy()->addHours(self::DUE_SOON_HOURS));
        $isNew = $isOpen && $firstResponseAt === null;
        $isStale = $isOpen
            && $staleSinceAt !== null
            && $staleSinceAt->lte($now);

        $queue = $this->resolveQueue($isOpen, $isBreached, $isDueSoon, $isNew, $isStale);

        return [
            'queue' => $queue,
            'is_open' => $isOpen,
            'is_new' => $isNew,
            'is_due_soon' => $isDueSoon,
            'is_stale' => $isStale,
            'is_breached' => $isBreached,
            'first_response_at' => $firstResponseAt,
            'last_activity_at' => $lastActivityAt,
            'sla_due_at' => $slaDueAt,
            'effective_due_at' => $effectiveDueAt,
            'stale_since_at' => $staleSinceAt,
            'triage_priority' => $lead->triage_priority ?? $this->defaultPriority($queue),
            'risk_level' => $lead->risk_level ?? $this->defaultRiskLevel($queue),
            'days_since_activity' => $lastActivityAt ? $now->diffInDays($lastActivityAt) : null,
        ];
    }

    private function isOpen(LeadRequest $lead): bool
    {
        return ! in_array($lead->status, [
            LeadRequest::STATUS_CONVERTED,
            LeadRequest::STATUS_WON,
            LeadRequest::STATUS_LOST,
        ], true);
    }

    private function resolveQueue(
        bool $isOpen,
        bool $isBreached,
        bool $isDueSoon,
        bool $isNew,
        bool $isStale
    ): string {
        if (! $isOpen) {
            return self::QUEUE_CLOSED;
        }

        if ($isBreached) {
            return self::QUEUE_BREACHED;
        }

        if ($isNew) {
            return self::QUEUE_NEW;
        }

        if ($isDueSoon) {
            return self::QUEUE_DUE_SOON;
        }

        if ($isStale) {
            return self::QUEUE_STALE;
        }

        return self::QUEUE_ACTIVE;
    }

    private function resolveFirstResponseAt(LeadRequest $lead): ?Carbon
    {
        $explicit = $this->dateValue($lead->first_response_at);
        if ($explicit) {
            return $explicit;
        }

        if (! $lead->exists) {
            return null;
        }

        $value = ActivityLog::query()
            ->where('subject_type', $lead->getMorphClass())
            ->where('subject_id', $lead->id)
            ->where('action', '!=', 'created')
            ->min('created_at');

        return $this->dateValue($value);
    }

    private function resolveLastActivityAt(LeadRequest $lead): ?Carbon
    {
        $explicit = $this->dateValue($lead->last_activity_at);
        if ($explicit) {
            return $explicit;
        }

        if ($lead->exists) {
            $value = ActivityLog::query()
                ->where('subject_type', $lead->getMorphClass())
                ->where('subject_id', $lead->id)
                ->max('created_at');

            $activityAt = $this->dateValue($value);
            if ($activityAt) {
                return $activityAt;
            }
        }

        return $this->dateValue($lead->updated_at)
            ?: $this->dateValue($lead->created_at);
    }

    private function resolveSlaDueAt(LeadRequest $lead, ?Carbon $firstResponseAt): ?Carbon
    {
        $explicit = $this->dateValue($lead->sla_due_at);
        if ($explicit) {
            return $explicit;
        }

        if ($firstResponseAt !== null) {
            return null;
        }

        $createdAt = $this->dateValue($lead->created_at);
        if (! $createdAt) {
            return null;
        }

        return $createdAt->copy()->addHours(self::INITIAL_RESPONSE_SLA_HOURS);
    }

    private function resolveEffectiveDueAt(LeadRequest $lead, ?Carbon $slaDueAt): ?Carbon
    {
        return $this->dateValue($lead->next_follow_up_at)
            ?: $slaDueAt;
    }

    private function resolveStaleSinceAt(LeadRequest $lead, ?Carbon $lastActivityAt, bool $isOpen): ?Carbon
    {
        $explicit = $this->dateValue($lead->stale_since_at);
        if ($explicit) {
            return $explicit;
        }

        if (! $isOpen || ! $lastActivityAt) {
            return null;
        }

        return $lastActivityAt->copy()->addDays(self::STALE_DAYS);
    }

    private function defaultPriority(string $queue): int
    {
        return match ($queue) {
            self::QUEUE_BREACHED => 100,
            self::QUEUE_DUE_SOON => 80,
            self::QUEUE_NEW => 70,
            self::QUEUE_STALE => 60,
            self::QUEUE_ACTIVE => 30,
            default => 0,
        };
    }

    private function defaultRiskLevel(string $queue): string
    {
        return match ($queue) {
            self::QUEUE_BREACHED => 'critical',
            self::QUEUE_DUE_SOON, self::QUEUE_STALE => 'high',
            self::QUEUE_NEW => 'medium',
            self::QUEUE_ACTIVE => 'low',
            default => 'closed',
        };
    }

    private function dateValue(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
