<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TeamMember;
use App\Models\Work;
use Illuminate\Support\Carbon;

class WorkScheduleService
{
    public function generateTasks(Work $work, ?int $createdByUserId = null): int
    {
        if (!$work->start_date) {
            return 0;
        }

        $dates = $this->buildOccurrenceDates($work);
        if (!$dates) {
            return 0;
        }

        $accountId = $work->user_id;
        $existingDates = Task::query()
            ->where('work_id', $work->id)
            ->whereNotNull('due_date')
            ->pluck('due_date')
            ->map(fn($date) => $date->toDateString())
            ->all();
        $existingDates = array_flip($existingDates);

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

        $tasks = [];
        foreach ($dates as $index => $date) {
            $dateString = $date->toDateString();
            if (isset($existingDates[$dateString])) {
                continue;
            }

            $assigneeId = $assigneeCount ? $assigneeIds[$index % $assigneeCount] : null;

            $tasks[] = [
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
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (!$tasks) {
            return 0;
        }

        Task::insert($tasks);

        return count($tasks);
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
}
