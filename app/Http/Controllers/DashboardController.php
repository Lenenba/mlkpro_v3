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
use App\Models\PlatformAnnouncement;
use App\Models\User;
use App\Services\UsageLimitService;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Laravel\Paddle\Cashier;
use Laravel\Paddle\Subscription;

class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $now = now();
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
                    'autoValidation' => [
                        'tasks' => false,
                        'invoices' => false,
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
            $autoValidateTasks = (bool) ($customer->auto_validate_tasks ?? false);
            $autoValidateInvoices = (bool) ($customer->auto_validate_invoices ?? false);

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

            $invoicesDueQuery = $autoValidateInvoices
                ? null
                : Invoice::query()
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
                'invoices_due' => $autoValidateInvoices ? 0 : (clone $invoicesDueQuery)->count(),
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

            $taskProofs = $autoValidateTasks
                ? collect()
                : Task::query()
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

            $invoicesDue = $autoValidateInvoices
                ? collect()
                : (clone $invoicesDueQuery)
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

            $seriesMonths = 6;
            $quotesPendingSeries = $this->buildMonthlySeries($now, $seriesMonths, function ($start, $end) use ($pendingQuotesQuery) {
                return (clone $pendingQuotesQuery)
                    ->whereBetween('created_at', [$start, $end])
                    ->count();
            });
            $worksPendingSeries = $this->buildMonthlySeries($now, $seriesMonths, function ($start, $end) use ($pendingWorksQuery) {
                return (clone $pendingWorksQuery)
                    ->whereBetween('created_at', [$start, $end])
                    ->count();
            });
            $invoicesDueSeries = $autoValidateInvoices
                ? ['values' => array_fill(0, $seriesMonths, 0)]
                : $this->buildMonthlySeries($now, $seriesMonths, function ($start, $end) use ($invoicesDueQuery) {
                    return (clone $invoicesDueQuery)
                        ->whereBetween('created_at', [$start, $end])
                        ->count();
                });
            $ratingsDueSeries = $this->buildMonthlySeries($now, $seriesMonths, function ($start, $end) use ($quoteRatingsQuery, $workRatingsQuery) {
                $quoteCount = (clone $quoteRatingsQuery)
                    ->whereBetween('updated_at', [$start, $end])
                    ->count();
                $workCount = (clone $workRatingsQuery)
                    ->whereBetween('updated_at', [$start, $end])
                    ->count();
                return $quoteCount + $workCount;
            });
            $kpiSeries = [
                'quotes_pending' => $quotesPendingSeries['values'],
                'works_pending' => $worksPendingSeries['values'],
                'invoices_due' => $invoicesDueSeries['values'],
                'ratings_due' => $ratingsDueSeries['values'],
            ];

            return Inertia::render('DashboardClient', [
                'profileMissing' => false,
                'stats' => $stats,
                'autoValidation' => [
                    'tasks' => $autoValidateTasks,
                    'invoices' => $autoValidateInvoices,
                ],
                'kpiSeries' => $kpiSeries,
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
        $accountOwner = User::query()->find($accountId);
        $internalAnnouncements = $this->resolveAnnouncements(
            $accountId,
            $accountOwner,
            'internal'
        );
        $quickAnnouncements = $this->resolveAnnouncements(
            $accountId,
            $accountOwner,
            'quick_actions'
        );
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

                $seriesMonths = 6;
                $tasksTotalSeries = $this->buildMonthlySeries($now, $seriesMonths, function ($start, $end) use ($tasksQuery) {
                    return (clone $tasksQuery)
                        ->whereBetween('created_at', [$start, $end])
                        ->count();
                });
                $tasksTodoSeries = $this->buildMonthlySeries($now, $seriesMonths, function ($start, $end) use ($tasksQuery) {
                    return (clone $tasksQuery)
                        ->where('status', 'todo')
                        ->whereBetween('created_at', [$start, $end])
                        ->count();
                });
                $tasksInProgressSeries = $this->buildMonthlySeries($now, $seriesMonths, function ($start, $end) use ($tasksQuery) {
                    return (clone $tasksQuery)
                        ->where('status', 'in_progress')
                        ->whereBetween('created_at', [$start, $end])
                        ->count();
                });
                $tasksDoneSeries = $this->buildMonthlySeries($now, $seriesMonths, function ($start, $end) use ($tasksQuery) {
                    return (clone $tasksQuery)
                        ->where('status', 'done')
                        ->whereBetween('created_at', [$start, $end])
                        ->count();
                });
                $kpiSeries = [
                    'tasks_total' => $tasksTotalSeries['values'],
                    'tasks_todo' => $tasksTodoSeries['values'],
                    'tasks_in_progress' => $tasksInProgressSeries['values'],
                    'tasks_done' => $tasksDoneSeries['values'],
                ];

                return Inertia::render('DashboardAdmin', [
                    'stats' => $stats,
                    'tasks' => $tasks,
                    'announcements' => $internalAnnouncements,
                    'quickAnnouncements' => $quickAnnouncements,
                    'kpiSeries' => $kpiSeries,
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

            $seriesMonths = 6;
            $tasksTodoSeries = $this->buildMonthlySeries($now, $seriesMonths, function ($start, $end) use ($tasksQuery) {
                return (clone $tasksQuery)
                    ->where('status', 'todo')
                    ->whereBetween('created_at', [$start, $end])
                    ->count();
            });
            $tasksInProgressSeries = $this->buildMonthlySeries($now, $seriesMonths, function ($start, $end) use ($tasksQuery) {
                return (clone $tasksQuery)
                    ->where('status', 'in_progress')
                    ->whereBetween('created_at', [$start, $end])
                    ->count();
            });
            $tasksDoneSeries = $this->buildMonthlySeries($now, $seriesMonths, function ($start, $end) use ($tasksQuery) {
                return (clone $tasksQuery)
                    ->where('status', 'done')
                    ->whereBetween('created_at', [$start, $end])
                    ->count();
            });
            $kpiSeries = [
                'tasks_todo' => $tasksTodoSeries['values'],
                'tasks_in_progress' => $tasksInProgressSeries['values'],
                'tasks_done' => $tasksDoneSeries['values'],
            ];

            return Inertia::render('DashboardMember', [
                'stats' => $stats,
                'tasks' => $tasks,
                'announcements' => $internalAnnouncements,
                'quickAnnouncements' => $quickAnnouncements,
                'kpiSeries' => $kpiSeries,
            ]);
        }

        $userId = $accountId;
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

        $seriesMonths = 6;
        $revenueSeries = $this->buildMonthlySeries($now, $seriesMonths, function ($start, $end) use ($userId) {
            return (float) Payment::where('user_id', $userId)
                ->whereBetween('paid_at', [$start, $end])
                ->sum('amount');
        });
        $revenueOutstandingSeries = $this->buildMonthlySeries($now, $seriesMonths, function ($start, $end) use ($invoicesQuery) {
            return (float) (clone $invoicesQuery)
                ->whereNotIn('status', ['paid', 'void'])
                ->whereBetween('created_at', [$start, $end])
                ->sum('total');
        });
        $quotesOpenSeries = $this->buildMonthlySeries($now, $seriesMonths, function ($start, $end) use ($quotesQuery) {
            return (clone $quotesQuery)
                ->whereIn('status', ['draft', 'sent'])
                ->whereBetween('created_at', [$start, $end])
                ->count();
        });
        $worksInProgressSeries = $this->buildMonthlySeries($now, $seriesMonths, function ($start, $end) use ($worksQuery, $inProgressStatuses) {
            return (clone $worksQuery)
                ->whereIn('status', $inProgressStatuses)
                ->whereBetween('created_at', [$start, $end])
                ->count();
        });
        $customersSeries = $this->buildMonthlySeries($now, $seriesMonths, function ($start, $end) use ($customersQuery) {
            return (clone $customersQuery)
                ->whereBetween('created_at', [$start, $end])
                ->count();
        });
        $lowStockSeries = $this->buildMonthlySeries($now, $seriesMonths, function ($start, $end) use ($productsQuery) {
            return (clone $productsQuery)
                ->whereColumn('stock', '<=', 'minimum_stock')
                ->where('stock', '>', 0)
                ->whereBetween('updated_at', [$start, $end])
                ->count();
        });
        $invoicesPaidSeries = $this->buildMonthlySeries($now, $seriesMonths, function ($start, $end) use ($invoicesQuery) {
            return (clone $invoicesQuery)
                ->where('status', 'paid')
                ->whereBetween('updated_at', [$start, $end])
                ->count();
        });
        $inventorySeries = $this->buildMonthlySeries($now, $seriesMonths, function ($start, $end) use ($productsQuery) {
            return (float) (clone $productsQuery)
                ->whereBetween('created_at', [$start, $end])
                ->selectRaw('COALESCE(SUM(stock * COALESCE(NULLIF(cost_price, 0), price)), 0) as value')
                ->value('value');
        });
        $kpiSeries = [
            'revenue_paid' => $revenueSeries['values'],
            'revenue_outstanding' => $revenueOutstandingSeries['values'],
            'quotes_open' => $quotesOpenSeries['values'],
            'works_in_progress' => $worksInProgressSeries['values'],
            'customers_total' => $customersSeries['values'],
            'products_low_stock' => $lowStockSeries['values'],
            'invoices_paid' => $invoicesPaidSeries['values'],
            'inventory_value' => $inventorySeries['values'],
        ];

        $plans = collect(config('billing.plans', []))
            ->map(function (array $plan, string $key) {
                return [
                    'key' => $key,
                    'name' => $plan['name'] ?? ucfirst($key),
                    'price_id' => $plan['price_id'] ?? null,
                    'price' => $plan['price'] ?? null,
                    'display_price' => $this->resolvePlanDisplayPrice($plan),
                    'features' => $plan['features'] ?? [],
                ];
            })
            ->values()
            ->all();

        $subscription = $accountOwner?->subscription(Subscription::DEFAULT_TYPE);
        $subscriptionPriceId = $subscription?->items()->value('price_id');
        $usageLimits = $accountOwner
            ? app(UsageLimitService::class)->buildForUser($accountOwner)
            : ['items' => []];

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'recentQuotes' => $recentQuotes,
            'upcomingJobs' => $upcomingJobs,
            'outstandingInvoices' => $outstandingInvoices,
            'activity' => $activity,
            'revenueSeries' => $revenueSeries,
            'kpiSeries' => $kpiSeries,
            'announcements' => $internalAnnouncements,
            'quickAnnouncements' => $quickAnnouncements,
            'usage_limits' => $usageLimits,
            'billing' => [
                'plans' => $plans,
                'subscription' => [
                    'active' => $accountOwner?->subscribed(Subscription::DEFAULT_TYPE) ?? false,
                    'on_trial' => $accountOwner?->onTrial(Subscription::DEFAULT_TYPE) ?? false,
                    'status' => $subscription?->status,
                    'price_id' => $subscriptionPriceId,
                    'paddle_id' => $subscription?->paddle_id,
                ],
            ],
        ]);
    }

    private function buildMonthlySeries($now, int $months, callable $resolver): array
    {
        $labels = [];
        $values = [];
        for ($i = $months - 1; $i >= 0; $i -= 1) {
            $date = $now->copy()->subMonths($i);
            $start = $date->copy()->startOfMonth();
            $end = $date->copy()->endOfMonth();
            $labels[] = $date->format('M');
            $values[] = $resolver($start, $end);
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    private function resolveAnnouncements(int $tenantId, ?User $tenant, string $placement): array
    {
        $now = now();

        $placements = $placement === 'internal'
            ? ['internal', 'client', 'both']
            : [$placement, 'both'];

        $announcements = PlatformAnnouncement::query()
            ->active()
            ->whereIn('placement', $placements)
            ->where(function ($query) use ($now) {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $now);
            })
            ->where(function ($query) use ($tenantId) {
                $query->where('audience', 'all')
                    ->orWhere(function ($sub) use ($tenantId) {
                        $sub->where('audience', 'tenants')
                            ->whereHas('tenants', function ($tenantQuery) use ($tenantId) {
                                $tenantQuery->where('users.id', $tenantId);
                            });
                    })
                    ->orWhere('audience', 'new_tenants');
            })
            ->orderByDesc('priority')
            ->orderByDesc('starts_at')
            ->limit(6)
            ->get()
            ->filter(function (PlatformAnnouncement $announcement) use ($tenant, $now) {
                if ($announcement->audience !== 'new_tenants') {
                    return true;
                }

                if (!$tenant?->created_at) {
                    return false;
                }

                $days = $announcement->new_tenant_days ?: 30;
                return $tenant->created_at->gte($now->copy()->subDays($days));
            })
            ->map(function (PlatformAnnouncement $announcement) {
                return [
                    'id' => $announcement->id,
                    'title' => $announcement->title,
                    'body' => $announcement->body,
                    'display_style' => $announcement->display_style,
                    'background_color' => $announcement->background_color,
                    'media_type' => $announcement->media_type,
                    'media_url' => $announcement->media_url,
                    'link_label' => $announcement->link_label,
                    'link_url' => $announcement->link_url,
                    'starts_at' => $announcement->starts_at?->toDateString(),
                    'ends_at' => $announcement->ends_at?->toDateString(),
                ];
            })
            ->values();

        return $announcements->all();
    }

    private function resolvePlanDisplayPrice(array $plan): ?string
    {
        $raw = $plan['price'] ?? null;
        $rawValue = is_string($raw) ? trim($raw) : $raw;

        if (is_numeric($rawValue)) {
            return Cashier::formatAmount((int) round((float) $rawValue * 100), config('cashier.currency', 'USD'));
        }

        if (is_string($rawValue) && $rawValue !== '') {
            return $rawValue;
        }

        return null;
    }
}
