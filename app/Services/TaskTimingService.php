<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;

class TaskTimingService
{
    public const STATUS_EARLY = 'early';
    public const STATUS_LATE = 'late';
    public const STATUS_ON_TIME = 'on_time';
    public const STATUS_UNSCHEDULED = 'unscheduled';

    private const COMPLETION_REASONS = [
        'client_available',
        'urgent_request',
        'optimized_planning',
        'team_available',
        'materials_ready',
    ];

    private static array $timezoneCache = [];

    public static function completionReasons(): array
    {
        return self::COMPLETION_REASONS;
    }

    public static function isValidCompletionReason(?string $reason): bool
    {
        return $reason !== null && in_array($reason, self::COMPLETION_REASONS, true);
    }

    public static function resolveTimingStatus(Task $task, ?Carbon $now = null): ?string
    {
        $timezone = self::resolveTimezoneForTask($task);
        $now = $now ? $now->copy()->setTimezone($timezone) : Carbon::now($timezone);

        $dueDate = self::resolveDueDate($task, $timezone);
        if (!$dueDate) {
            return null;
        }

        $completedAt = self::resolveCompletedAt($task, $timezone);

        if ($task->status === 'done' || $completedAt) {
            if (!$completedAt) {
                return self::STATUS_ON_TIME;
            }

            if ($completedAt->lt($dueDate)) {
                return self::STATUS_EARLY;
            }

            if ($completedAt->gt($dueDate)) {
                return self::STATUS_LATE;
            }

            return self::STATUS_ON_TIME;
        }

        $today = $now->copy()->startOfDay();
        if ($dueDate->lt($today)) {
            return self::STATUS_LATE;
        }

        return self::STATUS_ON_TIME;
    }

    public static function shouldRequireCompletionReason(?Carbon $dueDate, ?Carbon $completedAt): bool
    {
        if (!$dueDate || !$completedAt) {
            return false;
        }

        return $completedAt->copy()->startOfDay()->ne($dueDate->copy()->startOfDay());
    }

    public static function isDueDateInFuture(?Carbon $dueDate, ?Carbon $now = null): bool
    {
        if (!$dueDate) {
            return false;
        }

        $now = $now ?: Carbon::now($dueDate->getTimezone());

        return $dueDate->copy()->startOfDay()->gt($now->copy()->startOfDay());
    }

    public static function resolveTimezoneForTask(Task $task): string
    {
        if ($task->relationLoaded('account') && $task->account) {
            return self::resolveTimezoneForAccount($task->account);
        }

        $accountId = $task->account_id;
        if (!$accountId) {
            return config('app.timezone', 'UTC');
        }

        return self::resolveTimezoneForAccountId($accountId);
    }

    public static function resolveTimezoneForAccountId(int $accountId): string
    {
        if (!array_key_exists($accountId, self::$timezoneCache)) {
            $timezone = User::query()->whereKey($accountId)->value('company_timezone');
            self::$timezoneCache[$accountId] = $timezone ?: config('app.timezone', 'UTC');
        }

        return self::$timezoneCache[$accountId];
    }

    public static function resolveTimezoneForAccount(?User $user): string
    {
        if (!$user) {
            return config('app.timezone', 'UTC');
        }

        return $user->company_timezone ?: config('app.timezone', 'UTC');
    }

    public static function todayForAccountId(int $accountId): string
    {
        $timezone = self::resolveTimezoneForAccountId($accountId);
        return Carbon::now($timezone)->toDateString();
    }

    public static function normalizeCompletedAt($value, string $timezone): ?Carbon
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value, $timezone);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    public static function resolveDueDate(Task $task, string $timezone): ?Carbon
    {
        if (!$task->due_date) {
            return null;
        }

        return Carbon::parse($task->due_date, $timezone)->startOfDay();
    }

    public static function resolveCompletedAt(Task $task, string $timezone): ?Carbon
    {
        if (!$task->completed_at) {
            return null;
        }

        return Carbon::parse($task->completed_at, $timezone)->startOfDay();
    }
}
