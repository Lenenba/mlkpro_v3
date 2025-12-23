<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Task;
use App\Models\User;
use App\Models\Work;
use App\Models\ActivityLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TaskBillingService
{
    public function resolveSettings(Work $work): array
    {
        $work->loadMissing('customer');

        $customer = $work->customer;

        $mode = $work->billing_mode ?: ($customer?->billing_mode ?? 'end_of_job');
        $cycle = $work->billing_cycle ?: ($customer?->billing_cycle ?? null);
        $grouping = $work->billing_grouping ?: ($customer?->billing_grouping ?? 'single');
        $delay = $work->billing_delay_days ?? $customer?->billing_delay_days;
        $dateRule = $work->billing_date_rule ?: ($customer?->billing_date_rule ?? null);

        return [
            'billing_mode' => $mode,
            'billing_cycle' => $cycle,
            'billing_grouping' => $grouping,
            'billing_delay_days' => $delay,
            'billing_date_rule' => $dateRule,
        ];
    }

    public function shouldInvoiceOnWorkValidation(Work $work): bool
    {
        $settings = $this->resolveSettings($work);

        return ($settings['billing_mode'] ?? 'end_of_job') === 'end_of_job';
    }

    public function handleTaskCompleted(Task $task, ?User $actor = null): void
    {
        if (!$task->billable) {
            return;
        }

        $task->loadMissing(['work.customer', 'assignee.user', 'invoiceItem', 'materials']);
        $work = $task->work;
        if (!$work) {
            return;
        }

        if ($task->invoiceItem) {
            $this->markWorkCompleteIfReady($work, $actor);
            return;
        }

        $settings = $this->resolveSettings($work);
        $mode = $settings['billing_mode'] ?? 'end_of_job';

        if ($mode === 'per_task') {
            $this->billTasks($work, collect([$task]), $settings, $actor);
        } elseif ($mode === 'end_of_job') {
            $this->billIfWorkComplete($work, $settings, $actor);
        }

        $this->markWorkCompleteIfReady($work, $actor);
    }

    public function billIfWorkComplete(Work $work, array $settings, ?User $actor = null): void
    {
        $remaining = $work->tasks()
            ->where('status', '!=', 'done')
            ->count();

        if ($remaining > 0) {
            return;
        }

        $tasks = $work->tasks()
            ->where('status', 'done')
            ->where('billable', true)
            ->whereDoesntHave('invoiceItem')
            ->get();

        if ($tasks->isEmpty()) {
            return;
        }

        $settings['billing_grouping'] = 'single';
        $this->billTasks($work, $tasks, $settings, $actor);
    }

    private function billTasks(Work $work, Collection $tasks, array $settings, ?User $actor = null): void
    {
        if ($tasks->isEmpty()) {
            return;
        }

        $tasks->loadMissing(['invoiceItem', 'materials']);
        $tasks = $tasks->filter(fn($task) => $task->billable && !$task->invoiceItem);
        if ($tasks->isEmpty()) {
            return;
        }

        $grouping = $settings['billing_grouping'] ?? 'single';
        $status = $grouping === 'periodic' ? 'draft' : 'sent';

        DB::transaction(function () use ($work, $tasks, $settings, $status, $grouping, $actor) {
            $firstTask = $tasks->first();
            $periodDate = $this->resolveTaskDate($firstTask, $work);

            $invoice = $grouping === 'periodic'
                ? $this->findOpenInvoiceForPeriod($work, $periodDate, $settings)
                : null;

            if (!$invoice) {
                $invoice = Invoice::create([
                    'user_id' => $work->user_id,
                    'customer_id' => $work->customer_id,
                    'work_id' => $work->id,
                    'status' => $status,
                    'total' => 0,
                ]);

                if ($actor) {
                    ActivityLog::record($actor, $invoice, 'created', [
                        'work_id' => $work->id,
                    ], 'Invoice created from tasks');
                }
            }

            $unitPrice = $this->resolveUnitPrice($work, $tasks->count());

            $items = $tasks->map(function ($task) use ($work, $unitPrice) {
                $assigneeName = $task->assignee?->user?->name;
                $scheduledDate = $task->due_date ?: $work->start_date;

                return [
                    'task_id' => $task->id,
                    'work_id' => $work->id,
                    'assigned_team_member_id' => $task->assigned_team_member_id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'scheduled_date' => $scheduledDate,
                    'start_time' => $task->start_time,
                    'end_time' => $task->end_time,
                    'assignee_name' => $assigneeName,
                    'task_status' => $task->status,
                    'quantity' => 1,
                    'unit_price' => $unitPrice,
                    'total' => $unitPrice,
                    'meta' => [
                        'billing_mode' => $work->billing_mode,
                        'billing_cycle' => $work->billing_cycle,
                        'billing_grouping' => $work->billing_grouping,
                    ],
                ];
            });

            $materialItems = $tasks->flatMap(function ($task) use ($work) {
                $assigneeName = $task->assignee?->user?->name;
                $scheduledDate = $task->due_date ?: $work->start_date;

                return $task->materials
                    ->filter(fn($material) => $material->billable && (float) $material->quantity > 0)
                    ->map(function ($material) use ($task, $assigneeName, $scheduledDate, $work) {
                        $quantity = max(0, (float) $material->quantity);
                        $unitPrice = max(0, (float) $material->unit_price);
                        $total = round($quantity * $unitPrice, 2);

                        return [
                            'task_id' => null,
                            'work_id' => $work->id,
                            'assigned_team_member_id' => $task->assigned_team_member_id,
                            'title' => 'Material - ' . $material->label,
                            'description' => $material->description,
                            'scheduled_date' => $scheduledDate,
                            'start_time' => $task->start_time,
                            'end_time' => $task->end_time,
                            'assignee_name' => $assigneeName,
                            'task_status' => $task->status,
                            'quantity' => $quantity,
                            'unit_price' => $unitPrice,
                            'total' => $total,
                            'meta' => [
                                'type' => 'material',
                                'task_id' => $task->id,
                                'material_id' => $material->id,
                            ],
                        ];
                    });
            });

            $invoice->items()->createMany([
                ...$items->all(),
                ...$materialItems->all(),
            ]);

            $invoice->total = $invoice->items()->sum('total');
            $invoice->save();
        });
    }

    private function findOpenInvoiceForPeriod(Work $work, Carbon $date, array $settings): ?Invoice
    {
        $cycle = $settings['billing_cycle'] ?? null;
        if (!$cycle) {
            return null;
        }

        $query = Invoice::query()
            ->where('work_id', $work->id)
            ->where('status', 'draft');

        [$start, $end] = $this->resolveCycleWindow($date, $cycle);
        if ($start && $end) {
            $query->whereBetween('created_at', [$start, $end]);
        }

        return $query->latest()->first();
    }

    private function resolveCycleWindow(Carbon $date, string $cycle): array
    {
        switch ($cycle) {
            case 'weekly':
                $start = $date->copy()->startOfWeek();
                $end = $date->copy()->endOfWeek();
                return [$start, $end];
            case 'biweekly':
                $weekNumber = (int) $date->format('W');
                $isEven = $weekNumber % 2 === 0;
                $start = $date->copy()->startOfWeek();
                if ($isEven) {
                    $start->subWeek();
                }
                $end = $start->copy()->addWeeks(2)->subDay()->endOfDay();
                return [$start, $end];
            case 'monthly':
                $start = $date->copy()->startOfMonth();
                $end = $date->copy()->endOfMonth();
                return [$start, $end];
            default:
                return [null, null];
        }
    }

    private function resolveTaskDate(Task $task, Work $work): Carbon
    {
        if ($task->due_date) {
            return Carbon::parse($task->due_date);
        }

        if ($work->start_date) {
            return Carbon::parse($work->start_date);
        }

        return now();
    }

    private function resolveUnitPrice(Work $work, int $taskCount): float
    {
        $total = (float) ($work->total ?? 0);
        if ($total <= 0) {
            return 0.0;
        }

        $visits = (int) ($work->totalVisits ?? 0);
        $divider = $visits > 0 ? $visits : max(1, $taskCount);

        return round($total / $divider, 2);
    }

    private function markWorkCompleteIfReady(Work $work, ?User $actor = null): void
    {
        if (in_array($work->status, [Work::STATUS_CANCELLED, Work::STATUS_DISPUTE], true)) {
            return;
        }

        if (in_array($work->status, Work::COMPLETED_STATUSES, true)) {
            return;
        }

        $remaining = $work->tasks()
            ->where('status', '!=', 'done')
            ->count();

        if ($remaining > 0) {
            return;
        }

        $previous = $work->status;
        $work->status = Work::STATUS_TECH_COMPLETE;
        $work->save();

        if ($actor) {
            ActivityLog::record($actor, $work, 'status_changed', [
                'from' => $previous,
                'to' => $work->status,
            ], 'Job marked as completed after tasks');
        }
    }
}
