<?php

namespace App\Services;

use App\Models\AvailabilityException;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\ReservationSetting;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\WeeklyAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationAvailabilityService
{
    private const MAX_BUFFER_MINUTES = 240;

    public function resolveAccountForUser(User $user): ?User
    {
        if ($user->isClient()) {
            $customer = $user->relationLoaded('customerProfile')
                ? $user->customerProfile
                : $user->customerProfile()->first();
            if ($customer && $customer->user_id) {
                return User::query()->find($customer->user_id);
            }
        }

        $accountId = $user->accountOwnerId();
        if (!$accountId) {
            return null;
        }

        return $accountId === $user->id
            ? $user
            : User::query()->find($accountId);
    }

    public function timezoneForAccount(?User $account): string
    {
        if (!$account) {
            return config('app.timezone', 'UTC');
        }

        return $account->company_timezone ?: config('app.timezone', 'UTC');
    }

    public function resolveSettings(int $accountId, ?int $teamMemberId = null): array
    {
        $accountLevel = ReservationSetting::query()
            ->forAccount($accountId)
            ->whereNull('team_member_id')
            ->first();

        $teamLevel = null;
        if ($teamMemberId) {
            $teamLevel = ReservationSetting::query()
                ->forAccount($accountId)
                ->where('team_member_id', $teamMemberId)
                ->first();
        }

        $defaults = [
            'buffer_minutes' => 0,
            'slot_interval_minutes' => 30,
            'min_notice_minutes' => 0,
            'max_advance_days' => 90,
            'cancellation_cutoff_hours' => 12,
            'allow_client_cancel' => true,
            'allow_client_reschedule' => true,
        ];

        return [
            'buffer_minutes' => (int) ($teamLevel?->buffer_minutes ?? $accountLevel?->buffer_minutes ?? $defaults['buffer_minutes']),
            'slot_interval_minutes' => (int) ($teamLevel?->slot_interval_minutes ?? $accountLevel?->slot_interval_minutes ?? $defaults['slot_interval_minutes']),
            'min_notice_minutes' => (int) ($teamLevel?->min_notice_minutes ?? $accountLevel?->min_notice_minutes ?? $defaults['min_notice_minutes']),
            'max_advance_days' => (int) ($teamLevel?->max_advance_days ?? $accountLevel?->max_advance_days ?? $defaults['max_advance_days']),
            'cancellation_cutoff_hours' => (int) ($teamLevel?->cancellation_cutoff_hours ?? $accountLevel?->cancellation_cutoff_hours ?? $defaults['cancellation_cutoff_hours']),
            'allow_client_cancel' => (bool) ($teamLevel?->allow_client_cancel ?? $accountLevel?->allow_client_cancel ?? $defaults['allow_client_cancel']),
            'allow_client_reschedule' => (bool) ($teamLevel?->allow_client_reschedule ?? $accountLevel?->allow_client_reschedule ?? $defaults['allow_client_reschedule']),
        ];
    }

    public function resolveDurationMinutes(int $accountId, ?int $serviceId, ?int $durationMinutes): int
    {
        if ($durationMinutes && $durationMinutes > 0) {
            return (int) $durationMinutes;
        }

        if ($serviceId) {
            $service = Product::query()
                ->services()
                ->where('user_id', $accountId)
                ->whereKey($serviceId)
                ->first();
            if ($service) {
                return 60;
            }
        }

        return 60;
    }

    public function generateSlots(
        int $accountId,
        Carbon $rangeStartUtc,
        Carbon $rangeEndUtc,
        int $durationMinutes,
        ?int $teamMemberId = null
    ): array {
        $account = User::query()->find($accountId);
        if (!$account) {
            return ['timezone' => config('app.timezone', 'UTC'), 'slots' => []];
        }

        $timezone = $this->timezoneForAccount($account);
        $startUtc = $rangeStartUtc->copy()->utc();
        $endUtc = $rangeEndUtc->copy()->utc();
        if ($endUtc->lte($startUtc) || $durationMinutes <= 0) {
            return ['timezone' => $timezone, 'slots' => []];
        }

        $startLocal = $startUtc->copy()->setTimezone($timezone);
        $endLocal = $endUtc->copy()->setTimezone($timezone);

        $memberQuery = TeamMember::query()
            ->forAccount($accountId)
            ->active()
            ->with('user:id,name');
        if ($teamMemberId) {
            $memberQuery->whereKey($teamMemberId);
        }
        $members = $memberQuery->get();

        if ($members->isEmpty()) {
            return ['timezone' => $timezone, 'slots' => []];
        }

        $memberIds = $members->pluck('id')->all();

        $weekly = WeeklyAvailability::query()
            ->forAccount($accountId)
            ->whereIn('team_member_id', $memberIds)
            ->active()
            ->orderBy('start_time')
            ->get()
            ->groupBy('team_member_id');

        $exceptions = AvailabilityException::query()
            ->forAccount($accountId)
            ->whereDate('date', '>=', $startLocal->toDateString())
            ->whereDate('date', '<=', $endLocal->toDateString())
            ->where(function ($query) use ($memberIds) {
                $query->whereNull('team_member_id')
                    ->orWhereIn('team_member_id', $memberIds);
            })
            ->get();

        $reservations = Reservation::query()
            ->forAccount($accountId)
            ->whereIn('team_member_id', $memberIds)
            ->whereIn('status', Reservation::ACTIVE_STATUSES)
            ->where('starts_at', '<', $endUtc->copy()->addMinutes(self::MAX_BUFFER_MINUTES))
            ->where('ends_at', '>', $startUtc->copy()->subMinutes(self::MAX_BUFFER_MINUTES))
            ->orderBy('starts_at')
            ->get()
            ->groupBy('team_member_id');

        $slots = [];
        $nowLocal = now($timezone);
        $dates = $this->dateRange($startLocal->copy()->startOfDay(), $endLocal->copy()->startOfDay());

        foreach ($members as $member) {
            $settings = $this->resolveSettings($accountId, $member->id);
            $buffer = max(0, min(self::MAX_BUFFER_MINUTES, (int) $settings['buffer_minutes']));
            $intervalMinutes = max(5, min(120, (int) $settings['slot_interval_minutes']));
            $memberWeekly = $weekly->get($member->id, collect());
            $memberReservations = $reservations->get($member->id, collect());

            foreach ($dates as $date) {
                $dayIntervals = $this->buildDayIntervals(
                    $member->id,
                    $date,
                    $memberWeekly,
                    $exceptions,
                    $timezone
                );
                if (!$dayIntervals) {
                    continue;
                }

                foreach ($dayIntervals as $interval) {
                    $cursor = $this->alignToInterval($interval['start']->copy(), $intervalMinutes);
                    while ($cursor->copy()->addMinutes($durationMinutes)->lte($interval['end'])) {
                        $slotStart = $cursor->copy();
                        $slotEnd = $slotStart->copy()->addMinutes($durationMinutes);

                        if ($slotStart->lt($startLocal) || $slotEnd->gt($endLocal)) {
                            $cursor->addMinutes($intervalMinutes);
                            continue;
                        }

                        if (!$this->passesNoticeRules($slotStart, $nowLocal, $settings)) {
                            $cursor->addMinutes($intervalMinutes);
                            continue;
                        }

                        if ($this->hasReservationConflict($slotStart, $slotEnd, $memberReservations, $buffer, $timezone)) {
                            $cursor->addMinutes($intervalMinutes);
                            continue;
                        }

                        $slots[] = [
                            'team_member_id' => $member->id,
                            'team_member_name' => $member->user?->name ?? 'Member',
                            'starts_at' => $slotStart->copy()->utc()->toIso8601String(),
                            'ends_at' => $slotEnd->copy()->utc()->toIso8601String(),
                            'label' => $slotStart->format('D, M j - H:i'),
                            'date' => $slotStart->toDateString(),
                            'time' => $slotStart->format('H:i'),
                        ];

                        $cursor->addMinutes($intervalMinutes);
                    }
                }
            }
        }

        usort($slots, function (array $left, array $right) {
            $leftKey = $left['starts_at'] . ':' . $left['team_member_id'];
            $rightKey = $right['starts_at'] . ':' . $right['team_member_id'];
            return strcmp($leftKey, $rightKey);
        });

        return [
            'timezone' => $timezone,
            'slots' => $slots,
        ];
    }

    public function book(array $payload, User $actor): Reservation
    {
        $accountId = (int) $payload['account_id'];
        $teamMemberId = (int) $payload['team_member_id'];
        $account = User::query()->findOrFail($accountId);
        $timezone = $this->timezoneForAccount($account);

        $durationMinutes = $this->resolveDurationMinutes(
            $accountId,
            isset($payload['service_id']) ? (int) $payload['service_id'] : null,
            isset($payload['duration_minutes']) ? (int) $payload['duration_minutes'] : null
        );

        $startUtc = $this->parseToUtc((string) $payload['starts_at'], $payload['timezone'] ?? $timezone);
        $endUtc = !empty($payload['ends_at'])
            ? $this->parseToUtc((string) $payload['ends_at'], $payload['timezone'] ?? $timezone)
            : $startUtc->copy()->addMinutes($durationMinutes);

        if ($endUtc->lte($startUtc)) {
            throw ValidationException::withMessages([
                'starts_at' => ['The end time must be after the start time.'],
            ]);
        }

        $durationMinutes = $startUtc->diffInMinutes($endUtc);

        return DB::transaction(function () use (
            $payload,
            $accountId,
            $teamMemberId,
            $actor,
            $timezone,
            $startUtc,
            $endUtc,
            $durationMinutes
        ) {
            $teamMember = TeamMember::query()
                ->forAccount($accountId)
                ->active()
                ->whereKey($teamMemberId)
                ->lockForUpdate()
                ->first();
            if (!$teamMember) {
                throw ValidationException::withMessages([
                    'team_member_id' => ['Selected team member is not available.'],
                ]);
            }

            $settings = $this->resolveSettings($accountId, $teamMemberId);
            $bufferMinutes = max(0, min(
                self::MAX_BUFFER_MINUTES,
                (int) ($payload['buffer_minutes'] ?? $settings['buffer_minutes'])
            ));

            $this->assertWithinAvailability($accountId, $teamMemberId, $startUtc, $endUtc, $timezone);
            $this->assertNoDoubleBooking($accountId, $teamMemberId, $startUtc, $endUtc, $bufferMinutes, null);

            return Reservation::query()->create([
                'account_id' => $accountId,
                'team_member_id' => $teamMemberId,
                'client_id' => $payload['client_id'] ?? null,
                'client_user_id' => $payload['client_user_id'] ?? null,
                'service_id' => $payload['service_id'] ?? null,
                'created_by_user_id' => $actor->id,
                'status' => $payload['status'] ?? Reservation::STATUS_PENDING,
                'source' => $payload['source'] ?? Reservation::SOURCE_STAFF,
                'timezone' => $timezone,
                'starts_at' => $startUtc,
                'ends_at' => $endUtc,
                'duration_minutes' => $durationMinutes,
                'buffer_minutes' => $bufferMinutes,
                'internal_notes' => $payload['internal_notes'] ?? null,
                'client_notes' => $payload['client_notes'] ?? null,
                'metadata' => $payload['metadata'] ?? null,
            ]);
        });
    }

    public function reschedule(
        Reservation $reservation,
        array $payload,
        User $actor
    ): Reservation {
        $accountId = (int) $reservation->account_id;
        $account = User::query()->findOrFail($accountId);
        $timezone = $this->timezoneForAccount($account);
        $newTeamMemberId = isset($payload['team_member_id'])
            ? (int) $payload['team_member_id']
            : (int) $reservation->team_member_id;

        $durationMinutes = $this->resolveDurationMinutes(
            $accountId,
            isset($payload['service_id']) ? (int) $payload['service_id'] : (int) $reservation->service_id,
            isset($payload['duration_minutes']) ? (int) $payload['duration_minutes'] : (int) $reservation->duration_minutes
        );

        $startUtc = $this->parseToUtc((string) $payload['starts_at'], $payload['timezone'] ?? $timezone);
        $endUtc = !empty($payload['ends_at'])
            ? $this->parseToUtc((string) $payload['ends_at'], $payload['timezone'] ?? $timezone)
            : $startUtc->copy()->addMinutes($durationMinutes);

        if ($endUtc->lte($startUtc)) {
            throw ValidationException::withMessages([
                'starts_at' => ['The end time must be after the start time.'],
            ]);
        }

        $durationMinutes = $startUtc->diffInMinutes($endUtc);

        return DB::transaction(function () use (
            $reservation,
            $payload,
            $actor,
            $accountId,
            $newTeamMemberId,
            $timezone,
            $startUtc,
            $endUtc,
            $durationMinutes
        ) {
            $teamMember = TeamMember::query()
                ->forAccount($accountId)
                ->active()
                ->whereKey($newTeamMemberId)
                ->lockForUpdate()
                ->first();
            if (!$teamMember) {
                throw ValidationException::withMessages([
                    'team_member_id' => ['Selected team member is not available.'],
                ]);
            }

            $settings = $this->resolveSettings($accountId, $newTeamMemberId);
            $bufferMinutes = max(0, min(
                self::MAX_BUFFER_MINUTES,
                (int) ($payload['buffer_minutes'] ?? $reservation->buffer_minutes ?? $settings['buffer_minutes'])
            ));

            $this->assertWithinAvailability($accountId, $newTeamMemberId, $startUtc, $endUtc, $timezone);
            $this->assertNoDoubleBooking($accountId, $newTeamMemberId, $startUtc, $endUtc, $bufferMinutes, $reservation->id);

            $reservation->forceFill([
                'team_member_id' => $newTeamMemberId,
                'service_id' => $payload['service_id'] ?? $reservation->service_id,
                'status' => $payload['status'] ?? $reservation->status,
                'starts_at' => $startUtc,
                'ends_at' => $endUtc,
                'duration_minutes' => $durationMinutes,
                'buffer_minutes' => $bufferMinutes,
                'timezone' => $timezone,
                'internal_notes' => array_key_exists('internal_notes', $payload)
                    ? $payload['internal_notes']
                    : $reservation->internal_notes,
                'client_notes' => array_key_exists('client_notes', $payload)
                    ? $payload['client_notes']
                    : $reservation->client_notes,
                'metadata' => array_key_exists('metadata', $payload)
                    ? $payload['metadata']
                    : $reservation->metadata,
                'cancelled_at' => null,
                'cancel_reason' => null,
                'cancelled_by_user_id' => null,
            ])->save();

            return $reservation->fresh();
        });
    }

    public function canClientModify(Reservation $reservation): bool
    {
        $settings = $this->resolveSettings($reservation->account_id, $reservation->team_member_id);
        $cutoffHours = max(0, (int) $settings['cancellation_cutoff_hours']);
        if ($cutoffHours <= 0) {
            return true;
        }

        $cutoffAt = $reservation->starts_at->copy()->subHours($cutoffHours);
        return now('UTC')->lt($cutoffAt);
    }

    private function parseToUtc(string $value, string $timezone): Carbon
    {
        return Carbon::parse($value, $timezone)->utc();
    }

    private function assertWithinAvailability(
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

        if (!$fits) {
            throw ValidationException::withMessages([
                'starts_at' => ['Selected slot is outside configured availability.'],
            ]);
        }
    }

    private function assertNoDoubleBooking(
        int $accountId,
        int $teamMemberId,
        Carbon $startUtc,
        Carbon $endUtc,
        int $bufferMinutes,
        ?int $ignoreReservationId
    ): void {
        $windowStart = $startUtc->copy()->subMinutes(self::MAX_BUFFER_MINUTES);
        $windowEnd = $endUtc->copy()->addMinutes(self::MAX_BUFFER_MINUTES);

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
                min(self::MAX_BUFFER_MINUTES, (int) ($reservation->buffer_minutes ?? 0))
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

    private function hasReservationConflict(
        Carbon $slotStart,
        Carbon $slotEnd,
        Collection $memberReservations,
        int $bufferMinutes,
        string $timezone
    ): bool {
        foreach ($memberReservations as $reservation) {
            $effectiveBuffer = max(
                $bufferMinutes,
                min(self::MAX_BUFFER_MINUTES, (int) ($reservation->buffer_minutes ?? 0))
            );
            $busyStart = $reservation->starts_at->copy()->setTimezone($timezone)->subMinutes($effectiveBuffer);
            $busyEnd = $reservation->ends_at->copy()->setTimezone($timezone)->addMinutes($effectiveBuffer);

            if ($slotStart->lt($busyEnd) && $slotEnd->gt($busyStart)) {
                return true;
            }
        }

        return false;
    }

    private function passesNoticeRules(Carbon $slotStart, Carbon $nowLocal, array $settings): bool
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

    private function buildDayIntervals(
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
                $start = Carbon::parse($date->format('Y-m-d') . ' ' . $row->start_time, $timezone);
                $end = Carbon::parse($date->format('Y-m-d') . ' ' . $row->end_time, $timezone);
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
                $start = Carbon::parse($date->format('Y-m-d') . ' ' . $exception->start_time, $timezone);
                $end = Carbon::parse($date->format('Y-m-d') . ' ' . $exception->end_time, $timezone);
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

    private function normalizeIntervals(array $intervals): array
    {
        if (!$intervals) {
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

    private function alignToInterval(Carbon $dateTime, int $intervalMinutes): Carbon
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
    private function dateRange(Carbon $from, Carbon $to): array
    {
        $dates = [];
        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDay()) {
            $dates[] = $cursor->copy();
        }
        return $dates;
    }
}
