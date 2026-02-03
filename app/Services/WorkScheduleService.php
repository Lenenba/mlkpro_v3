<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TeamMember;
use App\Models\Work;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use App\Notifications\TaskAssignmentConflictNotification;
use App\Services\NotificationPreferenceService;
use App\Services\UsageLimitService;
use App\Support\NotificationDispatcher;

class WorkScheduleService
{
    public function generateTasks(Work $work, ?int $createdByUserId = null): int
    {
        $pendingDates = $this->resolvePendingDates($work);
        if (!$pendingDates) {
            return 0;
        }

        $accountId = $work->user_id;
        $assigneeIds = $work->teamMembers()->pluck('team_members.id')->all();
        if (!$assigneeIds) {
            $assigneeIds = TeamMember::query()
                ->forAccount($accountId)
                ->active()
                ->pluck('id')
                ->all();
        }
        $assigneeCount = count($assigneeIds);

        $startTime = $work->start_time ? Carbon::parse($work->start_time)->format('H:i:s') : null;
        $endTime = $work->end_time ? Carbon::parse($work->end_time)->format('H:i:s') : null;

        $materialTemplate = $this->buildMaterialTemplate($work);
        $createdCount = 0;

        $pendingCount = count($pendingDates);
        if ($pendingCount > 0) {
            $owner = User::query()->find($accountId);
            if ($owner) {
                app(UsageLimitService::class)->enforceLimit($owner, 'tasks', $pendingCount);
            }
        }

        return $this->generateTasksForDates($work, $pendingDates, $createdByUserId, $assigneeIds, $materialTemplate);
    }

