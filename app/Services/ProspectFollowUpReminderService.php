<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Request as LeadRequest;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ProspectFollowUpReminderNotification;
use App\Support\NotificationDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ProspectFollowUpReminderService
{
    public const ACTION_DUE_TODAY = 'prospect_follow_up_due_today_reminder_sent';

    public const ACTION_OVERDUE = 'prospect_follow_up_overdue_reminder_sent';

    /**
     * @return array{scanned:int,due_today:int,overdue:int,sent:int,skipped:int}
     */
    public function process(?Carbon $now = null, bool $dryRun = false): array
    {
        $referenceTime = $now?->copy() ?? now();
        $accountIds = Task::query()
            ->open()
            ->whereNotNull('request_id')
            ->whereNotNull('due_date')
            ->distinct()
            ->pluck('account_id')
            ->filter()
            ->map(fn ($value) => (int) $value)
            ->values();

        $summary = [
            'scanned' => 0,
            'due_today' => 0,
            'overdue' => 0,
            'sent' => 0,
            'skipped' => 0,
        ];

        foreach ($accountIds as $accountId) {
            $timezone = TaskTimingService::resolveTimezoneForAccountId($accountId);
            $today = $referenceTime->copy()->setTimezone($timezone)->toDateString();

            $tasks = Task::query()
                ->forAccount($accountId)
                ->open()
                ->whereNotNull('request_id')
                ->whereNotNull('due_date')
                ->where(function ($query) use ($today) {
                    $query->whereDate('due_date', $today)
                        ->orWhereDate('due_date', '<', $today);
                })
                ->whereHas('request', fn ($query) => $query->whereNull('archived_at'))
                ->with([
                    'account:id,name,email,locale,company_name,company_logo',
                    'assignee.user:id,name,email,locale',
                    'request:id,user_id,assigned_team_member_id,title,contact_name,contact_email,archived_at',
                    'request.user:id,name,email,locale,company_name,company_logo',
                    'request.assignee.user:id,name,email,locale',
                ])
                ->orderBy('due_date')
                ->orderByDesc('created_at')
                ->get();

            foreach ($tasks as $task) {
                $summary['scanned']++;

                $type = $task->due_date && $task->due_date->toDateString() < $today
                    ? ProspectFollowUpReminderNotification::TYPE_OVERDUE
                    : ProspectFollowUpReminderNotification::TYPE_DUE_TODAY;

                if ($type === ProspectFollowUpReminderNotification::TYPE_OVERDUE) {
                    $summary['overdue']++;
                } else {
                    $summary['due_today']++;
                }

                if ($this->alreadySentForDate($task, $type, $today)) {
                    $summary['skipped']++;

                    continue;
                }

                if ($dryRun) {
                    continue;
                }

                if ($this->sendReminder($task, $type, $today)) {
                    $summary['sent']++;
                } else {
                    $summary['skipped']++;
                }
            }
        }

        return $summary;
    }

    private function alreadySentForDate(Task $task, string $type, string $reminderDate): bool
    {
        $lastReminder = ActivityLog::query()
            ->where('subject_type', $task->getMorphClass())
            ->where('subject_id', $task->id)
            ->where('action', $this->actionForType($type))
            ->latest('created_at')
            ->first();

        return data_get($lastReminder?->properties, 'reminder_date') === $reminderDate;
    }

    private function sendReminder(Task $task, string $type, string $reminderDate): bool
    {
        $recipients = $this->resolveRecipients($task);
        if ($recipients->isEmpty()) {
            return false;
        }

        $sent = false;

        foreach ($recipients as $recipient) {
            $sent = NotificationDispatcher::send(
                $recipient,
                new ProspectFollowUpReminderNotification($task, $type),
                [
                    'task_id' => $task->id,
                    'lead_id' => $task->request_id,
                    'reminder_type' => $type,
                    'recipient_id' => $recipient->id,
                ]
            ) || $sent;
        }

        if ($sent) {
            $this->recordAudit($task, $type, $reminderDate, $recipients->count());
        }

        return $sent;
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveRecipients(Task $task): Collection
    {
        $lead = $task->request;

        return collect([
            $task->account,
            $lead?->user,
            $task->assignee?->user,
            $lead?->assignee?->user,
        ])
            ->filter(fn ($recipient) => $recipient instanceof User)
            ->unique('id')
            ->values();
    }

    private function recordAudit(Task $task, string $type, string $reminderDate, int $recipientCount): void
    {
        $action = $this->actionForType($type);
        $description = $type === ProspectFollowUpReminderNotification::TYPE_OVERDUE
            ? 'Prospect follow-up overdue reminder sent'
            : 'Prospect follow-up due today reminder sent';
        $properties = [
            'task_id' => $task->id,
            'lead_id' => $task->request_id,
            'reminder_type' => $type,
            'reminder_date' => $reminderDate,
            'due_date' => $task->due_date?->toDateString(),
            'recipient_count' => $recipientCount,
        ];

        ActivityLog::record(null, $task, $action, $properties, $description);

        if ($task->request instanceof LeadRequest) {
            ActivityLog::record(null, $task->request, $action, $properties, $description);
        }
    }

    private function actionForType(string $type): string
    {
        return $type === ProspectFollowUpReminderNotification::TYPE_OVERDUE
            ? self::ACTION_OVERDUE
            : self::ACTION_DUE_TODAY;
    }
}
