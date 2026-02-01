<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class ShiftScheduleService
{
    public function buildOccurrenceDates(
        string $startDate,
        ?string $endDate = null,
        ?string $frequency = null,
        array $repeatsOn = [],
        ?int $totalShifts = null
    ): array {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = $endDate ? Carbon::parse($endDate)->startOfDay() : null;
        $maxVisits = max(0, (int) ($totalShifts ?? 0));
        $frequency = strtolower((string) ($frequency ?: ''));

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
