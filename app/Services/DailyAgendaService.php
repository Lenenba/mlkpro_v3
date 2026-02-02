<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TeamMember;
use App\Models\TeamMemberAttendance;
use App\Models\TeamMemberShift;
use App\Models\User;
use App\Models\Work;
use App\Notifications\ActionEmailNotification;
use App\Notifications\ShiftNoticeNotification;
use App\Support\NotificationDispatcher;
use App\Services\CompanyNotificationPreferenceService;
use App\Services\TaskBillingService;
use App\Services\TaskStatusHistoryService;
use App\Services\TaskTimingService;
use App\Services\WhatsappNotificationService;
use Carbon\Carbon;
use App\Services\SmsNotificationService;
use Illuminate\Support\Facades\URL;

class DailyAgendaService
{
    public function __construct(private PushNotificationService $push)
    {
    }

    public function process(?Carbon $now = null): array
    {
        $now = $now ? $now->copy() : now();

        return [
            'tasks_started' => $this->handleTaskStarts($now),
            'works_started' => $this->handleWorkStarts($now),
            'tasks_completed' => $this->handleTaskEndOfDay($now),
            'tasks_overdue' => $this->handleTaskOverdue($now),
            'clients_notified' => $this->handleClientNotifications($now),
            'shift_reminders' => $this->handleShiftReminders($now),
            'shift_late' => $this->handleShiftLateAlerts($now),
        ];
    }

    private function handleTaskStarts(Carbon $now): int
    {
        $fallbackTime = config('agenda.start_time_fallback', '09:00:00');

        $accountIds = Task::query()
            ->whereNotNull('due_date')
            ->whereIn('status', ['todo'])
            ->whereNull('start_alerted_at')
            ->distinct()
            ->pluck('account_id')
            ->filter();

        $count = 0;
        foreach ($accountIds as $accountId) {
            $timezone = TaskTimingService::resolveTimezoneForAccountId($accountId);
            $accountNow = $now->copy()->setTimezone($timezone);
            $today = $accountNow->toDateString();
            $timeNow = $accountNow->format('H:i:s');

            $tasksQuery = Task::query()
                ->forAccount($accountId)
                ->whereDate('due_date', $today)
                ->whereIn('status', ['todo'])
                ->whereNull('start_alerted_at');

            $tasksQuery->where(function ($query) use ($timeNow, $fallbackTime) {
                $query->whereNotNull('start_time')
                    ->where('start_time', '<=', $timeNow);

                if ($timeNow >= $fallbackTime) {
                    $query->orWhereNull('start_time');
                }
            });

            $tasks = $tasksQuery
                ->with(['assignee.user:id,name,locale', 'account:id,name,locale'])
                ->get([
                    'id',
                    'title',
                    'status',
                    'due_date',
                    'start_time',
                    'assigned_team_member_id',
                    'account_id',
                    'auto_started_at',
                ]);

            foreach ($tasks as $task) {
                $previousStatus = $task->status;
                $task->status = config('agenda.task_auto_start_status', 'in_progress');
                $task->auto_started_at = $task->auto_started_at ?? $now;
                $task->start_alerted_at = $now;
                $task->save();

                app(TaskStatusHistoryService::class)->record($task, null, [
                    'from_status' => $previousStatus,
                    'to_status' => $task->status,
                    'action' => 'auto_started',
                ]);

                $this->notifyTaskStart($task);
                $count += 1;
            }
        }

        return $count;
    }

    private function handleWorkStarts(Carbon $now): int
    {
        $fallbackTime = config('agenda.start_time_fallback', '09:00:00');

        $accountIds = Work::query()
            ->whereNotNull('start_date')
            ->where('status', Work::STATUS_SCHEDULED)
            ->whereNull('start_alerted_at')
            ->distinct()
            ->pluck('user_id')
            ->filter();

        $count = 0;
        foreach ($accountIds as $accountId) {
            $timezone = TaskTimingService::resolveTimezoneForAccountId($accountId);
            $accountNow = $now->copy()->setTimezone($timezone);
            $today = $accountNow->toDateString();
            $timeNow = $accountNow->format('H:i:s');

            $worksQuery = Work::query()
                ->where('user_id', $accountId)
                ->whereDate('start_date', $today)
                ->where('status', Work::STATUS_SCHEDULED)
                ->whereNull('start_alerted_at');

            $worksQuery->where(function ($query) use ($timeNow, $fallbackTime) {
                $query->whereNotNull('start_time')
                    ->where('start_time', '<=', $timeNow);

                if ($timeNow >= $fallbackTime) {
                    $query->orWhereNull('start_time');
                }
            });

            $works = $worksQuery
                ->with(['teamMembers.user:id,name,locale', 'user:id,name,locale'])
                ->get([
                    'id',
                    'job_title',
                    'status',
                    'start_date',
                    'start_time',
                    'user_id',
                    'auto_started_at',
                ]);

            foreach ($works as $work) {
                $work->status = config('agenda.work_auto_start_status', Work::STATUS_TECH_COMPLETE);
                $work->auto_started_at = $work->auto_started_at ?? $now;
                $work->start_alerted_at = $now;
                $work->save();

                $this->notifyWorkStart($work);
                $count += 1;
            }
        }

        return $count;
    }

