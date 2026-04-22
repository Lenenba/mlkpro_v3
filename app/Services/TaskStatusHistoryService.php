<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskStatusHistory;
use App\Models\User;

class TaskStatusHistoryService
{
    public function record(Task $task, ?User $actor, array $context = []): TaskStatusHistory
    {
        $reasonCode = $context['reason_code'] ?? null;
        if ($reasonCode === null && TaskTimingService::isValidCompletionReason($task->completion_reason)) {
            $reasonCode = $task->completion_reason;
        }

        $payload = [
            'task_id' => $task->id,
            'user_id' => $actor?->id,
            'from_status' => $context['from_status'] ?? null,
            'to_status' => $context['to_status'] ?? null,
            'timing_status' => $context['timing_status'] ?? TaskTimingService::resolveTimingStatus($task),
            'due_date' => $task->due_date,
            'completed_at' => $task->completed_at,
            'reason_code' => $reasonCode,
            'note' => $context['note'] ?? null,
            'action' => $context['action'] ?? 'manual',
            'metadata' => $context['metadata'] ?? null,
        ];

        return TaskStatusHistory::create($payload);
    }
}
