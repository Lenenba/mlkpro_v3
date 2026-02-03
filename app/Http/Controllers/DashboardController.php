<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use App\Models\Work;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductLot;
use App\Models\Customer;
use App\Models\ActivityLog;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\PlanScan;
use App\Models\PlatformAnnouncement;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\BillingSubscriptionService;
use App\Services\StripeInvoiceService;
use App\Services\StripeSaleService;
use App\Services\UsageLimitService;
use App\Support\PlanDisplay;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Paddle\Cashier;

class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        if ($user && $user->isSuperadmin()) {
            return redirect()->route('superadmin.dashboard');
        }
        $cacheEnabled = empty(request()->query());
        $cacheTtl = now()->addSeconds(30);
        $fromCache = function (?string $cacheKey) use ($cacheEnabled) {
            if (!$cacheEnabled || !$cacheKey) {
                return null;
            }
            return Cache::get($cacheKey);
        };
        $respond = function (string $component, array $props, ?string $cacheKey = null) use ($cacheEnabled, $cacheTtl) {
            if ($cacheEnabled && $cacheKey) {
                Cache::put($cacheKey, ['component' => $component, 'props' => $props], $cacheTtl);
            }
            return $this->inertiaOrJson($component, $props);
        };
        $now = now();
        $today = $now->toDateString();
        if ($user && $user->isClient()) {
            $customer = $user->customerProfile;
            if (!$customer) {
                $cacheKey = $cacheEnabled ? "dashboard:client-missing:{$user->id}" : null;
                if ($cached = $fromCache($cacheKey)) {
                    return $this->inertiaOrJson($cached['component'], $cached['props']);
                }
                return $respond('DashboardClient', [
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
                    'stripe' => [
                        'enabled' => app(StripeInvoiceService::class)->isConfigured(),
                    ],
                ], $cacheKey);
            }

            $accountOwner = User::query()->select(['id', 'company_type', 'company_name'])->find($customer->user_id);
            if ($accountOwner?->company_type === 'products') {
                $cacheKey = $cacheEnabled ? "dashboard:client-products:{$user->id}" : null;
                if ($cached = $fromCache($cacheKey)) {
                    return $this->inertiaOrJson($cached['component'], $cached['props']);
                }
                $customerId = $customer->id;
                $salesQuery = Sale::query()
                    ->where('user_id', $accountOwner->id)
                    ->where('customer_id', $customerId);

                $stats = [
                    'orders_total' => (clone $salesQuery)->count(),
                    'orders_pending' => (clone $salesQuery)->where('status', Sale::STATUS_PENDING)->count(),
                    'orders_paid' => (clone $salesQuery)->where('status', Sale::STATUS_PAID)->count(),
                    'amount_paid' => (float) (clone $salesQuery)->where('status', Sale::STATUS_PAID)->sum('total'),
                ];

                $recentSales = (clone $salesQuery)
                    ->latest()
                    ->limit(8)
                    ->get([
                        'id',
                        'number',
                        'status',
                        'total',
                        'created_at',
                        'fulfillment_method',
                        'fulfillment_status',
                        'scheduled_for',
                        'delivery_confirmed_at',
                    ]);

                $pendingOrders = (clone $salesQuery)
                    ->where('status', '!=', Sale::STATUS_CANCELED)
                    ->where(function ($query) {
                        $query->whereNull('fulfillment_status')
                            ->orWhereIn('fulfillment_status', [
                                Sale::FULFILLMENT_PENDING,
                                Sale::FULFILLMENT_PREPARING,
                                Sale::FULFILLMENT_READY_FOR_PICKUP,
                                Sale::FULFILLMENT_COMPLETED,
                            ]);
                    })
                    ->latest()
                    ->limit(6)
                    ->get([
                        'id',
                        'number',
                        'status',
                        'total',
                        'deposit_amount',
                        'created_at',
                        'fulfillment_method',
                        'fulfillment_status',
                        'scheduled_for',
                        'delivery_confirmed_at',
                    ])
                    ->loadSum(['payments as payments_sum_amount' => fn($query) => $query->where('status', 'completed')], 'amount');

                $inDeliveryOrders = (clone $salesQuery)
                    ->where('status', '!=', Sale::STATUS_CANCELED)
                    ->where('fulfillment_method', 'delivery')
                    ->where('fulfillment_status', Sale::FULFILLMENT_OUT_FOR_DELIVERY)
                    ->latest()
                    ->limit(6)
                    ->get([
                        'id',
                        'number',
                        'status',
                        'total',
                        'created_at',
                        'fulfillment_method',
                        'fulfillment_status',
                        'scheduled_for',
                        'delivery_confirmed_at',
                    ]);

                $now = now();
                $deliveryAlerts = (clone $salesQuery)
                    ->where('status', '!=', Sale::STATUS_CANCELED)
                    ->where('fulfillment_method', 'delivery')
                    ->where(function ($query) use ($now) {
                        $query->where('fulfillment_status', Sale::FULFILLMENT_OUT_FOR_DELIVERY)
                            ->orWhere(function ($sub) use ($now) {
                                $sub->whereNotNull('scheduled_for')
                                    ->whereBetween('scheduled_for', [
                                        $now->copy()->subMinutes(30),
                                        $now->copy()->addHours(6),
                                    ]);
                            });
                    })
                    ->orderByRaw('scheduled_for is null, scheduled_for asc')
                    ->limit(6)
                    ->get([
                        'id',
                        'number',
                        'status',
                        'total',
                        'created_at',
                        'fulfillment_method',
                        'fulfillment_status',
                        'scheduled_for',
                        'delivery_confirmed_at',
                    ]);

                $props = [
                    'company' => [
                        'name' => $accountOwner->company_name,
                    ],
                    'stats' => $stats,
                    'sales' => $recentSales,
                    'pendingOrders' => $pendingOrders,
                    'inDeliveryOrders' => $inDeliveryOrders,
                    'deliveryAlerts' => $deliveryAlerts,
                    'stripe' => [
                        'enabled' => app(StripeSaleService::class)->isConfigured(),
                    ],
                ];

                return $respond('DashboardProductsClient', $props, $cacheKey);
            }

            $customerId = $customer->id;
            $autoValidateTasks = (bool) ($customer->auto_validate_tasks ?? false);
            $autoValidateInvoices = (bool) ($customer->auto_validate_invoices ?? false);

            $cacheKey = $cacheEnabled ? "dashboard:client:{$user->id}" : null;
            if ($cached = $fromCache($cacheKey)) {
                return $this->inertiaOrJson($cached['component'], $cached['props']);
            }

            $sessionId = request()->query('session_id');
            $invoiceId = request()->query('invoice');
            if ($sessionId && $invoiceId) {
                $stripeService = app(StripeInvoiceService::class);
                if ($stripeService->isConfigured()) {
                    $invoice = Invoice::query()->find($invoiceId);
                    $connectAccountId = $invoice ? $stripeService->resolveConnectedAccountId($invoice) : null;
                    $payment = $stripeService->syncFromCheckoutSessionId($sessionId, $connectAccountId);
                    if ($payment && (int) $payment->invoice_id === (int) $invoiceId) {
                        if ($invoice && (int) $invoice->customer_id === (int) $customerId) {
                            $invoice->refresh();
                        }
                    }
                }
            }

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

            $props = [
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
                'stripe' => [
                    'enabled' => app(StripeInvoiceService::class)->isConfigured(),
                ],
            ];

            return $respond('DashboardClient', $props, $cacheKey);
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

        if ($accountOwner?->company_type === 'products') {
            $cacheKey = $cacheEnabled ? "dashboard:products:{$accountId}:{$user?->id}" : null;
            if ($cached = $fromCache($cacheKey)) {
                return $this->inertiaOrJson($cached['component'], $cached['props']);
            }
            $restrictSales = $membership
                && !$membership->hasPermission('sales.manage')
                && $membership->hasPermission('sales.pos');

            $salesBaseQuery = Sale::query()
                ->where('user_id', $accountId)
                ->where('status', Sale::STATUS_PAID)
                ->when($restrictSales, fn($query) => $query->where('created_by_user_id', $user->id));
            $salesTodayQuery = (clone $salesBaseQuery)->whereDate('created_at', $today);
            $salesMonthQuery = (clone $salesBaseQuery)
                ->whereBetween('created_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()]);

            $productsQuery = Product::query()
                ->products()
                ->byUser($accountId);

            $stats = [
                'sales_today' => (clone $salesTodayQuery)->count(),
                'sales_month' => (clone $salesMonthQuery)->count(),
                'revenue_today' => (float) (clone $salesTodayQuery)
                    ->where('status', Sale::STATUS_PAID)
                    ->sum('total'),
                'revenue_month' => (float) (clone $salesMonthQuery)
                    ->where('status', Sale::STATUS_PAID)
                    ->sum('total'),
                'inventory_value' => (float) (clone $productsQuery)
                    ->select(DB::raw('COALESCE(SUM(stock * COALESCE(NULLIF(cost_price, 0), price)), 0) as value'))
                    ->value('value'),
                'products_total' => (clone $productsQuery)->count(),
                'low_stock' => (clone $productsQuery)
                    ->whereColumn('stock', '<=', 'minimum_stock')
                    ->where('stock', '>', 0)
                    ->count(),
                'out_of_stock' => (clone $productsQuery)
                    ->where('stock', '<=', 0)
                    ->count(),
            ];

            $stats['reserved_total'] = (int) ProductInventory::query()
                ->whereHas('product', fn($query) => $query->where('user_id', $accountId))
                ->sum('reserved');
            $stats['damaged_total'] = (int) ProductInventory::query()
                ->whereHas('product', fn($query) => $query->where('user_id', $accountId))
                ->sum('damaged');

            $expiringDate = $now->copy()->addDays(30)->toDateString();
            $stats['expired_lots'] = (int) ProductLot::query()
                ->whereHas('product', fn($query) => $query->where('user_id', $accountId))
                ->whereNotNull('expires_at')
                ->whereDate('expires_at', '<', $today)
                ->count();
            $stats['expiring_lots'] = (int) ProductLot::query()
                ->whereHas('product', fn($query) => $query->where('user_id', $accountId))
                ->whereNotNull('expires_at')
                ->whereDate('expires_at', '>=', $today)
                ->whereDate('expires_at', '<=', $expiringDate)
                ->count();

            $recentSales = (clone $salesBaseQuery)
                ->with('customer:id,first_name,last_name,company_name')
                ->latest()
                ->limit(8)
                ->get(['id', 'number', 'status', 'total', 'created_at', 'customer_id']);

            $stockAlerts = (clone $productsQuery)
                ->where(function ($query) {
                    $query->where('stock', '<=', 0)
                        ->orWhereColumn('stock', '<=', 'minimum_stock');
                })
                ->orderBy('stock')
                ->limit(8)
                ->get(['id', 'name', 'stock', 'minimum_stock', 'image', 'supplier_name', 'supplier_email']);

            $topSales = SaleItem::query()
                ->select('sale_items.product_id', DB::raw('SUM(sale_items.quantity) as quantity'))
                ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                ->where('sales.user_id', $accountId)
                ->where('sales.status', Sale::STATUS_PAID)
                ->when($restrictSales, fn($query) => $query->where('sales.created_by_user_id', $user->id))
                ->groupBy('sale_items.product_id')
                ->orderByDesc('quantity')
                ->limit(6)
                ->get();

            $topProducts = collect();
            if ($topSales->isNotEmpty()) {
                $productMap = Product::query()
                    ->whereIn('id', $topSales->pluck('product_id'))
                    ->get(['id', 'name', 'image'])
                    ->keyBy('id');

                $topProducts = $topSales->map(function ($row) use ($productMap) {
                    $product = $productMap->get($row->product_id);

                    return [
                        'id' => $row->product_id,
                        'name' => $product?->name ?? 'Product',
                        'image_url' => $product?->image_url,
                        'quantity' => (int) $row->quantity,
                    ];
                })->values();
            }

            if ($membership) {
                $props = [
                    'stats' => $stats,
                    'recentSales' => $recentSales,
                    'stockAlerts' => $stockAlerts,
                    'topProducts' => $topProducts,
                ];

                return $respond('DashboardProductsTeam', $props, $cacheKey);
            }

            $performance = $this->buildProductPerformance($accountId, $now);

            $props = [
                'stats' => $stats,
                'recentSales' => $recentSales,
                'stockAlerts' => $stockAlerts,
                'topProducts' => $topProducts,
                'performance' => $performance,
            ];

            return $respond('DashboardProductsOwner', $props, $cacheKey);
        }

        if ($membership) {
            if ($membership->role === 'admin') {
                $cacheKey = $cacheEnabled ? "dashboard:admin:{$accountId}:{$user->id}" : null;
                if ($cached = $fromCache($cacheKey)) {
                    return $this->inertiaOrJson($cached['component'], $cached['props']);
                }
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
                    ->get([
                        'id',
                        'title',
                        'status',
                        'due_date',
                        'start_time',
                        'end_time',
                        'auto_started_at',
                        'auto_completed_at',
                        'assigned_team_member_id',
                    ])
                    ->map(function ($task) {
                        return [
                            'id' => $task->id,
                            'title' => $task->title,
                            'status' => $task->status,
                            'due_date' => $task->due_date,
                            'start_time' => $task->start_time,
                            'end_time' => $task->end_time,
                            'auto_started_at' => $task->auto_started_at,
                            'auto_completed_at' => $task->auto_completed_at,
                            'assignee' => $task->assignee?->user ? [
                                'name' => $task->assignee->user->name,
                            ] : null,
                        ];
                    });

                $tasksToday = (clone $tasksQuery)
                    ->with('assignee.user:id,name')
                    ->whereDate('due_date', $today)
                    ->whereIn('status', ['todo', 'in_progress'])
                    ->orderByRaw('CASE WHEN start_time IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('start_time')
                    ->orderByDesc('created_at')
                    ->limit(12)
                    ->get([
                        'id',
                        'title',
                        'status',
                        'due_date',
                        'start_time',
                        'end_time',
                        'auto_started_at',
                        'auto_completed_at',
                        'assigned_team_member_id',
                    ])
                    ->map(function ($task) {
                        return [
                            'id' => $task->id,
                            'title' => $task->title,
                            'status' => $task->status,
                            'due_date' => $task->due_date,
                            'start_time' => $task->start_time,
                            'end_time' => $task->end_time,
                            'auto_started_at' => $task->auto_started_at,
                            'auto_completed_at' => $task->auto_completed_at,
                            'assignee' => $task->assignee?->user ? [
                                'name' => $task->assignee->user->name,
                            ] : null,
                        ];
                    });

                $worksQuery = Work::query()->byUser($accountId);
                $worksToday = $this->buildWorksToday($worksQuery, $today);
                $agendaAlerts = $this->buildAgendaAlerts($tasksQuery, $worksQuery, $today);

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

                $props = [
                    'stats' => $stats,
                    'tasks' => $tasks,
                    'tasksToday' => $tasksToday,
                    'worksToday' => $worksToday,
                    'agendaAlerts' => $agendaAlerts,
                    'announcements' => $internalAnnouncements,
                    'quickAnnouncements' => $quickAnnouncements,
                    'kpiSeries' => $kpiSeries,
                ];

                return $respond('DashboardAdmin', $props, $cacheKey);
            }

            $cacheKey = $cacheEnabled ? "dashboard:member:{$accountId}:{$user->id}" : null;
            if ($cached = $fromCache($cacheKey)) {
                return $this->inertiaOrJson($cached['component'], $cached['props']);
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
                ->get([
                    'id',
                    'title',
                    'status',
                    'due_date',
                    'start_time',
                    'end_time',
                    'auto_started_at',
                    'auto_completed_at',
                    'assigned_team_member_id',
                ])
                ->map(function ($task) {
                    return [
                        'id' => $task->id,
                        'title' => $task->title,
                        'status' => $task->status,
                        'due_date' => $task->due_date,
                        'start_time' => $task->start_time,
                        'end_time' => $task->end_time,
                        'auto_started_at' => $task->auto_started_at,
                        'auto_completed_at' => $task->auto_completed_at,
                        'assignee' => $task->assignee?->user ? [
                            'name' => $task->assignee->user->name,
                        ] : null,
                    ];
                });

            $tasksToday = (clone $tasksQuery)
                ->with('assignee.user:id,name')
                ->whereDate('due_date', $today)
                ->whereIn('status', ['todo', 'in_progress'])
                ->orderByRaw('CASE WHEN start_time IS NULL THEN 1 ELSE 0 END')
                ->orderBy('start_time')
                ->orderByDesc('created_at')
                ->limit(12)
                ->get([
                    'id',
                    'title',
                    'status',
                    'due_date',
                    'start_time',
                    'end_time',
                    'auto_started_at',
                    'auto_completed_at',
                    'assigned_team_member_id',
                ])
                ->map(function ($task) {
                    return [
                        'id' => $task->id,
                        'title' => $task->title,
                        'status' => $task->status,
                        'due_date' => $task->due_date,
                        'start_time' => $task->start_time,
                        'end_time' => $task->end_time,
                        'auto_started_at' => $task->auto_started_at,
                        'auto_completed_at' => $task->auto_completed_at,
                        'assignee' => $task->assignee?->user ? [
                            'name' => $task->assignee->user->name,
                        ] : null,
                    ];
                });

            $worksQuery = Work::query()
                ->byUser($accountId)
                ->whereHas('teamMembers', fn($query) => $query->whereKey($membership->id));
            $worksToday = $this->buildWorksToday($worksQuery, $today);
            $agendaAlerts = $this->buildAgendaAlerts($tasksQuery, $worksQuery, $today);

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

            $props = [
                'stats' => $stats,
                'tasks' => $tasks,
                'tasksToday' => $tasksToday,
                'worksToday' => $worksToday,
                'agendaAlerts' => $agendaAlerts,
                'announcements' => $internalAnnouncements,
                'quickAnnouncements' => $quickAnnouncements,
                'kpiSeries' => $kpiSeries,
            ];

            return $respond('DashboardMember', $props, $cacheKey);
        }

        $cacheKey = $cacheEnabled ? "dashboard:owner:{$accountId}:{$user?->id}" : null;
        if ($cached = $fromCache($cacheKey)) {
            return $this->inertiaOrJson($cached['component'], $cached['props']);
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
            ->select(DB::raw('COALESCE(SUM(stock * COALESCE(NULLIF(cost_price, 0), price)), 0) as value'))
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
            'plan_scans_total' => PlanScan::query()->where('user_id', $userId)->count(),
        ];

        $revenueBilled = (clone $invoicesQuery)->sum('total');
        $revenuePaid = Payment::where('user_id', $userId)->sum('amount');
        $stats['revenue_billed'] = $revenueBilled;
        $stats['revenue_paid'] = $revenuePaid;
        $stats['revenue_outstanding'] = max(0, $revenueBilled - $revenuePaid);
        $stats['payments_month'] = Payment::where('user_id', $userId)
            ->whereDate('paid_at', '>=', $startOfMonth)
            ->sum('amount');

        $tasksQuery = Task::query()->forAccount($accountId);
        $tasks = (clone $tasksQuery)
            ->with('assignee.user:id,name')
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get([
                'id',
                'title',
                'status',
                'due_date',
                'start_time',
                'end_time',
                'auto_started_at',
                'auto_completed_at',
                'assigned_team_member_id',
            ])
            ->map(function ($task) {
                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'status' => $task->status,
                    'due_date' => $task->due_date,
                    'start_time' => $task->start_time,
                    'end_time' => $task->end_time,
                    'auto_started_at' => $task->auto_started_at,
                    'auto_completed_at' => $task->auto_completed_at,
                    'assignee' => $task->assignee?->user ? [
                        'name' => $task->assignee->user->name,
                    ] : null,
                ];
            });

        $tasksToday = (clone $tasksQuery)
            ->with('assignee.user:id,name')
            ->whereDate('due_date', $today)
            ->whereIn('status', ['todo', 'in_progress'])
            ->orderByRaw('CASE WHEN start_time IS NULL THEN 1 ELSE 0 END')
            ->orderBy('start_time')
            ->orderByDesc('created_at')
            ->limit(12)
            ->get([
                'id',
                'title',
                'status',
                'due_date',
                'start_time',
                'end_time',
                'auto_started_at',
                'auto_completed_at',
                'assigned_team_member_id',
            ])
            ->map(function ($task) {
                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'status' => $task->status,
                    'due_date' => $task->due_date,
                    'start_time' => $task->start_time,
                    'end_time' => $task->end_time,
                    'auto_started_at' => $task->auto_started_at,
                    'auto_completed_at' => $task->auto_completed_at,
                    'assignee' => $task->assignee?->user ? [
                        'name' => $task->assignee->user->name,
                    ] : null,
                ];
            });

        $worksQuery = Work::query()->byUser($accountId);
        $worksToday = $this->buildWorksToday($worksQuery, $today);
        $agendaAlerts = $this->buildAgendaAlerts($tasksQuery, $worksQuery, $today);

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
                ->select(DB::raw('COALESCE(SUM(stock * COALESCE(NULLIF(cost_price, 0), price)), 0) as value'))
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

        $planDisplayOverrides = PlatformSetting::getValue('plan_display', []);
        $plans = collect(config('billing.plans', []))
            ->map(function (array $plan, string $key) use ($planDisplayOverrides) {
                $display = PlanDisplay::merge($plan, $key, $planDisplayOverrides);
                return [
                    'key' => $key,
                    'name' => $display['name'],
                    'price_id' => $plan['price_id'] ?? null,
                    'price' => $display['price'],
                    'display_price' => $this->resolvePlanDisplayPrice([
                        'price' => $display['price'],
                    ]),
                    'features' => $display['features'],
                    'badge' => $display['badge'],
                ];
            })
            ->values()
            ->all();

        $billingService = app(BillingSubscriptionService::class);
        $subscriptionSummary = $accountOwner
            ? $billingService->subscriptionSummary($accountOwner)
            : [
                'active' => false,
                'on_trial' => false,
                'status' => null,
                'price_id' => null,
                'ends_at' => null,
                'trial_ends_at' => null,
                'provider_id' => null,
            ];
        $usageLimits = $accountOwner
            ? app(UsageLimitService::class)->buildForUser($accountOwner)
            : ['items' => []];

        $props = [
            'stats' => $stats,
            'recentQuotes' => $recentQuotes,
            'upcomingJobs' => $upcomingJobs,
            'outstandingInvoices' => $outstandingInvoices,
            'activity' => $activity,
            'tasks' => $tasks,
            'tasksToday' => $tasksToday,
            'worksToday' => $worksToday,
            'agendaAlerts' => $agendaAlerts,
            'revenueSeries' => $revenueSeries,
            'kpiSeries' => $kpiSeries,
            'announcements' => $internalAnnouncements,
            'quickAnnouncements' => $quickAnnouncements,
            'usage_limits' => $usageLimits,
            'billing' => [
                'plans' => $plans,
                'subscription' => $subscriptionSummary,
            ],
        ];

        return $respond('Dashboard', $props, $cacheKey);
    }

    public function exportProductSellers(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            abort(403);
        }

        $accountId = $user->accountOwnerId() ?? $user->id;
        $isAccountOwner = $user->id === $accountId;
        $membership = null;
        if (!$isAccountOwner) {
            $membership = TeamMember::query()
                ->forAccount($accountId)
                ->active()
                ->where('user_id', $user->id)
                ->first();
        }

        if (!$isAccountOwner && (!$membership || !$membership->hasPermission('sales.manage'))) {
            abort(403);
        }

        $period = $request->query('period', 'month');
        $allowed = ['day', 'week', 'month', 'year'];
        if (!in_array($period, $allowed, true)) {
            $period = 'month';
        }

        $now = now();
        [$start, $end] = match ($period) {
            'day' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };

        $periodData = $this->buildProductPerformancePeriod($accountId, $start, $end, null, null);
        $rows = $periodData['top_sellers'] ?? [];
        $range = $periodData['range'] ?? ['start' => '', 'end' => ''];
        $onlineLabel = 'Online store';

        $filename = 'seller-performance-' . $period . '-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($rows, $range, $onlineLabel) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['rank', 'seller', 'type', 'orders', 'items', 'revenue', 'period_start', 'period_end']);
            $rank = 1;
            foreach ($rows as $row) {
                $type = $row['type'] ?? 'user';
                $name = $type === 'online' ? $onlineLabel : ($row['name'] ?? 'Seller');
                fputcsv($handle, [
                    $rank,
                    $name,
                    $type,
                    $row['orders'] ?? 0,
                    $row['items'] ?? 0,
                    $row['revenue'] ?? 0,
                    $range['start'] ?? '',
                    $range['end'] ?? '',
                ]);
                $rank += 1;
            }
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function tasksCalendar()
    {
        $user = Auth::user();
        if (!$user) {
            abort(403);
        }

        $accountId = $user->accountOwnerId() ?? $user->id;
        $isAccountOwner = $user->id === $accountId;
        $membership = null;
        if (!$isAccountOwner) {
            $membership = TeamMember::query()
                ->forAccount($accountId)
                ->active()
                ->where('user_id', $user->id)
                ->first();
        }

        $tasksQuery = Task::query()->forAccount($accountId);
        if ($membership && $membership->role !== 'admin') {
            $tasksQuery->where('assigned_team_member_id', $membership->id);
        }

        $worksQuery = Work::query()->byUser($accountId);
        if ($membership && $membership->role !== 'admin') {
            $worksQuery->whereHas('teamMembers', fn($query) => $query->whereKey($membership->id));
        }

        $today = now()->toDateString();
        $tasks = (clone $tasksQuery)
            ->whereDate('due_date', $today)
            ->whereIn('status', ['todo', 'in_progress'])
            ->orderByRaw('CASE WHEN start_time IS NULL THEN 1 ELSE 0 END')
            ->orderBy('start_time')
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'description', 'due_date', 'start_time', 'end_time']);

        $excludedStatuses = array_merge(Work::COMPLETED_STATUSES, [Work::STATUS_CANCELLED]);
        $works = (clone $worksQuery)
            ->whereDate('start_date', $today)
            ->whereNotIn('status', $excludedStatuses)
            ->orderByRaw('CASE WHEN start_time IS NULL THEN 1 ELSE 0 END')
            ->orderBy('start_time')
            ->orderByDesc('created_at')
            ->get(['id', 'job_title', 'instructions', 'start_date', 'start_time', 'end_time']);

        $calendar = $this->buildAgendaCalendar($tasks, $works);
        $filename = 'tasks-' . $today . '.ics';

        return response($calendar, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function buildAgendaCalendar($tasks, $works): string
    {
        $timezone = config('app.timezone', 'UTC');
        $nowUtc = now('UTC')->format('Ymd\THis\Z');
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//MLK Pro//Dashboard Tasks//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];

        foreach ($tasks as $task) {
            if (!$task->due_date) {
                continue;
            }

            $summary = $this->escapeCalendarText($task->title ?: 'Task');
            $description = $task->description ? $this->escapeCalendarText($task->description) : '';
            $window = $this->buildTaskCalendarWindow($task, $timezone);
            if (!$window) {
                continue;
            }

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:task-' . $task->id . '@mlkpro';
            $lines[] = 'DTSTAMP:' . $nowUtc;
            $lines[] = 'SUMMARY:' . $summary;
            if ($description !== '') {
                $lines[] = 'DESCRIPTION:' . $description;
            }

            if ($window['all_day']) {
                $lines[] = 'DTSTART;VALUE=DATE:' . $window['start'];
                $lines[] = 'DTEND;VALUE=DATE:' . $window['end'];
            } else {
                $lines[] = 'DTSTART:' . $window['start'];
                $lines[] = 'DTEND:' . $window['end'];
            }

            $lines[] = 'BEGIN:VALARM';
            $lines[] = 'TRIGGER:-PT15M';
            $lines[] = 'ACTION:DISPLAY';
            $lines[] = 'DESCRIPTION:Task reminder';
            $lines[] = 'END:VALARM';
            $lines[] = 'END:VEVENT';
        }

        foreach ($works as $work) {
            if (!$work->start_date) {
                continue;
            }

            $summary = $this->escapeCalendarText($work->job_title ?: 'Work');
            $description = $work->instructions ? $this->escapeCalendarText($work->instructions) : '';
            $window = $this->buildWorkCalendarWindow($work, $timezone);
            if (!$window) {
                continue;
            }

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:work-' . $work->id . '@mlkpro';
            $lines[] = 'DTSTAMP:' . $nowUtc;
            $lines[] = 'SUMMARY:' . $summary;
            if ($description !== '') {
                $lines[] = 'DESCRIPTION:' . $description;
            }

            if ($window['all_day']) {
                $lines[] = 'DTSTART;VALUE=DATE:' . $window['start'];
                $lines[] = 'DTEND;VALUE=DATE:' . $window['end'];
            } else {
                $lines[] = 'DTSTART:' . $window['start'];
                $lines[] = 'DTEND:' . $window['end'];
            }

            $lines[] = 'BEGIN:VALARM';
            $lines[] = 'TRIGGER:-PT15M';
            $lines[] = 'ACTION:DISPLAY';
            $lines[] = 'DESCRIPTION:Work reminder';
            $lines[] = 'END:VALARM';
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    private function buildTaskCalendarWindow(Task $task, string $timezone): ?array
    {
        $dueDate = $task->due_date ? Carbon::parse($task->due_date, $timezone) : null;
        if (!$dueDate) {
            return null;
        }

        $startTime = $task->start_time;
        $endTime = $task->end_time;
        if (!$startTime && !$endTime) {
            return [
                'all_day' => true,
                'start' => $dueDate->format('Ymd'),
                'end' => $dueDate->copy()->addDay()->format('Ymd'),
            ];
        }

        $startAt = $startTime
            ? Carbon::parse($dueDate->format('Y-m-d') . ' ' . $startTime, $timezone)
            : Carbon::parse($dueDate->format('Y-m-d') . ' ' . $endTime, $timezone)->subHour();
        $endAt = $endTime
            ? Carbon::parse($dueDate->format('Y-m-d') . ' ' . $endTime, $timezone)
            : $startAt->copy()->addHour();

        if ($endAt->lessThanOrEqualTo($startAt)) {
            $endAt = $startAt->copy()->addHour();
        }

        return [
            'all_day' => false,
            'start' => $startAt->copy()->utc()->format('Ymd\THis\Z'),
            'end' => $endAt->copy()->utc()->format('Ymd\THis\Z'),
        ];
    }

    private function buildWorkCalendarWindow(Work $work, string $timezone): ?array
    {
        $startDate = $work->start_date ? Carbon::parse($work->start_date, $timezone) : null;
        if (!$startDate) {
            return null;
        }

        $startTime = $work->start_time;
        $endTime = $work->end_time;
        if (!$startTime && !$endTime) {
            return [
                'all_day' => true,
                'start' => $startDate->format('Ymd'),
                'end' => $startDate->copy()->addDay()->format('Ymd'),
            ];
        }

        $startAt = $startTime
            ? Carbon::parse($startDate->format('Y-m-d') . ' ' . $startTime, $timezone)
            : Carbon::parse($startDate->format('Y-m-d') . ' ' . $endTime, $timezone)->subHour();
        $endAt = $endTime
            ? Carbon::parse($startDate->format('Y-m-d') . ' ' . $endTime, $timezone)
            : $startAt->copy()->addHour();

        if ($endAt->lessThanOrEqualTo($startAt)) {
            $endAt = $startAt->copy()->addHour();
        }

        return [
            'all_day' => false,
            'start' => $startAt->copy()->utc()->format('Ymd\THis\Z'),
            'end' => $endAt->copy()->utc()->format('Ymd\THis\Z'),
        ];
    }

    private function escapeCalendarText(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace("\r", '', $value);
        $value = str_replace("\n", '\\n', $value);
        $value = str_replace(';', '\;', $value);
        $value = str_replace(',', '\,', $value);

        return $value;
    }

    private function buildProductPerformance(int $accountId, Carbon $now): array
    {
        $periods = [
            'day' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
        ];

        $periodData = [];

        foreach ($periods as $key => [$start, $end]) {
            $periodData[$key] = $this->buildProductPerformancePeriod($accountId, $start, $end);
        }

        $sellerOfYear = collect($periodData['year']['top_sellers'] ?? [])
            ->first(fn($seller) => ($seller['type'] ?? null) === 'user')
            ?? ($periodData['year']['top_sellers'][0] ?? null);

        return [
            'periods' => $periodData,
            'seller_of_year' => $sellerOfYear,
        ];
    }

    private function buildProductPerformancePeriod(
        int $accountId,
        Carbon $start,
        Carbon $end,
        ?int $sellerLimit = 8,
        ?int $productLimit = 6
    ): array {
        $salesQuery = Sale::query()
            ->where('user_id', $accountId)
            ->where('status', Sale::STATUS_PAID)
            ->whereBetween('created_at', [$start, $end]);

        $orders = (clone $salesQuery)->count();
        $revenue = (float) (clone $salesQuery)->sum('total');
        $avgOrder = $orders > 0 ? round($revenue / $orders, 2) : 0.0;
        $uniqueCustomers = (clone $salesQuery)
            ->whereNotNull('customer_id')
            ->distinct('customer_id')
            ->count('customer_id');

        $activeSellerIds = Sale::query()
            ->where('user_id', $accountId)
            ->where('status', Sale::STATUS_PAID)
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('created_by_user_id')
            ->distinct('created_by_user_id')
            ->pluck('created_by_user_id');
        $activeSellers = $activeSellerIds->count();
        $revenuePerSeller = $activeSellers > 0 ? round($revenue / $activeSellers, 2) : 0.0;

        $itemsSold = (int) SaleItem::query()
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.user_id', $accountId)
            ->where('sales.status', Sale::STATUS_PAID)
            ->whereBetween('sales.created_at', [$start, $end])
            ->sum('sale_items.quantity');

        $sellerRowsQuery = Sale::query()
            ->select(DB::raw('COALESCE(created_by_user_id, 0) as seller_id'), DB::raw('COUNT(*) as orders'), DB::raw('SUM(total) as revenue'))
            ->where('user_id', $accountId)
            ->where('status', Sale::STATUS_PAID)
            ->whereBetween('created_at', [$start, $end])
            ->groupBy(DB::raw('COALESCE(created_by_user_id, 0)'))
            ->orderByDesc('revenue');

        if ($sellerLimit) {
            $sellerRowsQuery->limit($sellerLimit);
        }

        $sellerRows = $sellerRowsQuery->get();

        $sellerIds = $sellerRows->pluck('seller_id')
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();
        $sellerMap = $sellerIds->isNotEmpty()
            ? User::query()
                ->whereIn('id', $sellerIds)
                ->get(['id', 'name', 'profile_picture'])
                ->keyBy('id')
            : collect();

        $itemsBySeller = SaleItem::query()
            ->select(DB::raw('COALESCE(sales.created_by_user_id, 0) as seller_id'), DB::raw('SUM(sale_items.quantity) as items'))
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.user_id', $accountId)
            ->where('sales.status', Sale::STATUS_PAID)
            ->whereBetween('sales.created_at', [$start, $end])
            ->groupBy(DB::raw('COALESCE(sales.created_by_user_id, 0)'))
            ->pluck('items', 'seller_id')
            ->toArray();

        $topSellers = $sellerRows->map(function ($row) use ($sellerMap, $itemsBySeller) {
            $sellerId = (int) $row->seller_id;
            $isOnline = $sellerId === 0;
            $seller = $isOnline ? null : $sellerMap->get($sellerId);
            $items = (int) ($itemsBySeller[$sellerId] ?? 0);

            return [
                'id' => $sellerId,
                'type' => $isOnline ? 'online' : 'user',
                'name' => $seller?->name ?? 'Seller',
                'profile_picture_url' => $seller?->profile_picture_url,
                'orders' => (int) $row->orders,
                'revenue' => (float) $row->revenue,
                'items' => $items,
            ];
        })->values();

        $topProductRowsQuery = SaleItem::query()
            ->select('sale_items.product_id', DB::raw('SUM(sale_items.quantity) as quantity'), DB::raw('SUM(sale_items.total) as revenue'))
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.user_id', $accountId)
            ->where('sales.status', Sale::STATUS_PAID)
            ->whereBetween('sales.created_at', [$start, $end])
            ->groupBy('sale_items.product_id')
            ->orderByDesc('revenue');

        if ($productLimit) {
            $topProductRowsQuery->limit($productLimit);
        }

        $topProductRows = $topProductRowsQuery->get();

        $productMap = $topProductRows->isNotEmpty()
            ? Product::query()
                ->whereIn('id', $topProductRows->pluck('product_id'))
                ->get(['id', 'name', 'image'])
                ->keyBy('id')
            : collect();

        $topProducts = $topProductRows->map(function ($row) use ($productMap) {
            $product = $productMap->get($row->product_id);

            return [
                'id' => (int) $row->product_id,
                'name' => $product?->name ?? 'Product',
                'image_url' => $product?->image_url,
                'quantity' => (int) $row->quantity,
                'revenue' => (float) $row->revenue,
            ];
        })->values();

        return [
            'range' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'orders' => $orders,
            'revenue' => $revenue,
            'avg_order' => $avgOrder,
            'revenue_per_seller' => $revenuePerSeller,
            'items_sold' => $itemsSold,
            'customers' => (int) $uniqueCustomers,
            'active_sellers' => $activeSellers,
            'top_sellers' => $topSellers,
            'top_products' => $topProducts,
        ];
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

    private function buildAgendaAlerts($tasksQuery, $worksQuery, string $today): array
    {
        $tasksStarted = (clone $tasksQuery)
            ->whereNotNull('auto_started_at')
            ->whereDate('auto_started_at', $today)
            ->count();
        $tasksCompleted = (clone $tasksQuery)
            ->whereNotNull('auto_completed_at')
            ->whereDate('auto_completed_at', $today)
            ->count();
        $worksStarted = (clone $worksQuery)
            ->whereNotNull('auto_started_at')
            ->whereDate('auto_started_at', $today)
            ->count();
        $worksCompleted = (clone $worksQuery)
            ->whereNotNull('auto_completed_at')
            ->whereDate('auto_completed_at', $today)
            ->count();

        return [
            'tasks_started' => $tasksStarted,
            'tasks_completed' => $tasksCompleted,
            'works_started' => $worksStarted,
            'works_completed' => $worksCompleted,
        ];
    }

    private function buildWorksToday($query, string $today): array
    {
        $excludedStatuses = array_merge(Work::COMPLETED_STATUSES, [Work::STATUS_CANCELLED]);

        return (clone $query)
            ->whereDate('start_date', $today)
            ->whereNotIn('status', $excludedStatuses)
            ->orderByRaw('CASE WHEN start_time IS NULL THEN 1 ELSE 0 END')
            ->orderBy('start_time')
            ->orderByDesc('created_at')
            ->limit(12)
            ->get([
                'id',
                'job_title',
                'status',
                'start_date',
                'start_time',
                'end_time',
                'auto_started_at',
                'auto_completed_at',
            ])
            ->map(function ($work) {
                return [
                    'id' => $work->id,
                    'title' => $work->job_title,
                    'status' => $work->status,
                    'due_date' => $work->start_date,
                    'start_time' => $work->start_time,
                    'end_time' => $work->end_time,
                    'auto_started_at' => $work->auto_started_at,
                    'auto_completed_at' => $work->auto_completed_at,
                ];
            })
            ->values()
            ->all();
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
