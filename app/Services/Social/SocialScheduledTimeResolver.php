<?php

namespace App\Services\Social;

use App\Models\User;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

class SocialScheduledTimeResolver
{
    public function resolve(User $owner, mixed $value): ?Carbon
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->utc();
        }

        $raw = trim((string) $value);
        $timezone = new DateTimeZone($this->timezoneFor($owner));

        if (preg_match('/(?:Z|[+-]\d{2}:?\d{2})\z/i', $raw) === 1) {
            return $this->parseExplicitInstant($raw);
        }

        if (preg_match(
            '/\A(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?\z/',
            $raw,
            $parts,
        ) === 1) {
            return $this->resolveTenantWallClock($parts, $timezone);
        }

        try {
            return Carbon::parse($raw, $timezone)->utc();
        } catch (Throwable) {
            $this->throwInvalidTime();
        }
    }

    private function parseExplicitInstant(string $raw): Carbon
    {
        try {
            return Carbon::parse($raw)->utc();
        } catch (Throwable) {
            $this->throwInvalidTime();
        }
    }

    /**
     * @param  array<int, string>  $parts
     */
    private function resolveTenantWallClock(array $parts, DateTimeZone $timezone): Carbon
    {
        $year = (int) $parts[1];
        $month = (int) $parts[2];
        $day = (int) $parts[3];
        $hour = (int) $parts[4];
        $minute = (int) $parts[5];
        $second = (int) ($parts[6] ?? 0);

        if (! checkdate($month, $day, $year)
            || $hour > 23
            || $minute > 59
            || $second > 59) {
            $this->throwInvalidTime();
        }

        $wallClock = sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d',
            $year,
            $month,
            $day,
            $hour,
            $minute,
            $second,
        );
        $wallClockUtc = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $wallClock,
            new DateTimeZone('UTC'),
        );

        if (! $wallClockUtc || $wallClockUtc->format('Y-m-d H:i:s') !== $wallClock) {
            $this->throwInvalidTime();
        }

        $wallClockTimestamp = $wallClockUtc->getTimestamp();
        $offsets = collect($timezone->getTransitions(
            $wallClockTimestamp - (2 * 86400),
            $wallClockTimestamp + (2 * 86400),
        ))
            ->pluck('offset')
            ->map(fn (mixed $offset): int => (int) $offset)
            ->unique()
            ->values();
        $matches = $offsets
            ->map(fn (int $offset): int => $wallClockTimestamp - $offset)
            ->filter(function (int $timestamp) use ($timezone, $wallClock): bool {
                return (new DateTimeImmutable('@'.$timestamp))
                    ->setTimezone($timezone)
                    ->format('Y-m-d H:i:s') === $wallClock;
            })
            ->unique()
            ->values();

        if ($matches->count() !== 1) {
            throw ValidationException::withMessages([
                'scheduled_for' => $matches->isEmpty()
                    ? 'This time does not exist in the workspace timezone because of daylight saving time.'
                    : 'This time is ambiguous in the workspace timezone; choose another time or provide an explicit UTC offset.',
            ]);
        }

        return Carbon::createFromTimestampUTC((int) $matches->first());
    }

    private function timezoneFor(User $owner): string
    {
        $fallback = trim((string) config('app.timezone', 'UTC'));
        if (! in_array($fallback, timezone_identifiers_list(), true)) {
            $fallback = 'UTC';
        }

        $timezone = trim((string) ($owner->company_timezone ?: $fallback));

        return in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : $fallback;
    }

    private function throwInvalidTime(): never
    {
        throw ValidationException::withMessages([
            'scheduled_for' => 'Choose a valid date and time in the workspace timezone.',
        ]);
    }
}
