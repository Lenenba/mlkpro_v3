<?php

namespace App\Actions\Tasks;

use App\Models\ActivityLog;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ActionEmailNotification;
use App\Notifications\ShiftNoticeNotification;
use App\Support\NotificationDispatcher;

class AssignTaskAction
{
    public function execute(Task $task, ?int $nextAssigneeId, ?User $actor): Task
    {
        $previousAssigneeId = $task->assigned_team_member_id ? (int) $task->assigned_team_member_id : null;

        $task->update([
            'assigned_team_member_id' => $nextAssigneeId,
        ]);

        $task->loadMissing(['assignee.user:id,name', 'request:id,title,service_type']);

        ActivityLog::record($actor, $task, 'reassigned', [
            'previous_assigned_team_member_id' => $previousAssigneeId,
            'assigned_team_member_id' => $task->assigned_team_member_id,
            'request_id' => $task->request_id,
        ], 'Task assignee updated from lead page');

        if ($nextAssigneeId && $nextAssigneeId !== $previousAssigneeId) {
            $assigneeUser = $task->assignee?->user;
            if ($assigneeUser && (! $actor || $assigneeUser->id !== $actor->id)) {
                $taskLabel = trim((string) ($task->title ?: 'Task #'.$task->id));
                $leadLabel = trim((string) (
                    $task->request?->title
                    ?: $task->request?->service_type
                    ?: ($task->request_id ? 'Request #'.$task->request_id : '')
                ));
                $taskUrl = route('task.show', ['task' => $task->id]);

                $message = "You have been assigned to {$taskLabel}.";
                if ($leadLabel !== '') {
                    $message .= " Lead: {$leadLabel}.";
                }

                NotificationDispatcher::send($assigneeUser, new ShiftNoticeNotification(
                    'Task assigned',
                    $message,
                    $taskUrl,
                    [
                        'event' => 'task_assigned',
                        'task_id' => $task->id,
                        'request_id' => $task->request_id,
                    ]
                ), [
                    'task_id' => $task->id,
                    'request_id' => $task->request_id,
                    'assigned_user_id' => $assigneeUser->id,
                ]);

                if (! empty($assigneeUser->email)) {
                    NotificationDispatcher::send($assigneeUser, new ActionEmailNotification(
                        'Task assigned',
                        $message,
                        [
                            ['label' => 'Task', 'value' => $taskLabel],
                            ['label' => 'Lead', 'value' => $leadLabel !== '' ? $leadLabel : '-'],
                            ['label' => 'Due date', 'value' => $task->due_date ? $task->due_date->format('Y-m-d') : '-'],
                        ],
                        $taskUrl,
                        'Open task'
                    ), [
                        'task_id' => $task->id,
                        'request_id' => $task->request_id,
                        'assigned_user_id' => $assigneeUser->id,
                        'channel' => 'mail',
                    ]);
                }
            }
        }

        return $task;
    }
}
