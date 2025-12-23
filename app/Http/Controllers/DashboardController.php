<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use App\Models\Work;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Customer;
use App\Models\ActivityLog;
use App\Models\Task;
use App\Models\TeamMember;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        if ($user && $user->isClient()) {
            $customer = $user->customerProfile;
            if (!$customer) {
                return Inertia::render('DashboardClient', [
                    'profileMissing' => true,
                    'stats' => [
                        'quotes_pending' => 0,
                        'works_pending' => 0,
                        'invoices_due' => 0,
                        'ratings_due' => 0,
                    ],
                    'pendingQuotes' => [],
                    'validatedQuotes' => [],
                    'pendingSchedules' => [],
                    'pendingWorks' => [],
                    'validatedWorks' => [],
                    'invoicesDue' => [],
                    'taskProofs' => [],
                    'quoteRatingsDue' => [],
                    'workRatingsDue' => [],
                ]);
            }

            $customerId = $customer->id;

            $pendingQuotesQuery = Quote::query()
                ->where('customer_id', $customerId)
                ->whereNull('archived_at')
                ->where('status', 'sent');
            $validatedQuotesQuery = Quote::query()
                ->where('customer_id', $customerId)
                ->whereNull('archived_at')
                ->whereIn('status', ['accepted', 'declined']);

            $pendingWorksQuery = Work::query()
                ->where('customer_id', $customerId)
                ->whereIn('status', [Work::STATUS_PENDING_REVIEW, Work::STATUS_TECH_COMPLETE]);
            $pendingSchedulesQuery = Work::query()
                ->where('customer_id', $customerId)
                ->where('status', Work::STATUS_SCHEDULED)
                ->whereDoesntHave('tasks')
                ->with('teamMembers.user:id,name');
            $validatedWorksQuery = Work::query()
                ->where('customer_id', $customerId)
                ->whereIn('status', [
                    Work::STATUS_VALIDATED,
                    Work::STATUS_AUTO_VALIDATED,
                    Work::STATUS_CLOSED,
                    Work::STATUS_COMPLETED,
                ]);

            $invoicesDueQuery = Invoice::query()
                ->where('customer_id', $customerId)
                ->whereIn('status', ['sent', 'partial', 'overdue']);

            $quoteRatingsQuery = Quote::query()
                ->where('customer_id', $customerId)
                ->whereNull('archived_at')
                ->whereIn('status', ['accepted', 'declined'])
                ->whereDoesntHave('ratings', function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                });

            $workRatingsQuery = Work::query()
                ->where('customer_id', $customerId)
                ->whereIn('status', [
                    Work::STATUS_VALIDATED,
                    Work::STATUS_AUTO_VALIDATED,
                    Work::STATUS_CLOSED,
                    Work::STATUS_COMPLETED,
                ])
                ->whereDoesntHave('ratings', function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                });

            $stats = [
                'quotes_pending' => (clone $pendingQuotesQuery)->count(),
                'works_pending' => (clone $pendingWorksQuery)->count(),
                'invoices_due' => (clone $invoicesDueQuery)->count(),
                'ratings_due' => (clone $quoteRatingsQuery)->count()
                    + (clone $workRatingsQuery)->count(),
            ];

            $pendingQuotes = (clone $pendingQuotesQuery)
                ->latest()
                ->limit(8)
                ->get(['id', 'number', 'job_title', 'status', 'total', 'initial_deposit', 'created_at'])
                ->map(function ($quote) {
                    return [
                        'id' => $quote->id,
                        'number' => $quote->number,
                        'job_title' => $quote->job_title,
                        'status' => $quote->status,
                        'total' => (float) $quote->total,
                        'initial_deposit' => (float) ($quote->initial_deposit ?? 0),
                        'created_at' => $quote->created_at,
                    ];
                });

            $validatedQuotes = (clone $validatedQuotesQuery)
                ->orderByDesc('updated_at')
                ->limit(6)
                ->get(['id', 'number', 'job_title', 'status', 'total', 'signed_at', 'accepted_at', 'updated_at'])
                ->map(function ($quote) {
                    return [
                        'id' => $quote->id,
                        'number' => $quote->number,
                        'job_title' => $quote->job_title,
                        'status' => $quote->status,
                        'total' => (float) $quote->total,
                        'decided_at' => $quote->accepted_at ?? $quote->signed_at ?? $quote->updated_at,
                    ];
                });

            $pendingWorks = (clone $pendingWorksQuery)
                ->orderByDesc('updated_at')
                ->limit(8)
                ->get(['id', 'job_title', 'status', 'start_date', 'end_date', 'completed_at'])
                ->map(function ($work) {
                    return [
                        'id' => $work->id,
                        'job_title' => $work->job_title,
                        'status' => $work->status,
                        'start_date' => $work->start_date,
                        'end_date' => $work->end_date,
                        'completed_at' => $work->completed_at,
                    ];
                });

            $pendingSchedules = (clone $pendingSchedulesQuery)
                ->orderBy('start_date')
                ->limit(8)
                ->get([
                    'id',
                    'job_title',
                    'status',
                    'start_date',
                    'end_date',
                    'start_time',
                    'end_time',
                    'frequency',
                    'repeatsOn',
                    'totalVisits',
                ])
                ->map(function ($work) {
                    return [
                        'id' => $work->id,
                        'job_title' => $work->job_title,
                        'status' => $work->status,
                        'start_date' => $work->start_date,
                        'end_date' => $work->end_date,
                        'start_time' => $work->start_time,
                        'end_time' => $work->end_time,
                        'frequency' => $work->frequency,
                        'repeatsOn' => $work->repeatsOn,
                        'totalVisits' => $work->totalVisits,
                        'team_members' => $work->teamMembers->map(function ($member) {
                            return [
                                'id' => $member->id,
                                'name' => $member->user?->name ?? 'Membre equipe',
                            ];
                        })->values(),
                    ];
                });

            $taskProofs = Task::query()
                ->where('customer_id', $customerId)
                ->whereNotNull('work_id')
                ->whereIn('status', ['in_progress', 'done'])
                ->with(['work:id,job_title'])
                ->orderByDesc('due_date')
                ->orderByDesc('created_at')
                ->limit(8)
                ->get([
                    'id',
                    'title',
                    'status',
                    'due_date',
                    'start_time',
                    'end_time',
                    'work_id',
                ])
                ->map(function ($task) {
                    return [
                        'id' => $task->id,
                        'title' => $task->title,
                        'status' => $task->status,
                        'due_date' => $task->due_date,
                        'start_time' => $task->start_time,
                        'end_time' => $task->end_time,
                        'work_id' => $task->work_id,
                        'work_title' => $task->work?->job_title,
                    ];
                });

            $validatedWorks = (clone $validatedWorksQuery)
                ->orderByDesc('completed_at')
                ->limit(6)
                ->get(['id', 'job_title', 'status', 'completed_at'])
                ->map(function ($work) {
                    return [
                        'id' => $work->id,
                        'job_title' => $work->job_title,
                        'status' => $work->status,
                        'completed_at' => $work->completed_at,
                    ];
                });

            $invoicesDue = (clone $invoicesDueQuery)
                ->withSum('payments', 'amount')
                ->orderByDesc('created_at')
                ->limit(8)
                ->get(['id', 'number', 'status', 'total', 'created_at'])
                ->map(function ($invoice) {
                    return [
                        'id' => $invoice->id,
                        'number' => $invoice->number,
                        'status' => $invoice->status,
                        'total' => (float) $invoice->total,
                        'amount_paid' => (float) ($invoice->payments_sum_amount ?? 0),
                        'balance_due' => $invoice->balance_due,
                        'created_at' => $invoice->created_at,
                    ];
                });

            $quoteRatingsDue = (clone $quoteRatingsQuery)
                ->orderByDesc('updated_at')
                ->limit(6)
                ->get(['id', 'number', 'job_title', 'status', 'total', 'accepted_at', 'signed_at', 'updated_at'])
                ->map(function ($quote) {
                    return [
                        'id' => $quote->id,
                        'number' => $quote->number,
                        'job_title' => $quote->job_title,
                        'status' => $quote->status,
                        'total' => (float) $quote->total,
                        'decided_at' => $quote->accepted_at ?? $quote->signed_at ?? $quote->updated_at,
                    ];
                });

            $workRatingsDue = (clone $workRatingsQuery)
                ->orderByDesc('completed_at')
                ->limit(6)
                ->get(['id', 'job_title', 'status', 'completed_at'])
                ->map(function ($work) {
                    return [
                        'id' => $work->id,
                        'job_title' => $work->job_title,
                        'status' => $work->status,
                        'completed_at' => $work->completed_at,
                    ];
                });

            return Inertia::render('DashboardClient', [
                'profileMissing' => false,
                'stats' => $stats,
                'pendingQuotes' => $pendingQuotes,
                'pendingSchedules' => $pendingSchedules,
                'validatedQuotes' => $validatedQuotes,
                'pendingWorks' => $pendingWorks,
                'taskProofs' => $taskProofs,
                'validatedWorks' => $validatedWorks,
                'invoicesDue' => $invoicesDue,
                'quoteRatingsDue' => $quoteRatingsDue,
                'workRatingsDue' => $workRatingsDue,
            ]);
        }

        $accountId = $user?->accountOwnerId() ?? Auth::id();
        $isAccountOwner = ($user?->id ?? Auth::id()) === $accountId;

        $membership = null;
        if (!$isAccountOwner && $user) {
            $membership = TeamMember::query()
                ->forAccount($accountId)
                ->active()
                ->where('user_id', $user->id)
                ->first();
        }

        if ($membership) {
            if ($membership->role === 'admin') {
                $tasksQuery = Task::query()->forAccount($accountId);

                $stats = [
                    'tasks_total' => (clone $tasksQuery)->count(),
                    'tasks_todo' => (clone $tasksQuery)->where('status', 'todo')->count(),
                    'tasks_in_progress' => (clone $tasksQuery)->where('status', 'in_progress')->count(),
                    'tasks_done' => (clone $tasksQuery)->where('status', 'done')->count(),
                ];

                $tasks = (clone $tasksQuery)
                    ->with('assignee.user:id,name')
                    ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('due_date')
                    ->orderByDesc('created_at')
                    ->limit(10)
                    ->get(['id', 'title', 'status', 'due_date', 'assigned_team_member_id'])
                    ->map(function ($task) {
                        return [
                            'id' => $task->id,
                            'title' => $task->title,
                            'status' => $task->status,
                            'due_date' => $task->due_date,
                            'assignee' => $task->assignee?->user ? [
                                'name' => $task->assignee->user->name,
                            ] : null,
                        ];
                    });

                return Inertia::render('DashboardAdmin', [
                    'stats' => $stats,
                    'tasks' => $tasks,
                ]);
            }

            $tasksQuery = Task::query()
                ->forAccount($accountId)
                ->where('assigned_team_member_id', $membership->id);

            $stats = [
                'tasks_total' => (clone $tasksQuery)->count(),
                'tasks_todo' => (clone $tasksQuery)->where('status', 'todo')->count(),
                'tasks_in_progress' => (clone $tasksQuery)->where('status', 'in_progress')->count(),
                'tasks_done' => (clone $tasksQuery)->where('status', 'done')->count(),
            ];

            $tasks = (clone $tasksQuery)
                ->with('assignee.user:id,name')
                ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('due_date')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(['id', 'title', 'status', 'due_date', 'assigned_team_member_id'])
                ->map(function ($task) {
                    return [
                        'id' => $task->id,
                        'title' => $task->title,
                        'status' => $task->status,
                        'due_date' => $task->due_date,
                        'assignee' => $task->assignee?->user ? [
                            'name' => $task->assignee->user->name,
                        ] : null,
                    ];
                });

            return Inertia::render('DashboardMember', [
                'stats' => $stats,
                'tasks' => $tasks,
            ]);
        }

        $userId = $accountId;
        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();
        $recentSince = $now->copy()->subDays(30);

        $customersQuery = Customer::byUser($userId);
        $productsQuery = Product::byUser($userId);
        $quotesQuery = Quote::byUser($userId);
        $worksQuery = Work::byUser($userId);
        $invoicesQuery = Invoice::byUser($userId);

        $inventoryValue = (clone $productsQuery)
            ->selectRaw('COALESCE(SUM(stock * COALESCE(NULLIF(cost_price, 0), price)), 0) as value')
            ->value('value');

        $scheduledStatuses = [Work::STATUS_TO_SCHEDULE, Work::STATUS_SCHEDULED];
        $inProgressStatuses = [Work::STATUS_EN_ROUTE, Work::STATUS_IN_PROGRESS];
        $completedStatuses = [
            Work::STATUS_TECH_COMPLETE,
            Work::STATUS_PENDING_REVIEW,
            Work::STATUS_VALIDATED,
            Work::STATUS_AUTO_VALIDATED,
            Work::STATUS_CLOSED,
            Work::STATUS_COMPLETED,
        ];

        $stats = [
            'customers_total' => (clone $customersQuery)->count(),
            'customers_new' => (clone $customersQuery)->whereDate('created_at', '>=', $recentSince)->count(),
            'products_total' => (clone $productsQuery)->count(),
            'products_low_stock' => (clone $productsQuery)
                ->whereColumn('stock', '<=', 'minimum_stock')
                ->where('stock', '>', 0)
                ->count(),
            'products_out' => (clone $productsQuery)->where('stock', '<=', 0)->count(),
            'inventory_value' => $inventoryValue,
            'quotes_total' => (clone $quotesQuery)->count(),
            'quotes_open' => (clone $quotesQuery)->whereIn('status', ['draft', 'sent'])->count(),
            'quotes_accepted' => (clone $quotesQuery)->where('status', 'accepted')->count(),
            'quotes_month' => (clone $quotesQuery)->whereDate('created_at', '>=', $startOfMonth)->count(),
            'works_total' => (clone $worksQuery)->count(),
            'works_scheduled' => (clone $worksQuery)->whereIn('status', $scheduledStatuses)->count(),
            'works_in_progress' => (clone $worksQuery)->whereIn('status', $inProgressStatuses)->count(),
            'works_completed' => (clone $worksQuery)->whereIn('status', $completedStatuses)->count(),
            'invoices_total' => (clone $invoicesQuery)->count(),
            'invoices_paid' => (clone $invoicesQuery)->where('status', 'paid')->count(),
            'invoices_partial' => (clone $invoicesQuery)->where('status', 'partial')->count(),
            'invoices_overdue' => (clone $invoicesQuery)->where('status', 'overdue')->count(),
        ];

        $revenueBilled = (clone $invoicesQuery)->sum('total');
        $revenuePaid = Payment::where('user_id', $userId)->sum('amount');
        $stats['revenue_billed'] = $revenueBilled;
        $stats['revenue_paid'] = $revenuePaid;
        $stats['revenue_outstanding'] = max(0, $revenueBilled - $revenuePaid);
        $stats['payments_month'] = Payment::where('user_id', $userId)
            ->whereDate('paid_at', '>=', $startOfMonth)
            ->sum('amount');

        $recentQuotes = Quote::byUser($userId)
            ->with('customer:id,company_name,first_name,last_name')
            ->latest()
            ->limit(5)
            ->get(['id', 'number', 'status', 'total', 'customer_id', 'created_at'])
            ->map(function ($quote) {
                return [
                    'id' => $quote->id,
                    'number' => $quote->number,
                    'status' => $quote->status,
                    'total' => (float) $quote->total,
                    'created_at' => $quote->created_at,
                    'customer' => $quote->customer ? [
                        'company_name' => $quote->customer->company_name,
                        'first_name' => $quote->customer->first_name,
                        'last_name' => $quote->customer->last_name,
                    ] : null,
                ];
            });

        $upcomingJobs = Work::byUser($userId)
            ->with('customer:id,company_name,first_name,last_name')
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->orderBy('start_date')
            ->orderBy('start_time')
            ->limit(5)
            ->get(['id', 'job_title', 'status', 'start_date', 'start_time', 'customer_id'])
            ->map(function ($work) {
                return [
                    'id' => $work->id,
                    'job_title' => $work->job_title,
                    'status' => $work->status,
                    'start_date' => $work->start_date,
                    'start_time' => $work->start_time,
                    'customer' => $work->customer ? [
                        'company_name' => $work->customer->company_name,
                        'first_name' => $work->customer->first_name,
                        'last_name' => $work->customer->last_name,
                    ] : null,
                ];
            });

        $outstandingInvoices = Invoice::byUser($userId)
            ->with('customer:id,company_name,first_name,last_name')
            ->withSum('payments', 'amount')
            ->whereNotIn('status', ['paid', 'void'])
            ->orderByDesc('total')
            ->limit(5)
            ->get(['id', 'number', 'status', 'total', 'customer_id', 'created_at'])
            ->map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'status' => $invoice->status,
                    'total' => (float) $invoice->total,
                    'amount_paid' => (float) ($invoice->payments_sum_amount ?? 0),
                    'balance_due' => $invoice->balance_due,
                    'created_at' => $invoice->created_at,
                    'customer' => $invoice->customer ? [
                        'company_name' => $invoice->customer->company_name,
                        'first_name' => $invoice->customer->first_name,
                        'last_name' => $invoice->customer->last_name,
                    ] : null,
                ];
            });

        $subjectLabels = [
            Quote::class => 'Quote',
            Work::class => 'Job',
            Invoice::class => 'Invoice',
            Payment::class => 'Payment',
            Product::class => 'Product',
            Customer::class => 'Customer',
        ];

        $activity = ActivityLog::query()
            ->where('user_id', $userId)
            ->latest()
            ->limit(8)
            ->get(['id', 'action', 'description', 'subject_type', 'subject_id', 'created_at'])
            ->map(function ($log) use ($subjectLabels) {
                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'description' => $log->description,
                    'subject' => $subjectLabels[$log->subject_type] ?? 'Item',
                    'created_at' => $log->created_at,
                ];
            });

        $labels = [];
        $values = [];
        for ($i = 5; $i >= 0; $i -= 1) {
            $date = $now->copy()->subMonths($i);
            $start = $date->copy()->startOfMonth();
            $end = $date->copy()->endOfMonth();
            $labels[] = $date->format('M');
            $values[] = (float) Payment::where('user_id', $userId)
                ->whereBetween('paid_at', [$start, $end])
                ->sum('amount');
        }

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'recentQuotes' => $recentQuotes,
            'upcomingJobs' => $upcomingJobs,
            'outstandingInvoices' => $outstandingInvoices,
            'activity' => $activity,
            'revenueSeries' => [
                'labels' => $labels,
                'values' => $values,
            ],
        ]);
    }
}
