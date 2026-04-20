<?php

namespace App\Services\Playbooks;

use App\Models\Playbook;
use App\Models\PlaybookRun;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PlaybookSchedulerService
{
    public function __construct(
        private readonly PlaybookExecutionService $playbookExecutionService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function runDue(?int $accountId = null, ?Carbon $referenceTime = null): array
    {
        $referenceTime ??= now();

        $duePlaybookIds = Playbook::query()
            ->where('is_active', true)
            ->whereIn('schedule_type', [
                Playbook::SCHEDULE_DAILY,
                Playbook::SCHEDULE_WEEKLY,
            ])
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $referenceTime)
            ->when($accountId, fn ($query) => $query->where('user_id', $accountId))
            ->orderBy('next_run_at')
            ->orderBy('id')
            ->pluck('id');

        $summary = [
            'checked_count' => $duePlaybookIds->count(),
            'reserved_count' => 0,
            'executed_count' => 0,
            'failed_count' => 0,
            'skipped_overlap_count' => 0,
            'run_ids' => [],
            'playbook_ids' => [],
        ];

        foreach ($duePlaybookIds as $playbookId) {
            $reservation = $this->reserveDuePlaybook((int) $playbookId, $referenceTime);

            if (($reservation['status'] ?? null) === 'overlap') {
                $summary['skipped_overlap_count']++;

                continue;
            }

            if (($reservation['status'] ?? null) !== 'reserved') {
                continue;
            }

            $summary['reserved_count']++;
            $summary['playbook_ids'][] = (int) $reservation['playbook_id'];

            $playbook = Playbook::query()
                ->with('savedSegment')
                ->find((int) $reservation['playbook_id']);
            $owner = User::query()->find((int) $reservation['owner_id']);
            $run = PlaybookRun::query()->find((int) $reservation['run_id']);

            if (! $playbook || ! $owner || ! $run) {
                $summary['failed_count']++;

                continue;
            }

            $completedRun = $this->playbookExecutionService->executeReserved($playbook, $owner, $run);

            $summary['run_ids'][] = $completedRun->id;
            if ($completedRun->status === PlaybookRun::STATUS_COMPLETED) {
                $summary['executed_count']++;
            } else {
                $summary['failed_count']++;
            }
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function reserveDuePlaybook(int $playbookId, ?Carbon $referenceTime = null): array
    {
        $referenceTime ??= now();

        return DB::transaction(function () use ($playbookId, $referenceTime): array {
            /** @var Playbook|null $playbook */
            $playbook = Playbook::query()
                ->with('savedSegment')
                ->lockForUpdate()
                ->find($playbookId);

            if (! $playbook || ! $this->isDue($playbook, $referenceTime)) {
                return [
                    'status' => 'skipped',
                ];
            }

            if ($this->hasActiveRun($playbook)) {
                return [
                    'status' => 'overlap',
                    'playbook_id' => $playbook->id,
                ];
            }

            $owner = User::query()->find($playbook->user_id);
            if (! $owner) {
                return [
                    'status' => 'skipped',
                ];
            }

            $scheduledFor = $playbook->next_run_at?->copy() ?? $referenceTime->copy();
            $run = $this->playbookExecutionService->reserve(
                $playbook,
                $owner,
                PlaybookRun::ORIGIN_SCHEDULED,
                $scheduledFor,
            );

            $playbook->forceFill([
                'next_run_at' => $this->nextRunAt($playbook, $scheduledFor),
                'updated_by_user_id' => $owner->id,
            ])->save();

            return [
                'status' => 'reserved',
                'playbook_id' => $playbook->id,
                'run_id' => $run->id,
                'owner_id' => $owner->id,
                'scheduled_for' => $scheduledFor->toIso8601String(),
            ];
        });
    }

    public function nextRunAt(Playbook $playbook, Carbon $scheduledFor): ?Carbon
    {
        $timezone = (string) ($playbook->schedule_timezone ?: config('app.timezone', 'UTC'));
        $scheduledLocal = $scheduledFor->copy()->timezone($timezone);
        [$hour, $minute] = $this->resolveScheduleTime($playbook, $scheduledLocal);

        return match ((string) $playbook->schedule_type) {
            Playbook::SCHEDULE_DAILY => $this->nextDailyRunAt($scheduledLocal, $hour, $minute),
            Playbook::SCHEDULE_WEEKLY => $this->nextWeeklyRunAt($playbook, $scheduledLocal, $hour, $minute),
            default => null,
        };
    }

    private function isDue(Playbook $playbook, Carbon $referenceTime): bool
    {
        if (! $playbook->is_active) {
            return false;
        }

        if (! in_array((string) $playbook->schedule_type, [
            Playbook::SCHEDULE_DAILY,
            Playbook::SCHEDULE_WEEKLY,
        ], true)) {
            return false;
        }

        return $playbook->next_run_at !== null
            && $playbook->next_run_at->lte($referenceTime);
    }

    private function hasActiveRun(Playbook $playbook): bool
    {
        return PlaybookRun::query()
            ->where('playbook_id', $playbook->id)
            ->whereIn('status', [
                PlaybookRun::STATUS_PENDING,
                PlaybookRun::STATUS_RUNNING,
            ])
            ->exists();
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function resolveScheduleTime(Playbook $playbook, Carbon $scheduledLocal): array
    {
        $time = trim((string) ($playbook->schedule_time ?: ''));
        if ($time === '' || ! preg_match('/^\d{2}:\d{2}$/', $time)) {
            return [(int) $scheduledLocal->hour, (int) $scheduledLocal->minute];
        }

        [$hour, $minute] = array_map('intval', explode(':', $time, 2));

        return [$hour, $minute];
    }

    private function nextDailyRunAt(Carbon $scheduledLocal, int $hour, int $minute): Carbon
    {
        $nextLocal = $scheduledLocal->copy()
            ->addDay()
            ->setTime($hour, $minute);

        if ($nextLocal->lte($scheduledLocal)) {
            $nextLocal->addDay();
        }

        return $nextLocal->utc();
    }

    private function nextWeeklyRunAt(Playbook $playbook, Carbon $scheduledLocal, int $hour, int $minute): Carbon
    {
        $targetDay = $this->normalizeDayOfWeek($playbook->schedule_day_of_week, $scheduledLocal);
        $nextLocal = $scheduledLocal->copy()
            ->addDay()
            ->setTime($hour, $minute);

        while ((int) $nextLocal->dayOfWeek !== $targetDay) {
            $nextLocal->addDay();
        }

        if ($nextLocal->lte($scheduledLocal)) {
            $nextLocal->addWeek();
        }

        return $nextLocal->utc();
    }

    private function normalizeDayOfWeek(?int $configuredDay, Carbon $scheduledLocal): int
    {
        if ($configuredDay === null) {
            return (int) $scheduledLocal->dayOfWeek;
        }

        if ($configuredDay === 7) {
            return 0;
        }

        return max(0, min(6, $configuredDay));
    }
}
