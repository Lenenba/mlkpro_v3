<?php

namespace App\Services\Reservation;

use App\Models\AvailabilityException;
use App\Models\Reservation;
use App\Models\WeeklyAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ReservationAvailabilityWindowService
{
    public function parseToUtc(string $value, string $timezone): Carbon
    {
        return Carbon::parse($value, $timezone)->utc();
    }

    public function assertWithinAvailability(
        int $accountId,
        int $teamMemberId,
        Carbon $startUtc,
        Carbon $endUtc,
        string $timezone
    ): void {
        $startLocal = $startUtc->copy()->setTimezone($timezone);
        $endLocal = $endUtc->copy()->setTimezone($timezone);
        if ($endLocal->toDateString() !== $startLocal->toDateString()) {
            throw ValidationException::withMessages([
                'starts_at' => ['Reservations cannot span multiple days.'],
            ]);
        }

        $weekly = WeeklyAvailability::query()
            ->forAccount($accountId)
            ->forTeamMember($teamMemberId)
            ->active()
            ->orderBy('start_time')
            ->get();

        $exceptions = AvailabilityException::query()
            ->forAccount($accountId)
            ->whereDate('date', $startLocal->toDateString())
            ->where(function ($query) use ($teamMemberId) {
                $query->whereNull('team_member_id')
                    ->orWhere('team_member_id', $teamMemberId);
            })
            ->get();

        $intervals = $this->buildDayIntervals(
            $teamMemberId,
            $startLocal->copy()->startOfDay(),
            $weekly,
            $exceptions,
            $timezone
        );

        $fits = collect($intervals)->contains(function (array $interval) use ($startLocal, $endLocal) {
            return $startLocal->gte($interval['start']) && $endLocal->lte($interval['end']);
        });

        if (! $fits) {
            throw ValidationException::withMessages([
                'starts_at' => ['Selected slot is outside configured availability.'],
            ]);
        }
    }

    public function assertNoDoubleBooking(
        int $accountId,
        int $teamMemberId,
        Carbon $startUtc,
        Carbon $endUtc,
        int $bufferMinutes,
        ?int $ignoreReservationId,
        int $maxBufferMinutes
    ): void {
        $windowStart = $startUtc->copy()->subMinutes($maxBufferMinutes);
        $windowEnd = $endUtc->copy()->addMinutes($maxBufferMinutes);

        $existing = Reservation::query()
            ->forAccount($accountId)
            ->where('team_member_id', $teamMemberId)
            ->whereIn('status', Reservation::ACTIVE_STATUSES)
            ->when($ignoreReservationId, fn ($query) => $query->where('id', '!=', $ignoreReservationId))
            ->where('starts_at', '<', $windowEnd)
            ->where('ends_at', '>', $windowStart)
            ->lockForUpdate()
            ->get();

        foreach ($existing as $reservation) {
            $effectiveBuffer = max(
                $bufferMinutes,
                min($maxBufferMinutes, (int) ($reservation->buffer_minutes ?? 0))
            );
            $blockedStart = $reservation->starts_at->copy()->subMinutes($effectiveBuffer);
            $blockedEnd = $reservation->ends_at->copy()->addMinutes($effectiveBuffer);

            if ($startUtc->lt($blockedEnd) && $endUtc->gt($blockedStart)) {
                throw ValidationException::withMessages([
                    'starts_at' => ['Selected slot is no longer available.'],
                ]);
            }
        }
    }

    public function hasReservationConflict(
        Carbon $slotStart,
        Carbon $slotEnd,
        Collection $memberReservations,
        int $bufferMinutes,
        string $timezone,
        int $maxBufferMinutes
    ): bool {
        foreach ($memberReservations as $reservation) {
            $effectiveBuffer = max(
                $bufferMinutes,
                min($maxBufferMinutes, (int) ($reservation->buffer_minutes ?? 0))
            );
            $busyStart = $reservation->starts_at->copy()->setTimezone($timezone)->subMinutes($effectiveBuffer);
            $busyEnd = $reservation->ends_at->copy()->setTimezone($timezone)->addMinutes($effectiveBuffer);

            if ($slotStart->lt($busyEnd) && $slotEnd->gt($busyStart)) {
                return true;
            }
        }

        return false;
    }

    public function passesNoticeRules(Carbon $slotStart, Carbon $nowLocal, array $settings): bool
    {
        $minNotice = max(0, (int) ($settings['min_notice_minutes'] ?? 0));
        if ($minNotice > 0 && $slotStart->lt($nowLocal->copy()->addMinutes($minNotice))) {
            return false;
        }

        $maxAdvanceDays = max(1, (int) ($settings['max_advance_days'] ?? 90));
        if ($slotStart->gt($nowLocal->copy()->addDays($maxAdvanceDays))) {
            return false;
        }

        return true;
    }

    public function buildDayIntervals(
        int $teamMemberId,
        Carbon $date,
        Collection $weeklyRows,
        Collection $exceptions,
        string $timezone
    ): array {
        $dayOfWeek = (int) $date->dayOfWeek;
        $weeklyIntervals = $weeklyRows
            ->filter(fn (WeeklyAvailability $row) => (int) $row->team_member_id === $teamMemberId)
            ->filter(fn (WeeklyAvailability $row) => (int) $row->day_of_week === $dayOfWeek)
            ->map(function (WeeklyAvailability $row) use ($date, $timezone) {
                $start = Carbon::parse($date->format('Y-m-d').' '.$row->start_time, $timezone);
                $end = Carbon::parse($date->format('Y-m-d').' '.$row->end_time, $timezone);

                return ['start' => $start, 'end' => $end];
            })
            ->filter(fn (array $interval) => $interval['end']->gt($interval['start']))
            ->values()
            ->all();

        $dayExceptions = $exceptions
            ->filter(fn (AvailabilityException $item) => $item->date?->toDateString() === $date->toDateString())
            ->filter(fn (AvailabilityException $item) => $item->team_member_id === null || (int) $item->team_member_id === $teamMemberId)
            ->values();

        $openIntervals = [];
        $closedIntervals = [];
        foreach ($dayExceptions as $exception) {
            if ($exception->start_time && $exception->end_time) {
                $start = Carbon::parse($date->format('Y-m-d').' '.$exception->start_time, $timezone);
                $end = Carbon::parse($date->format('Y-m-d').' '.$exception->end_time, $timezone);
            } else {
                $start = $date->copy()->startOfDay();
                $end = $date->copy()->endOfDay();
            }

            if ($end->lte($start)) {
                continue;
            }

            if ($exception->type === AvailabilityException::TYPE_OPEN) {
                $openIntervals[] = ['start' => $start, 'end' => $end];

                continue;
            }

            $closedIntervals[] = ['start' => $start, 'end' => $end];
        }

        $intervals = $this->normalizeIntervals(array_merge($weeklyIntervals, $openIntervals));
        foreach ($closedIntervals as $closed) {
            $intervals = $this->subtractIntervals($intervals, $closed);
        }

        return $this->normalizeIntervals($intervals);
    }

    public function alignToInterval(Carbon $dateTime, int $intervalMinutes): Carbon
    {
        $minutes = ((int) $dateTime->format('H')) * 60 + (int) $dateTime->format('i');
        $remainder = $minutes % $intervalMinutes;
        if ($remainder > 0) {
            $dateTime->addMinutes($intervalMinutes - $remainder);
        }

        return $dateTime->second(0);
    }

    /**
     * @return array<int, Carbon>
     */
    public function dateRange(Carbon $from, Carbon $to): array
    {
        $dates = [];
        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDay()) {
            $dates[] = $cursor->copy();
        }

        return $dates;
    }

    private function normalizeIntervals(array $intervals): array
    {
        if (! $intervals) {
            return [];
        }

        usort($intervals, function (array $left, array $right) {
            if ($left['start']->eq($right['start'])) {
                return $left['end']->lt($right['end']) ? -1 : 1;
            }

            return $left['start']->lt($right['start']) ? -1 : 1;
        });

        $normalized = [];
        foreach ($intervals as $interval) {
            if (empty($normalized)) {
                $normalized[] = $interval;

                continue;
            }

            $lastIndex = count($normalized) - 1;
            $last = $normalized[$lastIndex];
            if ($interval['start']->lte($last['end'])) {
                if ($interval['end']->gt($last['end'])) {
                    $normalized[$lastIndex]['end'] = $interval['end'];
                }

                continue;
            }

            $normalized[] = $interval;
        }

        return $normalized;
    }

    private function subtractIntervals(array $intervals, array $closed): array
    {
        $results = [];
        foreach ($intervals as $interval) {
            if ($closed['end']->lte($interval['start']) || $closed['start']->gte($interval['end'])) {
                $results[] = $interval;

                continue;
            }

            if ($closed['start']->gt($interval['start'])) {
                $results[] = [
                    'start' => $interval['start'],
                    'end' => $closed['start']->copy(),
                ];
            }

            if ($closed['end']->lt($interval['end'])) {
                $results[] = [
                    'start' => $closed['end']->copy(),
                    'end' => $interval['end'],
                ];
            }
        }

        return $results;
    }
}