    private function handleTaskEndOfDay(Carbon $now): int
    {
        if (!config('agenda.auto_complete_tasks', true)) {
            return 0;
        }

        $endOfDay = config('agenda.end_of_day', '18:00:00');
        $accountIds = Task::query()
            ->whereNotNull('due_date')
            ->where('status', '!=', 'done')
            ->whereNull('end_alerted_at')
            ->distinct()
            ->pluck('account_id')
            ->filter();

        $count = 0;
        foreach ($accountIds as $accountId) {
            $timezone = TaskTimingService::resolveTimezoneForAccountId($accountId);
            $accountNow = $now->copy()->setTimezone($timezone);
            if ($accountNow->format('H:i:s') < $endOfDay) {
                continue;
            }

            $today = $accountNow->toDateString();
            $tasks = Task::query()
                ->forAccount($accountId)
                ->whereDate('due_date', $today)
                ->where('status', '!=', 'done')
                ->whereNull('end_alerted_at')
                ->with(['assignee.user:id,name,locale', 'account:id,name,locale'])
                ->get([
                    'id',
                    'title',
                    'status',
                    'due_date',
                    'assigned_team_member_id',
                    'account_id',
                    'auto_completed_at',
                ]);

            foreach ($tasks as $task) {
                $previousStatus = $task->status;
                $task->status = 'done';
                $task->completed_at = $now;
                $task->completion_reason = null;
                $task->delay_started_at = null;
                $task->auto_completed_at = $task->auto_completed_at ?? $now;
                $task->end_alerted_at = $now;
                $task->save();

                app(TaskStatusHistoryService::class)->record($task, null, [
                    'from_status' => $previousStatus,
                    'to_status' => $task->status,
                    'action' => 'auto_completed',
                ]);

                app(TaskBillingService::class)->handleTaskCompleted($task, null);

                $this->notifyTaskEndOfDay($task);
                $count += 1;
            }
        }

        return $count;
    }

    private function handleTaskOverdue(Carbon $now): int
    {
        $accountIds = Task::query()
            ->whereNotNull('due_date')
            ->where('status', '!=', 'done')
            ->whereNull('delay_started_at')
            ->distinct()
            ->pluck('account_id')
            ->filter();

        $count = 0;
        foreach ($accountIds as $accountId) {
            $today = TaskTimingService::todayForAccountId($accountId);

            $tasks = Task::query()
                ->forAccount($accountId)
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', $today)
                ->where('status', '!=', 'done')
                ->whereNull('delay_started_at')
                ->get(['id', 'status', 'due_date', 'completed_at', 'account_id']);

            foreach ($tasks as $task) {
                $task->delay_started_at = $now;
                $task->save();

                app(TaskStatusHistoryService::class)->record($task, null, [
                    'from_status' => $task->status,
                    'to_status' => $task->status,
                    'action' => 'overdue',
                ]);

                $count += 1;
            }
        }

        return $count;
    }

