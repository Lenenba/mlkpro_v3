<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;

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
        ];
    }

    private function handleTaskStarts(Carbon $now): int
    {
        $today = $now->toDateString();
        $timeNow = $now->format('H:i:s');
        $fallbackTime = config('agenda.start_time_fallback', '09:00:00');

        $tasksQuery = Task::query()
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

        $count = 0;
        foreach ($tasks as $task) {
            $task->status = config('agenda.task_auto_start_status', 'in_progress');
            $task->auto_started_at = $task->auto_started_at ?? $now;
            $task->start_alerted_at = $now;
            $task->save();

            $this->notifyTaskStart($task);
            $count += 1;
        }

        return $count;
    }

    private function handleWorkStarts(Carbon $now): int
    {
        $today = $now->toDateString();
        $timeNow = $now->format('H:i:s');
        $fallbackTime = config('agenda.start_time_fallback', '09:00:00');

        $worksQuery = Work::query()
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

        $count = 0;
        foreach ($works as $work) {
            $work->status = config('agenda.work_auto_start_status', Work::STATUS_TECH_COMPLETE);
            $work->auto_started_at = $work->auto_started_at ?? $now;
            $work->start_alerted_at = $now;
            $work->save();

            $this->notifyWorkStart($work);
            $count += 1;
        }

        return $count;
    }

    private function handleTaskEndOfDay(Carbon $now): int
    {
        if (!config('agenda.auto_complete_tasks', true)) {
            return 0;
        }

        $endOfDay = config('agenda.end_of_day', '18:00:00');
        if ($now->format('H:i:s') < $endOfDay) {
            return 0;
        }

        $today = $now->toDateString();
        $tasks = Task::query()
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

        $count = 0;
        foreach ($tasks as $task) {
            $task->status = 'done';
            $task->completed_at = $now;
            $task->auto_completed_at = $task->auto_completed_at ?? $now;
            $task->end_alerted_at = $now;
            $task->save();

            $this->notifyTaskEndOfDay($task);
            $count += 1;
        }

        return $count;
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
