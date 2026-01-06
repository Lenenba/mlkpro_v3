<?php

namespace App\Http\Controllers;

use App\Models\Work;
use App\Models\Quote;
use App\Models\Task;
use App\Models\Invoice;
use App\Models\Payment;
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
            'created_from',
            'created_to',
            'sort',
            'direction',
        ]);
        $userId = Auth::user()->id;

        $baseQuery = Customer::query()
            ->filter($filters)
            ->byUser($userId);

        $sort = in_array($filters['sort'] ?? null, ['company_name', 'first_name', 'created_at', 'quotes_count', 'works_count'], true)
            ? $filters['sort']
            : 'created_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        // Fetch customers with pagination
        $customers = (clone $baseQuery)
            ->with(['properties'])
            ->withCount([
                'quotes as quotes_count' => fn($query) => $query->where('user_id', $userId),
                'works as works_count' => fn($query) => $query->where('user_id', $userId),
                'invoices as invoices_count' => fn($query) => $query->where('user_id', $userId),
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
            ->whereHas('quotes', fn($query) => $query->where('user_id', $userId))
            ->count();
        $withWorks = (clone $baseQuery)
            ->whereHas('works', fn($query) => $query->where('user_id', $userId))
            ->count();
        $activeCount = (clone $baseQuery)
            ->where(function ($query) use ($userId, $recentThreshold) {
                $query->whereHas('quotes', function ($sub) use ($userId, $recentThreshold) {
                    $sub->where('user_id', $userId)
                        ->where('created_at', '>=', $recentThreshold);
                })->orWhereHas('works', function ($sub) use ($userId, $recentThreshold) {
                    $sub->where('user_id', $userId)
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
                'quotes as quotes_count' => fn($query) => $query->where('user_id', $userId),
                'works as works_count' => fn($query) => $query->where('user_id', $userId),
                'invoices as invoices_count' => fn($query) => $query->where('user_id', $userId),
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
        ]);
    }

    /**
     * Return customers and properties for quick-create dialogs.
     */
    public function options(Request $request)
    {
        $userId = $request->user()->id;

        $customers = Customer::byUser($userId)
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
    public function show(Customer $customer, ?Request $request)
    {
        $this->authorize('view', $customer);

        $user = $request?->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        // Valider les filtres uniquement si la requête contient des données
        $filters = $request?->only([
            'name',
            'status',
            'month',
        ]) ?? [];

        // Fetch works for the retrieved customers
        $works = Work::with(['products', 'ratings', 'customer'])
            ->byCustomer($customer->id)
            ->byUser($accountId)
            ->filter($filters)
            ->latest()
            ->paginate(10) // Paginer avec 10 résultats par page
            ->withQueryString(); // Conserver les paramètres de requête dans l'URL

        $customer->load([
            'properties',
            'quotes' => fn($query) => $query->latest()->limit(10),
            'works' => fn($query) => $query->with('invoice')->latest()->limit(10),
            'requests' => fn($query) => $query->latest()->limit(10)->with('quote:id,number,status,customer_id'),
            'invoices' => fn($query) => $query->withSum('payments', 'amount')->latest()->limit(10),
        ]);

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

        return $this->inertiaOrJson('Customer/Show', [
            'customer' => $customer,
            'works' => $works,
            'filters' => $filters,
            'stats' => $stats,
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
        $validated = $request->validated();
        $validated['logo'] = FileHandler::handleImageUpload('customers', $request, 'logo', 'customers/customer.png');
        $validated['header_image'] = FileHandler::handleImageUpload('customers', $request, 'header_image', 'customers/customer.png');
        $portalAccess = array_key_exists('portal_access', $validated)
            ? (bool) $validated['portal_access']
            : true;

        $customerData = Arr::except($validated, ['temporary_password']);
        $customerData['portal_access'] = $portalAccess;

        [$customer, $portalUser] = DB::transaction(function () use ($request, $validated, $customerData, $portalAccess) {
        $portalUser = null;
        if ($portalAccess) {
            $roleId = $this->resolveClientRoleId();
            $portalUser = $this->createPortalUser($validated, $roleId);
            $customerData['portal_user_id'] = $portalUser->id;
        }

        $customer = $request->user()->customers()->create($customerData);

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
            $accountOwner = User::query()->find($request->user()->accountOwnerId());
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
        $validated = $request->validated();
        $validated['logo'] = FileHandler::handleImageUpload('customers', $request, 'logo', 'customers/customer.png');
        $validated['header_image'] = FileHandler::handleImageUpload('customers', $request, 'header_image', 'customers/customer.png');
        $portalAccess = array_key_exists('portal_access', $validated)
            ? (bool) $validated['portal_access']
            : true;

        $customerData = Arr::except($validated, ['temporary_password']);
        $customerData['portal_access'] = $portalAccess;

        [$customer, $portalUser] = DB::transaction(function () use ($request, $validated, $customerData, $portalAccess) {
            $portalUser = null;
            if ($portalAccess) {
                $roleId = $this->resolveClientRoleId();
                $portalUser = $this->createPortalUser($validated, $roleId);
                $customerData['portal_user_id'] = $portalUser->id;
            }

            $customer = $request->user()->customers()->create($customerData);

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
            $accountOwner = User::query()->find($request->user()->accountOwnerId());
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
        $validated['logo'] = FileHandler::handleImageUpload(
            'customers',
            $request,
            'logo',
            'customers/customer.png',
            $customer->logo
        );
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

        FileHandler::deleteFile($customer->logo, 'customers/customer.png');
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
