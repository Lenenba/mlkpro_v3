<?php

namespace App\Http\Controllers;

use App\Models\Work;
use App\Models\Quote;
use App\Models\Task;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Request as LeadRequest;
use App\Models\User;
use App\Notifications\InviteUserNotification;
use App\Utils\FileHandler;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use App\Http\Requests\CustomerRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class CustomerController extends Controller
{
    use AuthorizesRequests;
    /**
     * Display a listing of the customers.
     *
     * @return \Inertia\Response
     */
    public function index(?Request $request)
    {
        $filters = $request->only([
            'name',
            'city',
            'country',
            'has_quotes',
            'has_works',
            'status',
            'created_from',
            'created_to',
            'sort',
            'direction',
        ]);
        $user = $request->user();
        if (!$user) {
            abort(403);
        }
        [, $accountId] = $this->resolveCustomerAccount($user);
        $canEdit = $user->id === $accountId;

        $baseQuery = Customer::query()
            ->filter($filters)
            ->byUser($accountId);

        $sort = in_array($filters['sort'] ?? null, ['company_name', 'first_name', 'created_at', 'quotes_count', 'works_count'], true)
            ? $filters['sort']
            : 'created_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        // Fetch customers with pagination
        $customers = (clone $baseQuery)
            ->with(['properties'])
            ->withCount([
                'quotes as quotes_count' => fn($query) => $query->where('user_id', $accountId),
                'works as works_count' => fn($query) => $query->where('user_id', $accountId),
                'invoices as invoices_count' => fn($query) => $query->where('user_id', $accountId),
            ])
            ->orderBy($sort, $direction)
            ->simplePaginate(12)
            ->withQueryString();

        $totalCount = (clone $baseQuery)->count();
        $recentThreshold = now()->subDays(30);
        $newCount = (clone $baseQuery)
            ->whereDate('created_at', '>=', $recentThreshold)
            ->count();
        $withQuotes = (clone $baseQuery)
            ->whereHas('quotes', fn($query) => $query->where('user_id', $accountId))
            ->count();
        $withWorks = (clone $baseQuery)
            ->whereHas('works', fn($query) => $query->where('user_id', $accountId))
            ->count();
        $activeCount = (clone $baseQuery)
            ->where(function ($query) use ($accountId, $recentThreshold) {
                $query->whereHas('quotes', function ($sub) use ($accountId, $recentThreshold) {
                    $sub->where('user_id', $accountId)
                        ->where('created_at', '>=', $recentThreshold);
                })->orWhereHas('works', function ($sub) use ($accountId, $recentThreshold) {
                    $sub->where('user_id', $accountId)
                        ->where('created_at', '>=', $recentThreshold);
                });
            })
            ->count();

        $stats = [
            'total' => $totalCount,
            'new' => $newCount,
            'with_quotes' => $withQuotes,
            'with_works' => $withWorks,
            'active' => $activeCount,
        ];

        $topCustomers = (clone $baseQuery)
            ->withCount([
                'quotes as quotes_count' => fn($query) => $query->where('user_id', $accountId),
                'works as works_count' => fn($query) => $query->where('user_id', $accountId),
                'invoices as invoices_count' => fn($query) => $query->where('user_id', $accountId),
            ])
            ->orderByDesc('quotes_count')
            ->orderByDesc('works_count')
            ->limit(5)
            ->get(['id', 'company_name', 'first_name', 'last_name', 'logo', 'header_image']);

        // Pass data to Inertia view
        return $this->inertiaOrJson('Customer/Index', [
            'customers' => $customers,
            'filters' => $filters,
            'count' => $totalCount,
            'stats' => $stats,
            'topCustomers' => $topCustomers,
            'canEdit' => $canEdit,
        ]);
    }

    /**
     * Return customers and properties for quick-create dialogs.
     */
    public function options(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(403);
        }
        [, $accountId] = $this->resolveCustomerAccount($user);

        $customers = Customer::byUser($accountId)
            ->with(['properties' => function ($query) {
                $query->orderByDesc('is_default')->orderBy('id');
            }])
            ->orderBy('company_name')
            ->orderBy('last_name')
            ->get(['id', 'company_name', 'first_name', 'last_name', 'email', 'phone']);

        $payload = $customers->map(function ($customer) {
            return [
                'id' => $customer->id,
                'company_name' => $customer->company_name,
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'properties' => $customer->properties->map(function ($property) {
                    return [
                        'id' => $property->id,
                        'type' => $property->type,
                        'is_default' => (bool) $property->is_default,
                        'street1' => $property->street1,
                        'street2' => $property->street2,
                        'city' => $property->city,
                        'state' => $property->state,
                        'zip' => $property->zip,
                        'country' => $property->country,
                    ];
                })->values(),
            ];
        })->values();

        return response()->json([
            'customers' => $payload,
        ]);
    }

    /**
     * Show the form for creating a new customer.
     *
     * @return \Inertia\Response
     */
    public function create()
    {
        $user = Auth::user();
        if ($user) {
            $this->resolveCustomerAccount($user);
        }

        return $this->inertiaOrJson('Customer/Create', [
            'customer' => new Customer(),
        ]);
    }

    /**
     * Show the form for editing the specified customer.
     *
     * @param  \App\Models\Customer  $customer
     * @return \Inertia\Response
     */
    public function edit(Customer $customer, ?Request $request)
    {
        $this->authorize('update', $customer);

        $user = $request?->user();
        if ($user) {
            $this->resolveCustomerAccount($user);
        }

        $customer->load('properties');

        return $this->inertiaOrJson('Customer/Create', [
            'customer' => $customer,
        ]);
    }

    /**
     * Display the specified customer.
     *
     * @param  \App\Models\Customer  $customer
     * @return \Inertia\Response
     */
    public function show(Customer $customer, ?Request $request)
    {
        $this->authorize('view', $customer);

        $user = $request?->user();
        $accountOwner = null;
        $accountId = $user?->id ?? Auth::id();
        if ($user) {
            [$accountOwner, $accountId] = $this->resolveCustomerAccount($user);
        }
        $canEdit = $user ? $user->can('update', $customer) : false;
        $isProductAccount = $accountOwner && $accountOwner->company_type === 'products';

        // Valider les filtres uniquement si la requête contient des données
        $filters = $request?->only([
            'name',
            'status',
            'month',
        ]) ?? [];

        $works = collect();
        if (!$isProductAccount) {
            // Fetch works for the retrieved customers
            $works = Work::with(['products', 'ratings', 'customer'])
                ->byCustomer($customer->id)
                ->byUser($accountId)
                ->filter($filters)
                ->latest()
                ->paginate(10)
                ->withQueryString();
        }

        $customer->load(['properties']);
        if (!$isProductAccount) {
            $customer->load([
                'quotes' => fn($query) => $query->latest()->limit(10),
                'works' => fn($query) => $query->with('invoice')->latest()->limit(10),
                'requests' => fn($query) => $query->latest()->limit(10)->with('quote:id,number,status,customer_id'),
                'invoices' => fn($query) => $query->withSum('payments', 'amount')->latest()->limit(10),
            ]);
        }

        $sales = collect();
        $salesSummary = null;
        $salesInsights = null;
        $topProducts = collect();
        if ($accountOwner && $accountOwner->company_type === 'products') {
            $salesQuery = Sale::query()
                ->where('user_id', $accountId)
                ->where('customer_id', $customer->id);

            $sales = (clone $salesQuery)
                ->latest()
                ->limit(10)
                ->get(['id', 'number', 'status', 'total', 'created_at']);

            $salesCount = (clone $salesQuery)->count();
            $salesTotal = (float) (clone $salesQuery)->sum('total');
            $salesPaid = (float) (clone $salesQuery)->where('status', Sale::STATUS_PAID)->sum('total');

            $salesSummary = [
                'count' => $salesCount,
                'total' => $salesTotal,
                'paid' => $salesPaid,
            ];

            $lastPurchaseAt = (clone $salesQuery)->latest()->value('created_at');
            $daysSinceLast = $lastPurchaseAt ? now()->diffInDays($lastPurchaseAt) : null;
            $recent30Count = (clone $salesQuery)
                ->where('created_at', '>=', now()->subDays(30))
                ->count();

            $saleDates = (clone $salesQuery)
                ->orderBy('created_at')
                ->get(['created_at'])
                ->pluck('created_at')
                ->filter();

            $purchaseFrequency = null;
            $preferredDay = null;
            $preferredPeriod = null;

            if ($saleDates->count() > 1) {
                $intervals = [];
                for ($i = 1; $i < $saleDates->count(); $i++) {
                    $current = $saleDates[$i];
                    $previous = $saleDates[$i - 1];
                    if ($current && $previous) {
                        $intervals[] = $current->diffInDays($previous);
                    }
                }
                if ($intervals) {
                    $purchaseFrequency = round(array_sum($intervals) / count($intervals), 1);
                }
            }

            if ($saleDates->isNotEmpty()) {
                $dayLabels = [
                    'Mon' => 'Lun',
                    'Tue' => 'Mar',
                    'Wed' => 'Mer',
                    'Thu' => 'Jeu',
                    'Fri' => 'Ven',
                    'Sat' => 'Sam',
                    'Sun' => 'Dim',
                ];
                $dayCounts = [];
                $periodCounts = [];
                foreach ($saleDates as $date) {
                    if (!$date) {
                        continue;
                    }
                    $dayKey = $date->format('D');
                    $dayCounts[$dayKey] = ($dayCounts[$dayKey] ?? 0) + 1;

                    $hour = (int) $date->format('H');
                    if ($hour >= 5 && $hour < 12) {
                        $periodKey = 'morning';
                    } elseif ($hour >= 12 && $hour < 17) {
                        $periodKey = 'afternoon';
                    } elseif ($hour >= 17 && $hour < 21) {
                        $periodKey = 'evening';
                    } else {
                        $periodKey = 'night';
                    }
                    $periodCounts[$periodKey] = ($periodCounts[$periodKey] ?? 0) + 1;
                }

                if ($dayCounts) {
                    arsort($dayCounts);
                    $preferredDayKey = array_key_first($dayCounts);
                    $preferredDay = $dayLabels[$preferredDayKey] ?? $preferredDayKey;
                }

                if ($periodCounts) {
                    $periodLabels = [
                        'morning' => 'Matin',
                        'afternoon' => 'Apres-midi',
                        'evening' => 'Soiree',
                        'night' => 'Nuit',
                    ];
                    arsort($periodCounts);
                    $preferredPeriodKey = array_key_first($periodCounts);
                    $preferredPeriod = $periodLabels[$preferredPeriodKey] ?? $preferredPeriodKey;
                }
            }

            $itemsQuery = SaleItem::query()
                ->whereHas('sale', function ($query) use ($accountId, $customer) {
                    $query->where('user_id', $accountId)
                        ->where('customer_id', $customer->id);
                });

            $distinctProducts = (clone $itemsQuery)->distinct('product_id')->count('product_id');
            $totalUnits = (int) (clone $itemsQuery)->sum('quantity');
            $averageItems = $salesCount > 0 ? round($totalUnits / $salesCount, 1) : 0;

            $topProducts = (clone $itemsQuery)
                ->select('product_id', DB::raw('SUM(quantity) as quantity'), DB::raw('SUM(total) as total'))
                ->whereNotNull('product_id')
                ->groupBy('product_id')
                ->orderByDesc('quantity')
                ->limit(5)
                ->with('product:id,name,sku,image')
                ->get()
                ->map(fn($row) => [
                    'id' => $row->product_id,
                    'name' => $row->product?->name,
                    'sku' => $row->product?->sku,
                    'image' => $row->product?->image_url ?? $row->product?->image,
                    'quantity' => (int) $row->quantity,
                    'total' => (float) $row->total,
                ])
                ->values();

            $salesInsights = [
                'average_order_value' => $salesCount > 0 ? round($salesTotal / $salesCount, 2) : 0,
                'average_items' => $averageItems,
                'last_purchase_at' => $lastPurchaseAt,
                'days_since_last_purchase' => $daysSinceLast,
                'purchase_frequency_days' => $purchaseFrequency,
                'recent_30_count' => $recent30Count,
                'preferred_day' => $preferredDay,
                'preferred_period' => $preferredPeriod,
                'distinct_products' => $distinctProducts,
            ];
        }

        $stats = [];
        $tasks = collect();
        $upcomingJobs = collect();
        $recentPayments = collect();
        $billing = [
            'total_invoiced' => 0,
            'total_paid' => 0,
            'balance_due' => 0,
        ];
        $activity = collect();

        if (!$isProductAccount) {
            $stats = [
                'active_works' => Work::query()
                    ->where('customer_id', $customer->id)
                    ->where('user_id', $accountId)
                    ->whereDate('end_date', '>=', now()->toDateString())
                    ->count(),
                'requests' => LeadRequest::query()
                    ->where('customer_id', $customer->id)
                    ->where('user_id', $accountId)
                    ->count(),
                'quotes' => Quote::query()
                    ->where('customer_id', $customer->id)
                    ->where('user_id', $accountId)
                    ->whereNull('archived_at')
                    ->count(),
                'jobs' => Work::query()
                    ->where('customer_id', $customer->id)
                    ->where('user_id', $accountId)
                    ->count(),
                'invoices' => Invoice::query()
                    ->where('customer_id', $customer->id)
                    ->where('user_id', $accountId)
                    ->count(),
            ];

            $tasks = Task::query()
                ->forAccount($accountId)
                ->where('customer_id', $customer->id)
                ->with(['assignee.user:id,name'])
                ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('due_date')
                ->orderByDesc('created_at')
                ->limit(8)
                ->get([
                    'id',
                    'title',
                    'status',
                    'due_date',
                    'completed_at',
                    'assigned_team_member_id',
                ])
                ->map(fn($task) => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'status' => $task->status,
                    'due_date' => $task->due_date,
                    'completed_at' => $task->completed_at,
                    'assignee' => $task->assignee?->user?->name,
                ])
                ->values();

            $upcomingJobs = Work::query()
                ->where('customer_id', $customer->id)
                ->where('user_id', $accountId)
                ->whereDate('start_date', '>=', now()->toDateString())
                ->orderBy('start_date')
                ->limit(8)
                ->get([
                    'id',
                    'job_title',
                    'status',
                    'start_date',
                    'end_date',
                    'created_at',
                ])
                ->map(fn($work) => [
                    'id' => $work->id,
                    'job_title' => $work->job_title,
                    'status' => $work->status,
                    'start_date' => $work->start_date,
                    'end_date' => $work->end_date,
                    'created_at' => $work->created_at,
                ])
                ->values();

            $recentPayments = Payment::query()
                ->where('customer_id', $customer->id)
                ->where('user_id', $accountId)
                ->with('invoice:id,number')
                ->orderByRaw('CASE WHEN paid_at IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('paid_at')
                ->orderByDesc('created_at')
                ->limit(8)
                ->get([
                    'id',
                    'invoice_id',
                    'amount',
                    'method',
                    'status',
                    'reference',
                    'paid_at',
                    'created_at',
                ])
                ->map(fn($payment) => [
                    'id' => $payment->id,
                    'amount' => (float) $payment->amount,
                    'method' => $payment->method,
                    'status' => $payment->status,
                    'reference' => $payment->reference,
                    'paid_at' => $payment->paid_at,
                    'created_at' => $payment->created_at,
                    'invoice' => $payment->invoice ? [
                        'id' => $payment->invoice_id,
                        'number' => $payment->invoice->number,
                    ] : null,
                ])
                ->values();

            $totalInvoiced = (float) Invoice::query()
                ->where('customer_id', $customer->id)
                ->where('user_id', $accountId)
                ->whereNotIn('status', ['void'])
                ->sum('total');
            $totalPaid = (float) Payment::query()
                ->where('customer_id', $customer->id)
                ->where('user_id', $accountId)
                ->sum('amount');

            $billing = [
                'total_invoiced' => $totalInvoiced,
                'total_paid' => $totalPaid,
                'balance_due' => max(0, round($totalInvoiced - $totalPaid, 2)),
            ];

            $subjectLabels = [
                Quote::class => 'Quote',
                Work::class => 'Job',
                Invoice::class => 'Invoice',
                Payment::class => 'Payment',
                Customer::class => 'Customer',
            ];

            $quoteIds = Quote::query()
                ->where('customer_id', $customer->id)
                ->where('user_id', $accountId)
                ->latest()
                ->limit(250)
                ->pluck('id');
            $workIds = Work::query()
                ->where('customer_id', $customer->id)
                ->where('user_id', $accountId)
                ->latest()
                ->limit(250)
                ->pluck('id');
            $invoiceIds = Invoice::query()
                ->where('customer_id', $customer->id)
                ->where('user_id', $accountId)
                ->latest()
                ->limit(250)
                ->pluck('id');
            $paymentIds = Payment::query()
                ->where('customer_id', $customer->id)
                ->where('user_id', $accountId)
                ->latest()
                ->limit(250)
                ->pluck('id');

            $activity = ActivityLog::query()
                ->where(function ($query) use ($customer, $quoteIds, $workIds, $invoiceIds, $paymentIds) {
                    $query->where(function ($sub) use ($customer) {
                        $sub->where('subject_type', Customer::class)
                            ->where('subject_id', $customer->id);
                    });

                    if ($quoteIds->isNotEmpty()) {
                        $query->orWhere(function ($sub) use ($quoteIds) {
                            $sub->where('subject_type', Quote::class)
                                ->whereIn('subject_id', $quoteIds);
                        });
                    }

                    if ($workIds->isNotEmpty()) {
                        $query->orWhere(function ($sub) use ($workIds) {
                            $sub->where('subject_type', Work::class)
                                ->whereIn('subject_id', $workIds);
                        });
                    }

                    if ($invoiceIds->isNotEmpty()) {
                        $query->orWhere(function ($sub) use ($invoiceIds) {
                            $sub->where('subject_type', Invoice::class)
                                ->whereIn('subject_id', $invoiceIds);
                        });
                    }

                    if ($paymentIds->isNotEmpty()) {
                        $query->orWhere(function ($sub) use ($paymentIds) {
                            $sub->where('subject_type', Payment::class)
                                ->whereIn('subject_id', $paymentIds);
                        });
                    }
                })
                ->latest()
                ->limit(12)
                ->get(['id', 'action', 'description', 'subject_type', 'subject_id', 'created_at'])
                ->map(function ($log) use ($subjectLabels) {
                    return [
                        'id' => $log->id,
                        'action' => $log->action,
                        'description' => $log->description,
                        'subject_type' => $log->subject_type,
                        'subject_id' => $log->subject_id,
                        'subject' => $subjectLabels[$log->subject_type] ?? 'Item',
                        'created_at' => $log->created_at,
                    ];
                })
                ->values();
        } else {
            $activity = ActivityLog::query()
                ->where('subject_type', Customer::class)
                ->where('subject_id', $customer->id)
                ->latest()
                ->limit(12)
                ->get(['id', 'action', 'description', 'subject_type', 'subject_id', 'created_at'])
                ->map(function ($log) {
                    return [
                        'id' => $log->id,
                        'action' => $log->action,
                        'description' => $log->description,
                        'subject_type' => $log->subject_type,
                        'subject_id' => $log->subject_id,
                        'subject' => 'Customer',
                        'created_at' => $log->created_at,
                    ];
                })
                ->values();
        }

        return $this->inertiaOrJson('Customer/Show', [
            'customer' => $customer,
            'canEdit' => $canEdit,
            'works' => $works,
            'filters' => $filters,
            'stats' => $stats,
            'sales' => $sales,
            'salesSummary' => $salesSummary,
            'salesInsights' => $salesInsights,
            'topProducts' => $topProducts,
            'schedule' => [
                'tasks' => $tasks,
                'upcomingJobs' => $upcomingJobs,
            ],
            'billing' => [
                'summary' => $billing,
                'recentPayments' => $recentPayments,
            ],
            'activity' => $activity,
            'lastInteraction' => $activity->first(),
        ]);
    }

    public function updateNotes(Request $request, Customer $customer)
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'description' => 'nullable|string|max:255',
        ]);

        $customer->update([
            'description' => $validated['description'] ?? null,
        ]);

        ActivityLog::record($request->user(), $customer, 'notes_updated', [
            'description' => $customer->description,
        ], 'Customer notes updated');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Notes updated.',
                'customer' => [
                    'id' => $customer->id,
                    'description' => $customer->description,
                ],
            ]);
        }

        return redirect()->back()->with('success', 'Notes updated.');
    }

    public function updateTags(Request $request, Customer $customer)
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'tags' => 'nullable|string|max:500',
        ]);

        $raw = $validated['tags'] ?? '';
        $tags = array_filter(array_map('trim', explode(',', $raw)));
        $tags = array_values(array_unique($tags));
        $tags = array_map(fn($tag) => mb_substr($tag, 0, 30), $tags);
        $tags = array_slice($tags, 0, 20);

        $customer->update([
            'tags' => $tags ?: null,
        ]);

        ActivityLog::record($request->user(), $customer, 'tags_updated', [
            'tags' => $tags,
        ], 'Customer tags updated');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Tags updated.',
                'customer' => [
                    'id' => $customer->id,
                    'tags' => $customer->tags,
                ],
            ]);
        }

        return redirect()->back()->with('success', 'Tags updated.');
    }

    public function updateAutoValidation(Request $request, Customer $customer)
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'auto_accept_quotes' => 'nullable|boolean',
            'auto_validate_jobs' => 'nullable|boolean',
            'auto_validate_tasks' => 'nullable|boolean',
            'auto_validate_invoices' => 'nullable|boolean',
        ]);

        $customer->update([
            'auto_accept_quotes' => (bool) ($validated['auto_accept_quotes'] ?? $customer->auto_accept_quotes ?? false),
            'auto_validate_jobs' => (bool) ($validated['auto_validate_jobs'] ?? $customer->auto_validate_jobs ?? false),
            'auto_validate_tasks' => (bool) ($validated['auto_validate_tasks'] ?? $customer->auto_validate_tasks ?? false),
            'auto_validate_invoices' => (bool) ($validated['auto_validate_invoices'] ?? $customer->auto_validate_invoices ?? false),
        ]);

        ActivityLog::record($request->user(), $customer, 'auto_validation_updated', [
            'auto_accept_quotes' => $customer->auto_accept_quotes,
            'auto_validate_jobs' => $customer->auto_validate_jobs,
            'auto_validate_tasks' => $customer->auto_validate_tasks,
            'auto_validate_invoices' => $customer->auto_validate_invoices,
        ], 'Customer auto validation updated');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Auto validation preferences updated.',
                'customer' => [
                    'id' => $customer->id,
                    'auto_accept_quotes' => $customer->auto_accept_quotes,
                    'auto_validate_jobs' => $customer->auto_validate_jobs,
                    'auto_validate_tasks' => $customer->auto_validate_tasks,
                    'auto_validate_invoices' => $customer->auto_validate_invoices,
                ],
            ]);
        }

        return redirect()->back()->with('success', 'Auto validation preferences updated.');
    }

    /**
     * Store a newly created customer in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(CustomerRequest $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(403);
        }
        [$accountOwner, $accountId] = $this->resolveCustomerAccount($user);

        $validated = $request->validated();
        $defaultLogo = config('icon_presets.defaults.company', Customer::DEFAULT_LOGO_PATH);
        $logoPath = FileHandler::handleImageUpload('customers', $request, 'logo', $defaultLogo);
        if (!empty($validated['logo_icon']) && !$request->hasFile('logo')) {
            $logoPath = $validated['logo_icon'];
        }
        $validated['logo'] = $logoPath;
        unset($validated['logo_icon']);
        $validated['header_image'] = FileHandler::handleImageUpload('customers', $request, 'header_image', 'customers/customer.png');
        $portalAccess = array_key_exists('portal_access', $validated)
            ? (bool) $validated['portal_access']
            : true;

        $customerData = Arr::except($validated, ['temporary_password']);
        $customerData['portal_access'] = $portalAccess;
        $customerData['user_id'] = $accountId;

        [$customer, $portalUser] = DB::transaction(function () use ($validated, $customerData, $portalAccess) {
            $portalUser = null;
            if ($portalAccess) {
                $roleId = $this->resolveClientRoleId();
                $portalUser = $this->createPortalUser($validated, $roleId);
                $customerData['portal_user_id'] = $portalUser->id;
            }

            $customer = Customer::create($customerData);

            return [$customer, $portalUser];
        });

        // Add properties if provided
        if (!empty($validated['properties'])) {
            $propertyPayload = $validated['properties'];
            $propertyPayload['type'] = $propertyPayload['type'] ?? 'physical';
            if (!empty($propertyPayload['city'])) {
                $propertyPayload['is_default'] = true;
                $customer->properties()->create($propertyPayload);
            }
        }

        ActivityLog::record($request->user(), $customer, 'created', [
            'company_name' => $customer->company_name,
            'email' => $customer->email,
        ], 'Customer created');

        if ($portalUser) {
            $token = Password::broker()->createToken($portalUser);
            $portalUser->notify(new InviteUserNotification(
                $token,
                $accountOwner?->company_name ?: config('app.name'),
                $accountOwner?->company_logo_url,
                'client'
            ));
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Customer created successfully.',
                'customer' => $customer->load('properties'),
                'portal_user_id' => $portalUser?->id,
            ], 201);
        }

        return redirect()->route('customer.index')->with('success', 'Customer created successfully.');
    }

    /**
     * Store a customer from quick-create dialogs.
     */
    public function storeQuick(CustomerRequest $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(403);
        }
        [$accountOwner, $accountId] = $this->resolveCustomerAccount($user, true);

        $validated = $request->validated();
        $defaultLogo = config('icon_presets.defaults.company', Customer::DEFAULT_LOGO_PATH);
        $logoPath = FileHandler::handleImageUpload('customers', $request, 'logo', $defaultLogo);
        if (!empty($validated['logo_icon']) && !$request->hasFile('logo')) {
            $logoPath = $validated['logo_icon'];
        }
        $validated['logo'] = $logoPath;
        unset($validated['logo_icon']);
        $validated['header_image'] = FileHandler::handleImageUpload('customers', $request, 'header_image', 'customers/customer.png');
        $portalAccess = array_key_exists('portal_access', $validated)
            ? (bool) $validated['portal_access']
            : true;

        $customerData = Arr::except($validated, ['temporary_password']);
        $customerData['portal_access'] = $portalAccess;
        $customerData['user_id'] = $accountId;

        [$customer, $portalUser] = DB::transaction(function () use ($validated, $customerData, $portalAccess) {
            $portalUser = null;
            if ($portalAccess) {
                $roleId = $this->resolveClientRoleId();
                $portalUser = $this->createPortalUser($validated, $roleId);
                $customerData['portal_user_id'] = $portalUser->id;
            }

            $customer = Customer::create($customerData);

            return [$customer, $portalUser];
        });

        $property = null;
        if (!empty($validated['properties'])) {
            $propertyPayload = $validated['properties'];
            $propertyPayload['type'] = $propertyPayload['type'] ?? 'physical';
            if (!empty($propertyPayload['city'])) {
                $propertyPayload['is_default'] = true;
                $property = $customer->properties()->create($propertyPayload);
            }
        }

        ActivityLog::record($request->user(), $customer, 'created', [
            'company_name' => $customer->company_name,
            'email' => $customer->email,
        ], 'Customer created');

        if ($portalUser) {
            $token = Password::broker()->createToken($portalUser);
            $portalUser->notify(new InviteUserNotification(
                $token,
                $accountOwner?->company_name ?: config('app.name'),
                $accountOwner?->company_logo_url,
                'client'
            ));
        }

        $propertyData = [];
        if ($property) {
            $propertyData[] = [
                'id' => $property->id,
                'type' => $property->type,
                'is_default' => (bool) $property->is_default,
                'street1' => $property->street1,
                'street2' => $property->street2,
                'city' => $property->city,
                'state' => $property->state,
                'zip' => $property->zip,
                'country' => $property->country,
            ];
        }

        return response()->json([
            'customer' => [
                'id' => $customer->id,
                'company_name' => $customer->company_name,
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'discount_rate' => $customer->discount_rate,
            ],
            'property_id' => $property?->id,
            'properties' => $propertyData,
        ], 201);
    }

    /**
     * Update the specified customer in the database.
     */
    public function update(CustomerRequest $request, Customer $customer)
    {
        $this->authorize('update', $customer);

        $validated = $request->validated();
        $defaultLogo = config('icon_presets.defaults.company', Customer::DEFAULT_LOGO_PATH);
        $validated['logo'] = FileHandler::handleImageUpload(
            'customers',
            $request,
            'logo',
            $defaultLogo,
            $customer->logo
        );
        if (!empty($validated['logo_icon']) && !$request->hasFile('logo')) {
            if (
                $customer->logo
                && $customer->logo !== $validated['logo_icon']
                && !str_starts_with($customer->logo, '/')
                && !str_starts_with($customer->logo, 'http://')
                && !str_starts_with($customer->logo, 'https://')
                && $customer->logo !== $defaultLogo
            ) {
                FileHandler::deleteFile($customer->logo, $defaultLogo);
            }
            $validated['logo'] = $validated['logo_icon'];
        }
        unset($validated['logo_icon']);
        $validated['header_image'] = FileHandler::handleImageUpload(
            'customers',
            $request,
            'header_image',
            'customers/customer.png',
            $customer->header_image
        );

        $customer->update($validated);

        if (!empty($validated['properties'])) {
            $propertyPayload = $validated['properties'];
            $propertyPayload['type'] = $propertyPayload['type'] ?? 'physical';

            if (!empty($propertyPayload['city'])) {
                DB::transaction(function () use ($customer, $propertyPayload) {
                    $defaultProperty = $customer->properties()->where('is_default', true)->first();
                    if ($defaultProperty) {
                        $defaultProperty->update($propertyPayload);
                        return;
                    }

                    $fallbackProperty = $customer->properties()->reorder('id')->first();
                    if ($fallbackProperty) {
                        $customer->properties()->update(['is_default' => false]);
                        $fallbackProperty->update(array_merge($propertyPayload, ['is_default' => true]));
                        return;
                    }

                    $propertyPayload['is_default'] = true;
                    $customer->properties()->create($propertyPayload);
                });
            }
        }

        ActivityLog::record($request->user(), $customer, 'updated', [
            'company_name' => $customer->company_name,
            'email' => $customer->email,
        ], 'Customer updated');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Customer updated successfully.',
                'customer' => $customer->load('properties'),
            ]);
        }

        return redirect()->route('customer.index')->with('success', 'Customer updated successfully.');
    }

    /**
     * Remove the specified customer from the database.
     */
    public function destroy(Request $request, Customer $customer)
    {
        $this->authorize('delete', $customer);

        FileHandler::deleteFile($customer->logo, Customer::DEFAULT_LOGO_PATH);
        FileHandler::deleteFile($customer->header_image, 'customers/customer.png');
        ActivityLog::record($request->user(), $customer, 'deleted', [
            'company_name' => $customer->company_name,
            'email' => $customer->email,
        ], 'Customer deleted');
        $customer->delete();

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Customer deleted successfully.',
            ]);
        }

        return redirect()->route('customer.index')->with('success', 'Customer deleted successfully.');
    }

    /**
     * Bulk actions on customers.
     */
    public function bulk(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(403);
        }
        [, $accountId] = $this->resolveCustomerAccount($user);

        $data = $request->validate([
            'action' => 'required|in:portal_enable,portal_disable,archive,restore,delete',
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $customers = Customer::query()
            ->byUser($accountId)
            ->whereIn('id', $data['ids'])
            ->get();

        if ($data['action'] === 'portal_enable') {
            foreach ($customers as $customer) {
                $this->authorize('update', $customer);
            }
            Customer::query()
                ->byUser($accountId)
                ->whereIn('id', $data['ids'])
                ->update(['portal_access' => true]);

            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Portal access enabled.',
                    'ids' => $data['ids'],
                ]);
            }

            return redirect()->back()->with('success', 'Portal access enabled.');
        }

        if ($data['action'] === 'portal_disable') {
            foreach ($customers as $customer) {
                $this->authorize('update', $customer);
            }
            Customer::query()
                ->byUser($accountId)
                ->whereIn('id', $data['ids'])
                ->update(['portal_access' => false]);

            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Portal access disabled.',
                    'ids' => $data['ids'],
                ]);
            }

            return redirect()->back()->with('success', 'Portal access disabled.');
        }

        if ($data['action'] === 'archive') {
            foreach ($customers as $customer) {
                $this->authorize('update', $customer);
            }
            Customer::query()
                ->byUser($accountId)
                ->whereIn('id', $data['ids'])
                ->update(['is_active' => false]);

            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Customers archived.',
                    'ids' => $data['ids'],
                ]);
            }

            return redirect()->back()->with('success', 'Customers archived.');
        }

        if ($data['action'] === 'restore') {
            foreach ($customers as $customer) {
                $this->authorize('update', $customer);
            }
            Customer::query()
                ->byUser($accountId)
                ->whereIn('id', $data['ids'])
                ->update(['is_active' => true]);

            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Customers restored.',
                    'ids' => $data['ids'],
                ]);
            }

            return redirect()->back()->with('success', 'Customers restored.');
        }

        foreach ($customers as $customer) {
            $this->authorize('delete', $customer);
            FileHandler::deleteFile($customer->logo, Customer::DEFAULT_LOGO_PATH);
            FileHandler::deleteFile($customer->header_image, 'customers/customer.png');
            $customer->delete();
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Customers deleted.',
                'ids' => $data['ids'],
            ]);
        }

        return redirect()->back()->with('success', 'Customers deleted.');
    }

    private function resolveCustomerAccount(User $user, bool $allowPos = false): array
    {
        $ownerId = $user->accountOwnerId();
        $owner = $ownerId === $user->id
            ? $user
            : User::query()
                ->select(['id', 'company_type', 'company_name', 'company_logo'])
                ->find($ownerId);

        if (!$owner) {
            abort(403);
        }

        $accountId = $user->id;
        if ($owner->company_type === 'products') {
            if ($user->id !== $owner->id) {
                $membership = $user->relationLoaded('teamMembership')
                    ? $user->teamMembership
                    : $user->teamMembership()->first();
                $canManage = $membership?->hasPermission('sales.manage') ?? false;
                $canPos = $allowPos ? ($membership?->hasPermission('sales.pos') ?? false) : false;
                if (!$membership || (!$canManage && !$canPos)) {
                    abort(403);
                }
            }
            $accountId = $owner->id;
        }

        return [$owner, $accountId];
    }

    private function resolveClientRoleId(): int
    {
        return Role::firstOrCreate(
            ['name' => 'client'],
            ['description' => 'Access to client functionalities']
        )->id;
    }

    private function createPortalUser(array $validated, int $roleId): User
    {
        $name = trim(($validated['first_name'] ?? '') . ' ' . ($validated['last_name'] ?? ''));
        if ($name === '') {
            $name = $validated['company_name'] ?? $validated['email'];
        }

        return User::create([
            'name' => $name ?: $validated['email'],
            'email' => $validated['email'],
            'password' => Hash::make(Str::random(32)),
            'role_id' => $roleId,
            'phone_number' => $validated['phone'] ?? null,
            'company_name' => $validated['company_name'] ?? null,
            'must_change_password' => true,
        ]);
    }
}
