<?php

namespace App\Notifications;

use App\Models\Work;
use App\Services\NotificationPreferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TaskAssignmentConflictNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Work $work,
        public int $conflictCount
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $number = $this->work->number ?: $this->work->id;
        $label = $this->conflictCount === 1 ? 'task' : 'tasks';

        return [
            'title' => 'Unassigned tasks created',
            'message' => "{$this->conflictCount} {$label} were left unassigned for job {$number} because the assignee was busy.",
            'action_url' => route('task.index'),
            'category' => NotificationPreferenceService::CATEGORY_SYSTEM,
            'work_id' => $this->work->id,
            'unassigned_count' => $this->conflictCount,
        ];
    }
}
