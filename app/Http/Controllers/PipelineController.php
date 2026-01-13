<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Task;
use App\Models\Work;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class PipelineController extends Controller
{
    public function timeline(string $entityType, string $entityId)
    {
        return inertia('Pipeline/EntityPipelineTimeline', [
            'entityType' => $entityType,
            'entityId' => (string) $entityId,
        ]);
    }

    public function data(Request $request)
    {
        $validated = $request->validate([
            'entityType' => ['required', Rule::in(['request', 'quote', 'job', 'task', 'invoice'])],
            'entityId' => ['required', 'string'],
        ]);

        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (!$user || $user->id !== $accountId) {
            abort(403);
        }

        $payload = $this->buildPipeline($validated['entityType'], $validated['entityId'], $accountId);

        return response()->json($payload);
    }

    private function buildPipeline(string $entityType, string $entityId, int $accountId): array
    {
        $request = null;
        $quote = null;
        $work = null;
        $tasks = collect();
        $invoice = null;

        switch ($entityType) {
            case 'request':
                $request = $this->requestQuery($accountId)->findOrFail($entityId);
                if ($request->quote) {
                    $quote = $this->quoteQuery($accountId)->find($request->quote->id);
                }
                break;
            case 'quote':
                $quote = $this->quoteQuery($accountId)->findOrFail($entityId);
                $request = $quote->request;
                break;
            case 'job':
                $work = $this->workQuery($accountId)->findOrFail($entityId);
                $quote = $work->quote;
                $request = $quote?->request;
                break;
            case 'task':
                $task = $this->taskQuery($accountId)->findOrFail($entityId);
                $work = $task->work;
                $quote = $work?->quote;
                $request = $quote?->request;
                $tasks = $work ? $work->tasks : collect([$task]);
                $invoice = $work?->invoice;
                break;
            case 'invoice':
                $invoice = $this->invoiceQuery($accountId)->findOrFail($entityId);
                $work = $invoice->work;
                $quote = $work?->quote;
                $request = $quote?->request;
                break;
        }

        if ($quote && !$work) {
            $work = $quote->work_id ? $this->workQuery($accountId)->find($quote->work_id) : null;
            if (!$work) {
                $work = $this->workQuery($accountId)
                    ->where('quote_id', $quote->id)
                    ->latest('created_at')
                    ->first();
            }
        }

        if ($work) {
            if (!$invoice) {
                $invoice = $work->invoice;
            }
            if ($tasks->isEmpty()) {
                $tasks = $work->tasks;
            }
        }

        $formattedTasks = $tasks->map(function (Task $task) use ($invoice) {
            return $this->formatTask($task, $invoice);
        })->values();

        $billing = $this->buildBilling($quote, $invoice);
        $derived = $this->buildDerived($request, $quote, $work, $formattedTasks, $invoice);

        return [
            'source' => [
                'type' => $entityType,
                'id' => $entityId,
            ],
            'request' => $request ? $this->formatRequest($request) : null,
            'quote' => $quote ? $this->formatQuote($quote) : null,
            'job' => $work ? $this->formatWork($work) : null,
            'tasks' => $formattedTasks,
            'invoice' => $invoice ? $this->formatInvoice($invoice) : null,
            'billing' => $billing,
            'derived' => $derived,
        ];
    }

    private function requestQuery(int $accountId)
    {
        return LeadRequest::query()
            ->where('user_id', $accountId)
            ->with([
                'customer:id,company_name,first_name,last_name,email,phone',
                'quote:id,request_id,customer_id',
            ]);
    }

    private function quoteQuery(int $accountId)
    {
        return Quote::query()
            ->where('user_id', $accountId)
            ->with([
                'customer:id,company_name,first_name,last_name,email,phone',
                'request:id,customer_id,status,title,service_type,created_at,converted_at',
            ]);
    }

    private function workQuery(int $accountId)
    {
        return Work::query()
            ->where('user_id', $accountId)
            ->with($this->workRelations());
    }

    private function taskQuery(int $accountId)
    {
        return Task::query()
            ->where('account_id', $accountId)
            ->with([
                'assignee.user:id,name',
                'invoiceItem:id,task_id,invoice_id,total',
                'work' => function ($query) {
                    $query->with($this->workRelations());
                },
            ]);
    }

    private function invoiceQuery(int $accountId)
    {
        return Invoice::query()
            ->where('user_id', $accountId)
            ->with([
                'customer:id,company_name,first_name,last_name,email,phone',
                'work' => function ($query) {
                    $query->with($this->workRelations());
                },
            ]);
    }

    private function workRelations(): array
    {
        return [
            'customer:id,company_name,first_name,last_name,email,phone',
            'quote:id,number,status,request_id,customer_id,total,subtotal,created_at,accepted_at,work_id',
            'quote.customer:id,company_name,first_name,last_name,email,phone',
            'quote.request:id,customer_id,status,title,service_type,created_at,converted_at',
            'invoice:id,work_id,number,status,total,created_at',
            'tasks:id,work_id,title,status,due_date,completed_at,assigned_team_member_id,billable',
            'tasks.assignee.user:id,name',
            'tasks.invoiceItem:id,task_id,invoice_id,total',
        ];
    }

    private function formatRequest(LeadRequest $request): array
    {
        return [
            'id' => $request->id,
            'title' => $request->title,
            'service_type' => $request->service_type,
            'status' => $request->status,
            'created_at' => optional($request->created_at)->toIso8601String(),
            'converted_at' => optional($request->converted_at)->toIso8601String(),
            'customer' => $this->formatCustomer($request->customer),
        ];
    }

    private function formatQuote(Quote $quote): array
    {
        return [
            'id' => $quote->id,
            'number' => $quote->number,
            'status' => $quote->status,
            'job_title' => $quote->job_title,
            'total' => $quote->total !== null ? (float) $quote->total : null,
            'subtotal' => $quote->subtotal !== null ? (float) $quote->subtotal : null,
            'created_at' => optional($quote->created_at)->toIso8601String(),
            'accepted_at' => optional($quote->accepted_at)->toIso8601String(),
            'customer' => $this->formatCustomer($quote->customer),
        ];
    }

    private function formatWork(Work $work): array
    {
        return [
            'id' => $work->id,
            'number' => $work->number,
            'job_title' => $work->job_title,
            'status' => $work->status,
            'start_date' => optional($work->start_date)->toDateString(),
            'end_date' => optional($work->end_date)->toDateString(),
            'total' => $work->total !== null ? (float) $work->total : null,
            'subtotal' => $work->subtotal !== null ? (float) $work->subtotal : null,
            'customer' => $this->formatCustomer($work->customer),
        ];
    }

    private function formatTask(Task $task, ?Invoice $invoice): array
    {
        $invoiceItem = $task->relationLoaded('invoiceItem') ? $task->invoiceItem : $task->invoiceItem()->first();
        $billingStatus = 'unbilled';

        if ($invoiceItem) {
            $billingStatus = $invoice && $invoice->status === 'partial' ? 'partial' : 'billed';
        }

        return [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'due_date' => optional($task->due_date)->toDateString(),
            'completed_at' => optional($task->completed_at)->toIso8601String(),
            'assignee' => $task->assignee?->user?->name,
            'billable' => (bool) $task->billable,
            'billing_status' => $billingStatus,
            'invoice_id' => $invoiceItem?->invoice_id,
        ];
    }

    private function formatInvoice(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status,
            'total' => $invoice->total !== null ? (float) $invoice->total : null,
            'amount_paid' => (float) $invoice->amount_paid,
            'balance_due' => (float) $invoice->balance_due,
            'created_at' => optional($invoice->created_at)->toIso8601String(),
            'customer' => $this->formatCustomer($invoice->customer ?? $invoice->work?->customer),
        ];
    }

    private function formatCustomer($customer): ?array
    {
        if (!$customer) {
            return null;
        }

        $name = $customer->company_name ?: trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));

        return [
            'id' => $customer->id,
            'name' => $name ?: 'Customer',
            'email' => $customer->email,
            'phone' => $customer->phone,
        ];
    }

    private function buildBilling(?Quote $quote, ?Invoice $invoice): array
    {
        $quoteTotal = $quote?->total !== null ? (float) $quote->total : null;
        $invoiceTotal = $invoice?->total !== null ? (float) $invoice->total : null;
        $remaining = null;

        if ($quoteTotal !== null) {
            $remaining = max(0, $quoteTotal - ($invoiceTotal ?? 0));
        }

        return [
            'quote_total' => $quoteTotal,
            'invoice_total' => $invoiceTotal,
            'remaining_to_bill' => $remaining,
            'amount_paid' => $invoice ? (float) $invoice->amount_paid : null,
            'balance_due' => $invoice ? (float) $invoice->balance_due : null,
        ];
    }

    private function buildDerived(?LeadRequest $request, ?Quote $quote, ?Work $work, $tasks, ?Invoice $invoice): array
    {
        $steps = 5;
        $present = 0;
        $alerts = [];

        if ($request) {
            $present++;
            if (!$quote) {
                $alerts[] = 'Quote not created yet.';
            }
        }
        if ($quote) {
            $present++;
            if ($quote->status === 'declined') {
                $alerts[] = 'Quote declined.';
            }
            if (!$work) {
                $alerts[] = 'Job not created yet.';
            }
        }
        if ($work) {
            $present++;
            if (in_array($work->status, ['dispute', 'cancelled'], true)) {
                $alerts[] = $work->status === 'cancelled' ? 'Job cancelled.' : 'Job in dispute.';
            }
            if (!$invoice) {
                $alerts[] = 'Invoice not created yet.';
            }
        }
        if ($tasks && count($tasks)) {
            $present++;
            $pending = collect($tasks)->where('status', '!=', 'done')->count();
            if ($pending > 0) {
                $alerts[] = 'Tasks pending.';
            }
        }
        if ($invoice) {
            $present++;
            if ($invoice->status === 'overdue') {
                $alerts[] = 'Invoice overdue.';
            }
        }

        $completeness = (int) round(($present / $steps) * 100);
        $globalStatus = $invoice?->status ?? $work?->status ?? $quote?->status ?? $request?->status ?? 'missing';

        return [
            'completeness' => $completeness,
            'globalStatus' => $globalStatus,
            'alerts' => $alerts,
        ];
    }
}