    private function handleClientNotifications(Carbon $now): int
    {
        $accountIds = Task::query()
            ->whereNotNull('due_date')
            ->whereNull('client_notified_at')
            ->where('status', '!=', 'done')
            ->distinct()
            ->pluck('account_id')
            ->filter();

        $count = 0;
        foreach ($accountIds as $accountId) {
            $timezone = TaskTimingService::resolveTimezoneForAccountId($accountId);
            $accountNow = $now->copy()->setTimezone($timezone);
            $today = $accountNow->toDateString();

            $owner = User::query()
                ->select(['id', 'name', 'locale', 'company_name', 'company_notification_settings'])
                ->find($accountId);
            if (!$owner) {
                continue;
            }

            $channels = app(CompanyNotificationPreferenceService::class)->taskDayChannels($owner);
            if (!array_filter($channels)) {
                continue;
            }

            $tasks = Task::query()
                ->forAccount($accountId)
                ->whereDate('due_date', $today)
                ->where('status', '!=', 'done')
                ->whereNull('client_notified_at')
                ->with([
                    'assignee.user:id,name',
                    'customer:id,company_name,first_name,last_name,email,phone',
                    'work:id,job_title,number',
                ])
                ->get([
                    'id',
                    'title',
                    'due_date',
                    'start_time',
                    'assigned_team_member_id',
                    'customer_id',
                    'work_id',
                    'account_id',
                ]);

            foreach ($tasks as $task) {
                $sent = $this->notifyClientTaskDay($task, $owner, $channels, $accountNow);
                if (!$sent) {
                    continue;
                }

                $task->client_notified_at = $now;
                $task->save();

                app(TaskStatusHistoryService::class)->record($task, null, [
                    'from_status' => $task->status,
                    'to_status' => $task->status,
                    'action' => 'client_notified',
                    'metadata' => [
                        'channels' => array_keys(array_filter($channels)),
                    ],
                ]);

                $count += 1;
            }
        }

        return $count;
    }

