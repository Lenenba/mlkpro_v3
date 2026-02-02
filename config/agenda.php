<?php

return [
    'start_time_fallback' => '09:00:00',
    'end_of_day' => '18:00:00',
    'task_auto_start_status' => 'in_progress',
    'work_auto_start_status' => 'tech_complete',
    'auto_complete_tasks' => true,
    'shift_reminder_minutes' => (int) env('SHIFT_REMINDER_MINUTES', 30),
    'shift_late_grace_minutes' => (int) env('SHIFT_LATE_GRACE_MINUTES', 10),
];