    public function generateTasksForDates(
        Work $work,
        array $dateInputs,
        ?int $createdByUserId = null,
        ?array $assigneeIds = null,
        ?array $materialTemplate = null,
        ?array &$conflicts = null
    ): int {
        if (!$dateInputs) {
            return 0;
        }

        $dateStrings = collect($dateInputs)
            ->map(function ($date) {
                if ($date instanceof Carbon) {
                    return $date->toDateString();
                }
                return Carbon::parse($date)->toDateString();
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (!$dateStrings) {
            return 0;
        }

        $existingDates = Task::query()
            ->where('work_id', $work->id)
            ->whereNotNull('due_date')
            ->whereIn('due_date', $dateStrings)
            ->pluck('due_date')
            ->map(fn($date) => $date->toDateString())
            ->all();
        $existingDates = array_flip($existingDates);

        $accountId = $work->user_id;
        $assigneeIds = $assigneeIds ?? $work->teamMembers()->pluck('team_members.id')->all();
        if (!$assigneeIds) {
            $assigneeIds = TeamMember::query()
                ->forAccount($accountId)
                ->active()
                ->pluck('id')
                ->all();
        }
        $assigneeCount = count($assigneeIds);

        $startTime = $work->start_time ? Carbon::parse($work->start_time)->format('H:i:s') : null;
        $endTime = $work->end_time ? Carbon::parse($work->end_time)->format('H:i:s') : null;

        $materialTemplate = $materialTemplate ?? $this->buildMaterialTemplate($work);
        $createdCount = 0;
        $conflictItems = [];
        $rangeForNewTask = $this->buildTimeRange($startTime, $endTime);
        $busyMap = $this->buildBusyMap($accountId, $assigneeIds, $dateStrings);

        foreach ($dateStrings as $index => $dateString) {
            if (isset($existingDates[$dateString])) {
                continue;
            }

            $assigneeId = $assigneeCount ? $assigneeIds[$index % $assigneeCount] : null;
            $hasConflict = false;
            if ($assigneeId && $rangeForNewTask && !empty($busyMap[$assigneeId][$dateString])) {
                foreach ($busyMap[$assigneeId][$dateString] as $range) {
                    if ($this->rangesOverlap($rangeForNewTask, $range)) {
                        $hasConflict = true;
                        break;
                    }
                }
            }
            if ($hasConflict) {
                $conflictItems[] = [
                    'date' => $dateString,
                    'member_id' => $assigneeId,
                ];
                $assigneeId = null;
            }

            $task = Task::create([
                'account_id' => $accountId,
                'created_by_user_id' => $createdByUserId,
                'assigned_team_member_id' => $assigneeId,
                'customer_id' => $work->customer_id,
                'product_id' => null,
                'work_id' => $work->id,
                'title' => $work->job_title,
                'description' => $work->instructions,
                'status' => 'todo',
                'due_date' => $dateString,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'completed_at' => null,
            ]);

            $createdCount++;

            if ($materialTemplate) {
                $task->materials()->createMany($materialTemplate);
            }
        }

        if ($conflicts !== null) {
            $conflicts = $conflictItems;
        }
        if ($conflictItems) {
            $this->notifyAssignmentConflicts($work, count($conflictItems));
        }

        return $createdCount;
    }

    public function countPendingTasks(Work $work): int
    {
        return count($this->resolvePendingDates($work));
    }

    public function pendingDateStrings(Work $work): array
    {
        return collect($this->resolvePendingDates($work))
            ->map(fn($date) => $date->toDateString())
            ->values()
            ->all();
    }

    private function buildOccurrenceDates(Work $work): array
    {
        if (!$work->start_date) {
            return [];
        }

        $start = Carbon::parse($work->start_date)->startOfDay();
        $end = $work->end_date ? Carbon::parse($work->end_date)->startOfDay() : null;
        $maxVisits = max(0, (int) ($work->totalVisits ?? 0));
        $frequency = strtolower((string) ($work->frequency ?? ''));
        $repeatsOn = is_array($work->repeatsOn) ? $work->repeatsOn : [];

        if (!$end && $maxVisits <= 0) {
            return [$start];
        }

        $weekdayMap = [
            'su' => 0,
            'mo' => 1,
            'tu' => 2,
            'we' => 3,
            'th' => 4,
            'fr' => 5,
            'sa' => 6,
        ];

        $repeatWeekdays = [];
        $repeatMonthDays = [];

        foreach ($repeatsOn as $value) {
            $key = strtolower((string) $value);
            if (array_key_exists($key, $weekdayMap)) {
                $repeatWeekdays[] = $weekdayMap[$key];
                continue;
            }

            $dayNumber = (int) $value;
            if ($dayNumber > 0) {
                $repeatMonthDays[] = $dayNumber;
            }
        }

        if (!$repeatWeekdays) {
            $repeatWeekdays = [$start->dayOfWeek];
        }

        if (!$repeatMonthDays) {
            $repeatMonthDays = [$start->day];
        }

        $dates = [];

        $estimateMultiplier = 1;
        if ($frequency === 'monthly') {
            $estimateMultiplier = 31;
        } elseif ($frequency === 'weekly') {
            $estimateMultiplier = 7;
        }

        $maxIterations = $end
            ? max(1, $start->diffInDays($end) + 1)
            : max(1, $maxVisits * $estimateMultiplier);
        $maxIterations = min($maxIterations, 365 * 3);

        $cursor = $start->copy();
        while ($maxIterations > 0) {
            if ($end && $cursor->gt($end)) {
                break;
            }

            $shouldAdd = false;
            switch ($frequency) {
                case 'daily':
                    $shouldAdd = true;
                    break;
                case 'monthly':
                    $shouldAdd = in_array($cursor->day, $repeatMonthDays, true);
                    break;
                case 'yearly':
                    $shouldAdd = $cursor->day === $start->day && $cursor->month === $start->month;
                    break;
                case 'weekly':
                default:
                    $shouldAdd = in_array($cursor->dayOfWeek, $repeatWeekdays, true);
                    break;
            }

            if ($shouldAdd) {
                $dates[] = $cursor->copy();
                if ($maxVisits > 0 && count($dates) >= $maxVisits) {
                    break;
                }
            }

            $cursor->addDay();
            $maxIterations--;
        }

        return $dates;
    }

    private function resolvePendingDates(Work $work): array
    {
        if (!$work->start_date) {
            return [];
        }

        $dates = $this->buildOccurrenceDates($work);
        if (!$dates) {
            return [];
        }

        $existingDates = Task::query()
            ->where('work_id', $work->id)
            ->whereNotNull('due_date')
            ->pluck('due_date')
            ->map(fn($date) => $date->toDateString())
            ->all();
        $existingDates = array_flip($existingDates);

        $pendingDates = [];
        foreach ($dates as $date) {
            $dateString = $date->toDateString();
            if (!isset($existingDates[$dateString])) {
                $pendingDates[] = $date;
            }
        }

        return $pendingDates;
    }

    private function buildMaterialTemplate(Work $work): array
    {
        $services = $work->products()
            ->where('item_type', Product::ITEM_TYPE_SERVICE)
            ->with('serviceMaterials')
            ->get();

        if ($services->isEmpty()) {
            return [];
        }

        $materials = [];

        foreach ($services as $service) {
            $pivotQuantity = (float) ($service->pivot?->quantity ?? 1);
            $pivotQuantity = $pivotQuantity > 0 ? $pivotQuantity : 1;

            foreach ($service->serviceMaterials as $material) {
                $quantity = (float) $material->quantity * $pivotQuantity;

                $materials[] = [
                    'product_id' => $material->product_id,
                    'source_service_id' => $service->id,
                    'label' => $material->label,
                    'description' => $material->description,
                    'unit' => $material->unit,
                    'quantity' => max(0, $quantity),
                    'unit_price' => max(0, (float) $material->unit_price),
                    'billable' => (bool) $material->billable,
                    'sort_order' => (int) $material->sort_order,
                ];
            }
        }

        return $materials;
    }

    private function buildBusyMap(int $accountId, array $assigneeIds, array $dateStrings): array
    {
        if (!$assigneeIds || !$dateStrings) {
            return [];
        }

        $minDate = min($dateStrings);
        $maxDate = max($dateStrings);
        if (!$minDate || !$maxDate) {
            return [];
        }

        $tasks = Task::query()
            ->forAccount($accountId)
            ->whereIn('assigned_team_member_id', $assigneeIds)
            ->whereBetween('due_date', [$minDate, $maxDate])
            ->get(['assigned_team_member_id', 'due_date', 'start_time', 'end_time']);

        $map = [];
        foreach ($tasks as $task) {
            $memberId = (int) $task->assigned_team_member_id;
            if (!$memberId) {
                continue;
            }

            $dateValue = $task->due_date instanceof Carbon
                ? $task->due_date->toDateString()
                : (string) $task->due_date;
            if ($dateValue === '') {
                continue;
            }

            $range = $this->buildTimeRange($task->start_time, $task->end_time);
            if (!$range) {
                continue;
            }

            $map[$memberId][$dateValue][] = $range;
        }

        return $map;
    }

    private function buildTimeRange(?string $startTime, ?string $endTime): ?array
    {
        if (!$startTime) {
            return [
                'start' => 0,
                'end' => 24 * 60,
                'all_day' => true,
            ];
        }

        $start = $this->timeToMinutes($startTime);
        if ($start === null) {
            return null;
        }

        $end = $this->timeToMinutes($endTime) ?? $start;
        if ($end < $start) {
            $end = $start;
        }

        return [
            'start' => $start,
            'end' => $end,
            'all_day' => false,
        ];
    }

    private function rangesOverlap(array $left, array $right): bool
    {
        return $left['start'] <= $right['end'] && $left['end'] >= $right['start'];
    }

    private function timeToMinutes(?string $value): ?int
    {
        if (!$value) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        foreach (['H:i:s', 'H:i'] as $format) {
            try {
                $time = Carbon::createFromFormat($format, $value);
                return ($time->hour * 60) + $time->minute;
            } catch (\Throwable $exception) {
                continue;
            }
        }

        return null;
    }

    private function notifyAssignmentConflicts(Work $work, int $conflictCount): void
    {
        if ($conflictCount <= 0) {
            return;
        }

        $owner = User::query()->find($work->user_id);
        if (!$owner) {
            return;
        }

        $preferences = app(NotificationPreferenceService::class);
        if (!$preferences->shouldNotify($owner, NotificationPreferenceService::CATEGORY_PLANNING)) {
            return;
        }

        NotificationDispatcher::send($owner, new TaskAssignmentConflictNotification(
            $work,
            $conflictCount
        ));
    }
}
