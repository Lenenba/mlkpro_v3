<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Work;
use App\Notifications\ActionEmailNotification;
use App\Support\NotificationDispatcher;
use App\Services\UsageLimitService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use App\Services\TemplateService;

class WorkBillingService
{
    public function createInvoiceFromWork(Work $work, ?User $actor = null): Invoice
    {
        if ($work->invoice) {
            return $work->invoice;
        }

        $work->loadMissing(['customer', 'products']);

        $limitUser = $actor ?: User::find($work->user_id);
        if ($limitUser) {
            app(UsageLimitService::class)->enforceLimit($limitUser, 'invoices');
        }

        $quoteQuery = Quote::query()
            ->where('status', 'accepted')
            ->where(function ($query) use ($work) {
                $query->where('work_id', $work->id);
                if ($work->quote_id) {
                    $query->orWhere('id', $work->quote_id);
                }
            });

        $quoteTotal = (float) (clone $quoteQuery)->sum('total');
        $quotes = (clone $quoteQuery)->with('products')->get();
        if ($quoteTotal <= 0) {
            $quoteTotal = (float) ($work->total ?? 0);
        }

        $depositTotal = (float) Transaction::query()
            ->where('work_id', $work->id)
            ->where('type', 'deposit')
            ->where('status', 'completed')
            ->sum('amount');

        $scheduledDate = null;
        if ($work->start_date) {
            $scheduledDate = $work->start_date instanceof Carbon
                ? $work->start_date->toDateString()
                : Carbon::parse($work->start_date)->toDateString();
        }

        $lineItems = collect();
        $itemsSource = null;
        $buildLineItem = function ($product, ?int $quoteId = null) use ($work, $scheduledDate) {
            $quantity = (float) ($product->pivot?->quantity ?? 0);
            $unitPrice = (float) ($product->pivot?->price ?? $product->price ?? 0);
            $total = (float) ($product->pivot?->total ?? round($quantity * $unitPrice, 2));

            return [
                'work_id' => $work->id,
                'title' => $product->name ?? 'Line item',
                'description' => $product->pivot?->description ?: $product->description,
                'scheduled_date' => $scheduledDate,
                'start_time' => $work->start_time,
                'end_time' => $work->end_time,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total' => $total,
                'meta' => [
                    'source' => $quoteId ? 'quote' : 'work',
                    'quote_id' => $quoteId,
                    'product_id' => $product->id,
                    'item_type' => $product->item_type,
                ],
            ];
        };

        if ($work->products->isNotEmpty()) {
            foreach ($work->products as $product) {
                $lineItems->push($buildLineItem($product));
            }
            $itemsSource = 'work';
        } elseif ($quotes->isNotEmpty()) {
            foreach ($quotes as $quote) {
                foreach ($quote->products as $product) {
                    $lineItems->push($buildLineItem($product, $quote->id));
                }
            }
            if ($lineItems->isNotEmpty()) {
                $itemsSource = 'quote';
            }
        }

        if ($lineItems->isEmpty()) {
            $tasks = $work->tasks()
                ->where('status', 'done')
                ->where('billable', true)
                ->whereDoesntHave('invoiceItem')
                ->with(['assignee.user', 'materials'])
                ->get();

            if ($tasks->isNotEmpty()) {
                $itemsSource = 'task';
                $unitPrice = $this->resolveTaskUnitPrice($work, $tasks->count());

                foreach ($tasks as $task) {
                    $assigneeName = $task->assignee?->user?->name;
                    $taskDate = $task->due_date ?: $scheduledDate;

                    $lineItems->push([
                        'task_id' => $task->id,
                        'work_id' => $work->id,
                        'assigned_team_member_id' => $task->assigned_team_member_id,
                        'title' => $task->title ?: 'Task',
                        'description' => $task->description,
                        'scheduled_date' => $taskDate,
                        'start_time' => $task->start_time,
                        'end_time' => $task->end_time,
                        'assignee_name' => $assigneeName,
                        'task_status' => $task->status,
                        'quantity' => 1,
                        'unit_price' => $unitPrice,
                        'total' => $unitPrice,
                        'meta' => [
                            'source' => 'task',
                            'billing_mode' => $work->billing_mode,
                            'billing_cycle' => $work->billing_cycle,
                            'billing_grouping' => $work->billing_grouping,
                        ],
                    ]);
                }

                $materialItems = $tasks->flatMap(function ($task) use ($work, $scheduledDate) {
                    $assigneeName = $task->assignee?->user?->name;
                    $taskDate = $task->due_date ?: $scheduledDate;

                    return $task->materials
                        ->filter(fn($material) => $material->billable && (float) $material->quantity > 0)
                        ->map(function ($material) use ($task, $assigneeName, $taskDate, $work) {
                            $quantity = max(0, (float) $material->quantity);
                            $unitPrice = max(0, (float) $material->unit_price);
                            $total = round($quantity * $unitPrice, 2);

                            return [
                                'task_id' => null,
                                'work_id' => $work->id,
                                'assigned_team_member_id' => $task->assigned_team_member_id,
                                'title' => 'Material - ' . $material->label,
                                'description' => $material->description,
                                'scheduled_date' => $taskDate,
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

                $lineItems = $lineItems->merge($materialItems);
            }
        }

        $itemsTotal = $lineItems->isNotEmpty()
            ? round($lineItems->sum('total'), 2)
            : 0;
        $baseTotal = $quoteTotal;
        if ($itemsSource === 'work' || $itemsSource === 'task') {
            $baseTotal = (float) ($work->total ?? 0);
        }
        if ($lineItems->isNotEmpty() && $baseTotal <= 0) {
            $baseTotal = $itemsTotal;
        }

        $invoiceTotal = max(0, round($baseTotal - $depositTotal, 2));

        if ($lineItems->isNotEmpty()) {
            $adjustment = round($invoiceTotal - $itemsTotal, 2);
            if (abs($adjustment) >= 0.01) {
                $lineItems->push([
                    'work_id' => $work->id,
                    'title' => $adjustment < 0 ? 'Deposit applied' : 'Adjustment',
                    'description' => $adjustment < 0 ? 'Deposit' : null,
                    'scheduled_date' => $scheduledDate,
                    'start_time' => $work->start_time,
                    'end_time' => $work->end_time,
                    'quantity' => 1,
                    'unit_price' => $adjustment,
                    'total' => $adjustment,
                    'meta' => [
                        'type' => $adjustment < 0 ? 'deposit' : 'adjustment',
                    ],
                ]);
            }
        }

        $invoice = DB::transaction(function () use ($work, $invoiceTotal, $lineItems) {
            $invoice = Invoice::create([
                'user_id' => $work->user_id,
                'customer_id' => $work->customer_id,
                'work_id' => $work->id,
                'status' => 'sent',
                'total' => $invoiceTotal,
            ]);

            if ($lineItems->isNotEmpty()) {
                $invoice->items()->createMany($lineItems->all());
            }

            return $invoice;
        });

        if ($actor) {
            ActivityLog::record($actor, $invoice, 'created', [
                'work_id' => $work->id,
                'total' => $invoice->total,
            ], 'Invoice created from job');
        }

        $customer = $work->customer;
        if ($customer && $customer->email) {
            $accountOwner = User::find($work->user_id);
            $note = $accountOwner
                ? app(TemplateService::class)->resolveInvoiceNote($accountOwner)
                : null;
            $usePublicLink = !(bool) ($customer->portal_access ?? true) || !$customer->portal_user_id;
            $actionUrl = route('dashboard');
            $actionLabel = 'Open dashboard';
            if ($usePublicLink) {
                $expiresAt = now()->addDays(7);
                $actionUrl = URL::temporarySignedRoute(
                    'public.invoices.show',
                    $expiresAt,
                    ['invoice' => $invoice->id]
                );
                $actionLabel = 'Pay invoice';
            }
            NotificationDispatcher::send($customer, new ActionEmailNotification(
                'New invoice available',
                'A new invoice has been generated for your job.',
                [
                    ['label' => 'Invoice', 'value' => $invoice->number ?? $invoice->id],
                    ['label' => 'Job', 'value' => $work->job_title ?? $work->number ?? $work->id],
                    ['label' => 'Total', 'value' => '$' . number_format((float) $invoice->total, 2)],
                ],
                $actionUrl,
                $actionLabel,
                'New invoice available',
                $note
            ), [
                'invoice_id' => $invoice->id,
                'work_id' => $work->id,
            ]);
        }

        return $invoice;
    }

    private function resolveTaskUnitPrice(Work $work, int $taskCount): float
    {
        $total = (float) ($work->total ?? 0);
        if ($total <= 0) {
            return 0.0;
        }

        $visits = (int) ($work->totalVisits ?? 0);
        $divider = $visits > 0 ? $visits : max(1, $taskCount);

        return round($total / $divider, 2);
    }
}