    private function handleShiftReminders(Carbon $now): int
    {
        $minutesBefore = (int) config('agenda.shift_reminder_minutes', 30);
        if ($minutesBefore <= 0) {
            return 0;
        }

        $accountIds = TeamMemberShift::query()
            ->where('kind', 'shift')
            ->whereNull('reminder_sent_at')
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhere('status', 'approved');
            })
            ->distinct()
            ->pluck('account_id')
            ->filter();

        $count = 0;
        foreach ($accountIds as $accountId) {
            $timezone = TaskTimingService::resolveTimezoneForAccountId($accountId);
            $accountNow = $now->copy()->setTimezone($timezone);
            $today = $accountNow->toDateString();
            $windowEnd = $accountNow->copy()->addMinutes($minutesBefore);

            $owner = User::query()->select(['id', 'name', 'locale'])->find($accountId);
            if (!$owner) {
                continue;
            }

            $shifts = TeamMemberShift::query()
                ->where('account_id', $accountId)
                ->where('kind', 'shift')
                ->whereDate('shift_date', $today)
                ->whereNull('reminder_sent_at')
                ->where(function ($query) {
                    $query->whereNull('status')
                        ->orWhere('status', 'approved');
                })
                ->with(['teamMember.user:id,name,locale'])
                ->get([
                    'id',
                    'team_member_id',
                    'shift_date',
                    'start_time',
                    'end_time',
                ]);

            foreach ($shifts as $shift) {
                if (!$shift->start_time) {
                    continue;
                }

                $startAt = Carbon::parse(
                    $shift->shift_date->toDateString() . ' ' . $shift->start_time,
                    $timezone
                );

                if ($startAt->lt($accountNow) || $startAt->gt($windowEnd)) {
                    continue;
                }

                $this->notifyShiftReminder($owner, $shift, $startAt);
                $shift->reminder_sent_at = $now;
                $shift->save();
                $count += 1;
            }
        }

        return $count;
    }

    private function handleShiftLateAlerts(Carbon $now): int
    {
        $graceMinutes = (int) config('agenda.shift_late_grace_minutes', 10);
        if ($graceMinutes < 0) {
            return 0;
        }

        $accountIds = TeamMemberShift::query()
            ->where('kind', 'shift')
            ->whereNull('late_alerted_at')
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhere('status', 'approved');
            })
            ->distinct()
            ->pluck('account_id')
            ->filter();

        $count = 0;
        foreach ($accountIds as $accountId) {
            $timezone = TaskTimingService::resolveTimezoneForAccountId($accountId);
            $accountNow = $now->copy()->setTimezone($timezone);
            $today = $accountNow->toDateString();

            $owner = User::query()->select(['id', 'name', 'locale'])->find($accountId);
            if (!$owner) {
                continue;
            }

            $shifts = TeamMemberShift::query()
                ->where('account_id', $accountId)
                ->where('kind', 'shift')
                ->whereDate('shift_date', $today)
                ->whereNull('late_alerted_at')
                ->where(function ($query) {
                    $query->whereNull('status')
                        ->orWhere('status', 'approved');
                })
                ->with(['teamMember.user:id,name,locale'])
                ->get([
                    'id',
                    'team_member_id',
                    'shift_date',
                    'start_time',
                    'end_time',
                ]);

            foreach ($shifts as $shift) {
                if (!$shift->start_time) {
                    continue;
                }

                $startAt = Carbon::parse(
                    $shift->shift_date->toDateString() . ' ' . $shift->start_time,
                    $timezone
                );
                $lateAt = $startAt->copy()->addMinutes($graceMinutes);

                if ($accountNow->lt($lateAt)) {
                    continue;
                }

                if ($shift->end_time) {
                    $endAt = Carbon::parse(
                        $shift->shift_date->toDateString() . ' ' . $shift->end_time,
                        $timezone
                    );
                    if ($accountNow->gt($endAt)) {
                        $shift->late_alerted_at = $now;
                        $shift->save();
                        continue;
                    }
                }

                $memberUser = $shift->teamMember?->user;
                if ($this->hasClockedInForShift($accountId, $memberUser, $startAt, $lateAt)) {
                    $shift->late_alerted_at = $now;
                    $shift->save();
                    continue;
                }

                $this->notifyShiftLate($owner, $shift, $startAt, $lateAt);
                $shift->late_alerted_at = $now;
                $shift->save();
                $count += 1;
            }
        }

        return $count;
    }

    private function notifyClientTaskDay(Task $task, User $owner, array $channels, Carbon $now): bool
    {
        $customer = $task->customer;
        if (!$customer) {
            return false;
        }

        $locale = $owner->locale ?? 'fr';
        $isFr = str_starts_with(strtolower($locale), 'fr');
        $assignee = $task->assignee?->user?->name;
        $timeLabel = $this->formatTimeLabel($task->start_time);

        $title = $isFr ? 'Intervention aujourd hui' : 'Service scheduled today';
        $intro = $isFr
            ? $this->buildFrenchIntro($timeLabel, $assignee)
            : $this->buildEnglishIntro($timeLabel, $assignee);

        $details = [
            ['label' => $isFr ? 'Tache' : 'Task', 'value' => $task->title ?: ($task->work?->job_title ?? 'Task')],
        ];

        if ($timeLabel) {
            $details[] = ['label' => $isFr ? 'Heure estimee' : 'Estimated time', 'value' => $timeLabel];
        }

        if ($assignee) {
            $details[] = ['label' => $isFr ? 'Technicien' : 'Technician', 'value' => $assignee];
        }

        $actionUrl = null;
        $actionLabel = null;
        if ($task->work_id) {
            $expiresAt = $now->copy()->addDays(2);
            $actionUrl = URL::temporarySignedRoute('public.works.show', $expiresAt, ['work' => $task->work_id]);
            $actionLabel = $isFr ? 'Voir le suivi' : 'View details';
        }

        $sent = false;
        if (!empty($channels[CompanyNotificationPreferenceService::CHANNEL_EMAIL]) && $customer->email) {
            $sent = NotificationDispatcher::send($customer, new ActionEmailNotification(
                $title,
                $intro,
                $details,
                $actionUrl,
                $actionLabel,
                $title
            ), [
                'task_id' => $task->id,
            ]) || $sent;
        }

        if (!empty($channels[CompanyNotificationPreferenceService::CHANNEL_SMS]) && $customer->phone) {
            $message = $intro ?: ($isFr ? 'Intervention prevue aujourd hui.' : 'Service scheduled today.');
            $sent = app(SmsNotificationService::class)->send($customer->phone, $message) || $sent;
        }

        if (!empty($channels[CompanyNotificationPreferenceService::CHANNEL_WHATSAPP]) && $customer->phone) {
            $message = $intro ?: ($isFr ? 'Intervention prevue aujourd hui.' : 'Service scheduled today.');
            $sent = app(WhatsappNotificationService::class)->send($customer->phone, $message) || $sent;
        }

        return $sent;
    }

    private function buildFrenchIntro(?string $timeLabel, ?string $assignee): string
    {
        $intro = 'Bonjour, notre technicien arrive aujourd hui';
        if ($timeLabel) {
            $intro .= ' vers ' . $timeLabel;
        }
        $intro .= '.';
        if ($assignee) {
            $intro .= ' Technicien: ' . $assignee . '.';
        }

        return $intro;
    }

    private function buildEnglishIntro(?string $timeLabel, ?string $assignee): string
    {
        $intro = 'Hello, our technician will arrive today';
        if ($timeLabel) {
            $intro .= ' around ' . $timeLabel;
        }
        $intro .= '.';
        if ($assignee) {
            $intro .= ' Technician: ' . $assignee . '.';
        }

        return $intro;
    }

    private function formatTimeLabel(?string $time): ?string
    {
        if (!$time) {
            return null;
        }

        $parts = explode(':', $time);
        if (count($parts) < 2) {
            return $time;
        }

        return $parts[0] . ':' . $parts[1];
    }

    private function notifyTaskStart(Task $task): void
    {
        $recipients = $this->resolveTaskRecipients($task);
        if ($recipients->isEmpty()) {
            return;
        }

        $title = $task->title ?: 'Tache';
        foreach ($recipients as $user) {
            $payload = $this->buildPayload($user, 'task_start', [
                'title' => $title,
                'assignee' => $task->assignee?->user?->name,
            ]);
            $this->push->sendToUsers([$user->id], $payload);
        }
    }

    private function notifyWorkStart(Work $work): void
    {
        $recipients = $this->resolveWorkRecipients($work);
        if ($recipients->isEmpty()) {
            return;
        }

        $title = $work->job_title ?: 'Chantier';
        foreach ($recipients as $user) {
            $payload = $this->buildPayload($user, 'work_start', [
                'title' => $title,
            ]);
            $this->push->sendToUsers([$user->id], $payload);
        }
    }

    private function notifyTaskEndOfDay(Task $task): void
    {
        $recipients = $this->resolveTaskRecipients($task);
        if ($recipients->isEmpty()) {
            return;
        }

        $title = $task->title ?: 'Tache';
        foreach ($recipients as $user) {
            $payload = $this->buildPayload($user, 'task_end', [
                'title' => $title,
                'assignee' => $task->assignee?->user?->name,
            ]);
            $this->push->sendToUsers([$user->id], $payload);
        }
    }

    private function resolveTaskRecipients(Task $task)
    {
        $users = collect();

        $assignee = $task->assignee?->user;
        if ($assignee) {
            $users->push($assignee);
        }

        $accountId = $task->account_id;
        if ($accountId) {
            $users = $users->merge($this->resolveAdminUsers($accountId));
        }

        return $users->filter()->unique('id');
    }

    private function resolveWorkRecipients(Work $work)
    {
        $users = collect();

        $teamUsers = $work->teamMembers
            ? $work->teamMembers->map(fn($member) => $member->user)->filter()
            : collect();
        $users = $users->merge($teamUsers);

        if ($work->user_id) {
            $users = $users->merge($this->resolveAdminUsers($work->user_id));
        }

        return $users->filter()->unique('id');
    }

    private function resolveAdminUsers(int $accountId)
    {
        $owner = User::query()->find($accountId);

        $admins = TeamMember::query()
            ->forAccount($accountId)
            ->active()
            ->where('role', 'admin')
            ->with('user:id,name,locale')
            ->get()
            ->map(fn($member) => $member->user)
            ->filter();

        return collect([$owner])->merge($admins)->filter()->unique('id');
    }

    private function resolveShiftManagers(int $accountId)
    {
        $owners = $this->resolveAdminUsers($accountId);

        $managers = TeamMember::query()
            ->forAccount($accountId)
            ->active()
            ->whereIn('role', ['sales_manager'])
            ->with('user:id,name,locale')
            ->get()
            ->map(fn($member) => $member->user)
            ->filter();

        return $owners->merge($managers)->filter()->unique('id');
    }

    private function hasClockedInForShift(
        int $accountId,
        ?User $memberUser,
        Carbon $startAt,
        Carbon $lateAt
    ): bool {
        if (!$memberUser) {
            return false;
        }

        $appTimezone = config('app.timezone', 'UTC');
        $startUtc = $startAt->copy()->setTimezone($appTimezone);
        $lateUtc = $lateAt->copy()->setTimezone($appTimezone);

        return TeamMemberAttendance::query()
            ->where('account_id', $accountId)
            ->where('user_id', $memberUser->id)
            ->where('clock_in_at', '<=', $lateUtc)
            ->where(function ($query) use ($startUtc) {
                $query->whereNull('clock_out_at')
                    ->orWhere('clock_out_at', '>=', $startUtc);
            })
            ->exists();
    }

    private function notifyShiftReminder(User $owner, TeamMemberShift $shift, Carbon $startAt): void
    {
        $memberUser = $shift->teamMember?->user;
        $memberName = $memberUser?->name ?? 'Employe';
        $timeLabel = $startAt->format('H:i');
        $recipients = $this->resolveShiftManagers($owner->id);
        if ($memberUser) {
            $recipients = $recipients->merge([$memberUser]);
        }
        $recipients = $recipients->filter()->unique('id');

        foreach ($recipients as $user) {
            $locale = $user->locale ?? 'fr';
            $isFr = str_starts_with(strtolower($locale), 'fr');
            $isSelf = $memberUser && $user->id === $memberUser->id;
            $title = $isFr ? 'Rappel de shift' : 'Shift reminder';
            if ($isSelf) {
                $message = $isFr
                    ? "Ton shift commence a {$timeLabel}."
                    : "Your shift starts at {$timeLabel}.";
            } else {
                $message = $isFr
                    ? "Shift pour {$memberName} a {$timeLabel}."
                    : "{$memberName}'s shift starts at {$timeLabel}.";
            }

            $user->notify(new ShiftNoticeNotification(
                $title,
                $message,
                route('planning.index'),
                [
                    'type' => 'shift_reminder',
                    'shift_id' => $shift->id,
                    'team_member_id' => $shift->team_member_id,
                    'shift_date' => $shift->shift_date?->toDateString(),
                    'start_time' => $timeLabel,
                ]
            ));
        }
    }

    private function notifyShiftLate(
        User $owner,
        TeamMemberShift $shift,
        Carbon $startAt,
        Carbon $lateAt
    ): void {
        $memberUser = $shift->teamMember?->user;
        $memberName = $memberUser?->name ?? 'Employe';
        $timeLabel = $startAt->format('H:i');
        $recipients = $this->resolveShiftManagers($owner->id);
        if ($memberUser) {
            $recipients = $recipients->merge([$memberUser]);
        }
        $recipients = $recipients->filter()->unique('id');

        foreach ($recipients as $user) {
            $locale = $user->locale ?? 'fr';
            $isFr = str_starts_with(strtolower($locale), 'fr');
            $isSelf = $memberUser && $user->id === $memberUser->id;
            $title = $isFr ? 'Retard de shift' : 'Shift late';
            if ($isSelf) {
                $message = $isFr
                    ? "Tu es en retard pour ton shift de {$timeLabel}."
                    : "You are late for your {$timeLabel} shift.";
            } else {
                $message = $isFr
                    ? "Retard: {$memberName} devait commencer a {$timeLabel}."
                    : "Late: {$memberName} was scheduled at {$timeLabel}.";
            }

            $user->notify(new ShiftNoticeNotification(
                $title,
                $message,
                route('planning.index'),
                [
                    'type' => 'shift_late',
                    'shift_id' => $shift->id,
                    'team_member_id' => $shift->team_member_id,
                    'shift_date' => $shift->shift_date?->toDateString(),
                    'start_time' => $timeLabel,
                    'late_after_minutes' => (int) config('agenda.shift_late_grace_minutes', 10),
                ]
            ));
        }
    }

    private function buildPayload(User $user, string $type, array $context): array
    {
        $locale = $user->locale ?? 'fr';
        $isFr = str_starts_with(strtolower($locale), 'fr');

        $title = $context['title'] ?? '';
        $assignee = $context['assignee'] ?? null;

        if ($type === 'task_start') {
            return [
                'title' => $isFr ? 'Tache a demarrer' : 'Task should start',
                'body' => $isFr
                    ? "La tache \"{$title}\" doit commencer maintenant." . ($assignee ? " Assigne: {$assignee}." : '')
                    : "Task \"{$title}\" should start now." . ($assignee ? " Assignee: {$assignee}." : ''),
                'data' => [
                    'type' => 'task_start',
                ],
            ];
        }

        if ($type === 'task_end') {
            return [
                'title' => $isFr ? 'Tache terminee automatiquement' : 'Task auto-completed',
                'body' => $isFr
                    ? "La tache \"{$title}\" a ete terminee automatiquement a 18h."
                    : "Task \"{$title}\" was auto-completed at 6pm.",
                'data' => [
                    'type' => 'task_end',
                ],
            ];
        }

        return [
            'title' => $isFr ? 'Chantier a demarrer' : 'Job should start',
            'body' => $isFr
                ? "Le chantier \"{$title}\" doit commencer maintenant."
                : "Job \"{$title}\" should start now.",
            'data' => [
                'type' => 'work_start',
            ],
        ];
    }
}
