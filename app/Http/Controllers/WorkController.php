<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Work;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteProduct;
use App\Models\Task;
use App\Models\Tax;
use App\Models\ActivityLog;
use App\Models\TeamMember;
use App\Models\WorkChecklistItem;
use App\Models\User;
use App\Services\WorkBillingService;
use App\Services\WorkScheduleService;
use App\Services\UsageLimitService;
use App\Notifications\ActionEmailNotification;
use App\Support\NotificationDispatcher;
use Illuminate\Http\Request;
use App\Http\Requests\WorkRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use App\Traits\GeneratesSequentialNumber;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class WorkController extends Controller
{
    use AuthorizesRequests, GeneratesSequentialNumber;

    /**
     * Display a listing of the works.
     */
    public function index(Request $request)
    {
        $filters = $request->only([
            'search',
            'status',
            'customer_id',
            'start_from',
            'start_to',
            'sort',
            'direction',
        ]);

        $user = Auth::user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();
        $isAccountOwner = ($user?->id ?? Auth::id()) === $accountId;

        $baseQuery = Work::query()
            ->filter($filters)
            ->byUser($accountId);

        if (!$isAccountOwner) {
            $membership = $user?->teamMembership()->first();
            if ($membership) {
                $baseQuery->whereHas('teamMembers', fn($query) => $query->whereKey($membership->id));
            } else {
                $baseQuery->whereRaw('1=0');
            }
        }

        $sort = in_array($filters['sort'] ?? null, ['start_date', 'status', 'total', 'job_title'], true)
            ? $filters['sort']
            : 'start_date';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $works = (clone $baseQuery)
            ->with(['customer', 'invoice'])
            ->withAvg('ratings', 'rating')
            ->withCount('ratings')
            ->orderBy($sort, $direction)
            ->simplePaginate(10)
            ->withQueryString();

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
            'total' => (clone $baseQuery)->count(),
            'scheduled' => (clone $baseQuery)->whereIn('status', $scheduledStatuses)->count(),
            'in_progress' => (clone $baseQuery)->whereIn('status', $inProgressStatuses)->count(),
            'completed' => (clone $baseQuery)->whereIn('status', $completedStatuses)->count(),
            'cancelled' => (clone $baseQuery)->where('status', Work::STATUS_CANCELLED)->count(),
        ];

        $customersQuery = Customer::byUser($accountId)->orderBy('company_name');
        if (!$isAccountOwner) {
            $customerIds = (clone $baseQuery)
                ->select('customer_id')
                ->distinct()
                ->pluck('customer_id');
            $customersQuery->whereIn('id', $customerIds);
        }

        $customers = $customersQuery->get(['id', 'company_name', 'first_name', 'last_name']);

        return $this->inertiaOrJson('Work/Index', [
            'works' => $works,
            'filters' => $filters,
            'stats' => $stats,
            'customers' => $customers,
        ]);
    }

    /**
     * Show the form for creating a new work.
     */
    public function create(Customer $customer)
    {
        $user = Auth::user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();
        if (!$user || $user->id !== $accountId) {
            abort(403);
        }

        if ($customer->user_id !== $accountId) {
            abort(403);
        }

        $teamMembers = TeamMember::query()
            ->forAccount($accountId)
            ->active()
            ->with('user')
            ->orderBy('created_at')
            ->get();

        $calendarStart = Carbon::now()->subMonths(3)->toDateString();
        $calendarEnd = Carbon::now()->addMonths(6)->toDateString();
        $tasks = Task::query()
            ->forAccount($accountId)
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$calendarStart, $calendarEnd])
            ->with(['assignee.user:id,name'])
            ->orderBy('due_date')
            ->get(['id', 'work_id', 'title', 'due_date', 'start_time', 'end_time', 'assigned_team_member_id'])
            ->map(function ($task) {
                return [
                    'id' => $task->id,
                    'work_id' => $task->work_id,
                    'title' => $task->title,
                    'due_date' => $task->due_date,
                    'start_time' => $task->start_time,
                    'end_time' => $task->end_time,
                    'assigned_team_member_id' => $task->assigned_team_member_id,
                    'assignee' => $task->assignee?->user ? [
                        'name' => $task->assignee->user->name,
                    ] : null,
                ];
            });

        $itemType = $user->company_type === 'products'
            ? Product::ITEM_TYPE_PRODUCT
            : Product::ITEM_TYPE_SERVICE;

        return $this->inertiaOrJson('Work/Create', [
            'lastWorkNumber' => $this->generateNextNumber($customer->works->last()->number ?? null),
            'tasks' => $tasks,
            'customer' => $customer->load('properties'),
            'teamMembers' => $teamMembers,
            'lockedFromQuote' => false,
        ]);
    }

    /**
     * Display the specified work.
     */
    public function show($id)
    {
        $accountId = Auth::user()?->accountOwnerId() ?? Auth::id();
        $work = Work::byUser($accountId)
            ->with(['customer', 'invoice', 'products', 'teamMembers.user', 'ratings', 'quote.products'])
            ->withAvg('ratings', 'rating')
            ->withCount('ratings')
            ->findOrFail($id);

        $this->authorize('view', $work);

        $this->applyQuoteSnapshotToWork($work);
        $lockedFromQuote = (bool) $work->quote_id && $work->relationLoaded('quote') && $work->quote;

        $work->loadMissing([
            'checklistItems' => fn($query) => $query->orderBy('sort_order'),
            'media',
        ]);

        if ($work->relationLoaded('media')) {
            $mediaPayload = $work->media
                ->sortByDesc('created_at')
                ->values()
                ->map(function ($media) {
                    $path = $media->path;
                    $url = $path
                        ? (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
                            ? $path
                            : Storage::disk('public')->url($path))
                        : null;

                    return [
                        'id' => $media->id,
                        'type' => $media->type,
                        'path' => $path,
                        'url' => $url,
                        'meta' => $media->meta,
                        'created_at' => $media->created_at,
                    ];
                });

            $work->setRelation('media', $mediaPayload);
        }

        return $this->inertiaOrJson('Work/Show', [
            'work' => $work,
            'customer' => $work->customer,
            'lockedFromQuote' => $lockedFromQuote,
        ]);
    }

    /**
     * Store a newly created work in storage.
     */
    public function store(WorkRequest $request)
    {
        $user = Auth::user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();
        if (!$user || $user->id !== $accountId) {
            abort(403);
        }

        app(UsageLimitService::class)->enforceLimit($user, 'jobs');

        $itemType = $user->company_type === 'products'
            ? Product::ITEM_TYPE_PRODUCT
            : Product::ITEM_TYPE_SERVICE;

        $validated = $request->validated();
        $customer = Customer::byUser($accountId)->with(['works'])->findOrFail($validated['customer_id']);
        $validated['instructions'] = $validated['instructions'] ?? '';

        $validated['start_date'] = !empty($validated['start_date'])
            ? Carbon::parse($validated['start_date'])->toDateString()
            : null;
        $validated['end_date'] = !empty($validated['end_date'])
            ? Carbon::parse($validated['end_date'])->toDateString()
            : null;

        $validated['start_time'] = !empty($validated['start_time'])
            ? Carbon::parse($validated['start_time'])->format('H:i:s')
            : null;
        $validated['end_time'] = !empty($validated['end_time'])
            ? Carbon::parse($validated['end_time'])->format('H:i:s')
            : null;

        $validated['user_id'] = $accountId;
        $validated['status'] = $validated['status'] ?? 'scheduled';

        $billingFields = [
            'billing_mode',
            'billing_cycle',
            'billing_grouping',
            'billing_delay_days',
            'billing_date_rule',
        ];

        foreach ($billingFields as $field) {
            $value = $validated[$field] ?? null;
            if ($value === '') {
                $value = null;
            }
            if ($value === null) {
                $value = $customer->{$field} ?? null;
            }
            $validated[$field] = $value;
        }

        $selectedTeamMemberIds = collect($validated['team_member_ids'] ?? [])
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        $lines = collect();
        if (array_key_exists('products', $validated)) {
            $lines = collect($validated['products'] ?? [])
                ->map(function ($product) {
                    $quantity = (int) ($product['quantity'] ?? 1);
                    $price = (float) ($product['price'] ?? 0);

                    return [
                        'product_id' => (int) $product['id'],
                        'quantity' => $quantity,
                        'price' => $price,
                        'total' => round($quantity * $price, 2),
                    ];
                })
                ->filter(fn($line) => $line['product_id'] > 0);

            $productMap = collect();
            if ($lines->isNotEmpty()) {
                $productMap = Product::byUser($accountId)
                    ->where('item_type', $itemType)
                    ->whereIn('id', $lines->pluck('product_id'))
                    ->get()
                    ->keyBy('id');
            }

            $lines = $lines->map(function ($line) use ($productMap) {
                $product = $productMap->get($line['product_id']);
                if (!$product) {
                    return null;
                }

                $price = $line['price'] > 0 ? $line['price'] : (float) $product->price;
                $quantity = (int) $line['quantity'];

                return [
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'price' => $price,
                    'total' => round($price * $quantity, 2),
                ];
            })->filter();

            $subtotal = $lines->sum('total');
            $validated['subtotal'] = $subtotal;
            $validated['total'] = $subtotal;
        } else {
            unset($validated['subtotal'], $validated['total']);
        }

        $allowedTeamMemberIds = $selectedTeamMemberIds->isNotEmpty()
            ? TeamMember::query()->forAccount($accountId)->whereIn('id', $selectedTeamMemberIds)->pluck('id')
            : collect();

        $work = DB::transaction(function () use ($customer, $validated, $lines, $allowedTeamMemberIds) {
            $work = $customer->works()->create($validated);

            if ($lines->isNotEmpty()) {
                $pivotData = $lines->mapWithKeys(function ($line) {
                    return [
                        $line['product_id'] => [
                            'quantity' => $line['quantity'],
                            'price' => $line['price'],
                            'total' => $line['total'],
                        ],
                    ];
                });
                $work->products()->sync($pivotData);
            }

            $work->teamMembers()->sync($allowedTeamMemberIds->all());

            return $work;
        });

        ActivityLog::record(Auth::user(), $work, 'created', [
            'status' => $work->status,
            'total' => $work->total,
        ], 'Job created');

        $this->autoScheduleTasksForWork($work, $user);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Job created successfully!',
                'work' => $work->load(['customer', 'products', 'teamMembers']),
            ], 201);
        }

        return redirect()->route('customer.show', $customer)->with('success', 'Job created successfully!');
    }

    /**
     * Show the form for editing the specified work.
     */
    public function edit(int $work_id, ?Request $request)
    {
        $accountId = Auth::user()?->accountOwnerId() ?? Auth::id();
        $accountCompanyType = Auth::user()?->id === $accountId
            ? Auth::user()?->company_type
            : User::query()->whereKey($accountId)->value('company_type');
        $itemType = $accountCompanyType === 'products'
            ? Product::ITEM_TYPE_PRODUCT
            : Product::ITEM_TYPE_SERVICE;

        $work = Work::byUser($accountId)
            ->with(['customer', 'invoice', 'products', 'ratings', 'teamMembers.user', 'quote.products'])
            ->findOrFail($work_id);
        $this->authorize('edit', $work);

        $this->applyQuoteSnapshotToWork($work);
        $lockedFromQuote = (bool) $work->quote_id && $work->relationLoaded('quote') && $work->quote;

        $filters = $request->only(['category_id', 'name', 'stock']);
        $workProducts = $work->products()->with('category')->get() ?: [];

        $productsQuery = Product::byUser($accountId)
            ->where('item_type', $itemType)
            ->mostRecent()
            ->filter($filters)
            ->with(['category', 'works']);
        $products = $productsQuery->simplePaginate(8)->withQueryString();

        $customer = Customer::with(['works'])
            ->byUser($accountId)
            ->findOrFail($work->customer_id);

        $teamMembers = TeamMember::query()
            ->forAccount($accountId)
            ->active()
            ->with('user')
            ->orderBy('created_at')
            ->get();

        $calendarStart = $work->start_date
            ? Carbon::parse($work->start_date)->subMonths(1)->toDateString()
            : Carbon::now()->subMonths(3)->toDateString();
        $calendarEnd = $work->end_date
            ? Carbon::parse($work->end_date)->addMonths(1)->toDateString()
            : Carbon::now()->addMonths(6)->toDateString();

        $tasks = Task::query()
            ->forAccount($accountId)
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$calendarStart, $calendarEnd])
            ->with(['assignee.user:id,name'])
            ->orderBy('due_date')
            ->get(['id', 'work_id', 'title', 'due_date', 'start_time', 'end_time', 'assigned_team_member_id'])
            ->map(function ($task) {
                return [
                    'id' => $task->id,
                    'work_id' => $task->work_id,
                    'title' => $task->title,
                    'due_date' => $task->due_date,
                    'start_time' => $task->start_time,
                    'end_time' => $task->end_time,
                    'assigned_team_member_id' => $task->assigned_team_member_id,
                    'assignee' => $task->assignee?->user ? [
                        'name' => $task->assignee->user->name,
                    ] : null,
                ];
            });

        return $this->inertiaOrJson('Work/Create', [
            'work' => $work,
            'lastWorkNumber' => $work->number,
            'customer' => $customer,
            'filters' => $filters,
            'workProducts' => $workProducts,
            'products' => $products,
            'tasks' => $tasks,
            'teamMembers' => $teamMembers,
            'lockedFromQuote' => $lockedFromQuote,
        ]);
    }

    /**
     * Update the specified work in storage.
     */
    public function update(WorkRequest $request, $id)
    {
        $user = Auth::user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();
        $work = Work::byUser($accountId)->findOrFail($id);
        $this->authorize('update', $work);

        $accountCompanyType = $user?->id === $accountId
            ? $user?->company_type
            : User::query()->whereKey($accountId)->value('company_type');
        $itemType = $accountCompanyType === 'products'
            ? Product::ITEM_TYPE_PRODUCT
            : Product::ITEM_TYPE_SERVICE;

        $validated = $request->validated();
        $previousStatus = $work->status;
        $validated['instructions'] = $validated['instructions'] ?? $work->instructions ?? '';

        $lockedQuote = null;
        if ($work->quote_id) {
            $lockedQuote = Quote::query()
                ->where('user_id', $accountId)
                ->with('products')
                ->find($work->quote_id);
        }

        if ($lockedQuote) {
            $validated['job_title'] = $lockedQuote->job_title;
            $validated['instructions'] = $lockedQuote->notes ?: ($lockedQuote->messages ?: '');
            $validated['subtotal'] = $lockedQuote->subtotal;
            $validated['total'] = $lockedQuote->total;

            $lines = $lockedQuote->products->map(function ($product) use ($work) {
                return [
                    'product_id' => $product->id,
                    'quote_id' => $work->quote_id,
                    'quantity' => (int) $product->pivot->quantity,
                    'price' => (float) $product->pivot->price,
                    'total' => (float) $product->pivot->total,
                    'description' => $product->pivot->description,
                ];
            });
        } else {
            $lines = collect($validated['products'] ?? [])
                ->map(function ($product) {
                    $quantity = (int) ($product['quantity'] ?? 1);
                    $price = (float) ($product['price'] ?? 0);

                    return [
                        'product_id' => (int) $product['id'],
                        'quantity' => $quantity,
                        'price' => $price,
                        'total' => round($quantity * $price, 2),
                    ];
                })
                ->filter(fn($line) => $line['product_id'] > 0);

            $productMap = collect();
            if ($lines->isNotEmpty()) {
                $productMap = Product::byUser($accountId)
                    ->where('item_type', $itemType)
                    ->whereIn('id', $lines->pluck('product_id'))
                    ->get()
                    ->keyBy('id');
            }

            $lines = $lines->map(function ($line) use ($productMap) {
                $product = $productMap->get($line['product_id']);
                if (!$product) {
                    return null;
                }

                $price = $line['price'] > 0 ? $line['price'] : (float) $product->price;
                $quantity = (int) $line['quantity'];

                return [
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'price' => $price,
                    'total' => round($price * $quantity, 2),
                ];
            })->filter();

            $subtotal = $lines->sum('total');
            $validated['subtotal'] = $subtotal;
            $validated['total'] = $subtotal;
        }
        $validated['status'] = $validated['status'] ?? $work->status ?? 'scheduled';

        $billingFields = [
            'billing_mode',
            'billing_cycle',
            'billing_grouping',
            'billing_delay_days',
            'billing_date_rule',
        ];

        foreach ($billingFields as $field) {
            if (!array_key_exists($field, $validated)) {
                continue;
            }

            if ($validated[$field] === '') {
                $validated[$field] = null;
            }
        }

        $shouldSyncTeamMembers = $user && $user->id === $accountId && array_key_exists('team_member_ids', $validated);

        $allowedTeamMemberIds = collect();
        if ($shouldSyncTeamMembers) {
            $selectedTeamMemberIds = collect($validated['team_member_ids'] ?? [])
                ->map(fn($id) => (int) $id)
                ->filter(fn($id) => $id > 0)
                ->unique()
                ->values();

            if ($selectedTeamMemberIds->isNotEmpty()) {
                $allowedTeamMemberIds = TeamMember::query()
                    ->forAccount($accountId)
                    ->whereIn('id', $selectedTeamMemberIds)
                    ->pluck('id');
            }
        }

        DB::transaction(function () use ($work, $validated, $lines, $allowedTeamMemberIds, $shouldSyncTeamMembers, $lockedQuote) {
            $work->update($validated);

            if ($lockedQuote) {
                $pivotData = $lines->mapWithKeys(function ($line) {
                    return [
                        $line['product_id'] => [
                            'quote_id' => $line['quote_id'] ?? null,
                            'quantity' => $line['quantity'],
                            'price' => $line['price'],
                            'description' => $line['description'] ?? null,
                            'total' => $line['total'],
                        ],
                    ];
                });
                $work->products()->sync($pivotData->toArray());
            } elseif ($lines->isNotEmpty()) {
                $pivotData = $lines->mapWithKeys(function ($line) {
                    return [
                        $line['product_id'] => [
                            'quantity' => $line['quantity'],
                            'price' => $line['price'],
                            'total' => $line['total'],
                        ],
                    ];
                });
                $work->products()->sync($pivotData);
            }

            if ($shouldSyncTeamMembers) {
                $work->teamMembers()->sync($allowedTeamMemberIds->all());
            }
        });

        if ($previousStatus !== $work->status) {
            ActivityLog::record(Auth::user(), $work, 'status_changed', [
                'from' => $previousStatus,
                'to' => $work->status,
            ], 'Job status updated');
        }

        ActivityLog::record(Auth::user(), $work, 'updated', [
            'total' => $work->total,
        ], 'Job updated');

        $this->autoScheduleTasksForWork($work, $user);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Job updated successfully.',
                'work' => $work->fresh(['customer', 'products', 'teamMembers']),
            ]);
        }

        return redirect()->back()->with('success', 'Job updated successfully.');
    }

    public function updateStatus(Request $request, Work $work, WorkBillingService $billingService)
    {
        $user = Auth::user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();
        if (!$user || $work->user_id !== $accountId) {
            abort(403);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(Work::STATUSES)],
        ]);

        $nextStatus = $validated['status'];
        $beforeCount = $work->media()->where('type', 'before')->count();
        $afterCount = $work->media()->where('type', 'after')->count();

        if ($nextStatus === Work::STATUS_IN_PROGRESS && $beforeCount < 3) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Validation error.',
                    'errors' => [
                        'status' => ['Upload at least 3 before photos before starting the job.'],
                    ],
                ], 422);
            }

            return redirect()->back()->withErrors([
                'status' => 'Upload at least 3 before photos before starting the job.',
            ]);
        }

        if ($nextStatus === Work::STATUS_TECH_COMPLETE) {
            $pendingChecklist = $work->checklistItems()->where('status', '!=', 'done')->count();
            if ($pendingChecklist > 0) {
                if ($this->shouldReturnJson($request)) {
                    return response()->json([
                        'message' => 'Validation error.',
                        'errors' => [
                            'status' => ['Complete all checklist items before finishing the job.'],
                        ],
                    ], 422);
                }

                return redirect()->back()->withErrors([
                    'status' => 'Complete all checklist items before finishing the job.',
                ]);
            }

            if ($afterCount < 3) {
                if ($this->shouldReturnJson($request)) {
                    return response()->json([
                        'message' => 'Validation error.',
                        'errors' => [
                            'status' => ['Upload at least 3 after photos before finishing the job.'],
                        ],
                    ], 422);
                }

                return redirect()->back()->withErrors([
                    'status' => 'Upload at least 3 after photos before finishing the job.',
                ]);
            }
        }

        $autoValidateJobs = (bool) ($work->customer?->auto_validate_jobs ?? false);
        if ($autoValidateJobs && in_array($nextStatus, [Work::STATUS_TECH_COMPLETE, Work::STATUS_PENDING_REVIEW], true)) {
            $nextStatus = Work::STATUS_AUTO_VALIDATED;
        }

        $previousStatus = $work->status;
        $work->status = $nextStatus;
        $work->save();

        ActivityLog::record($user, $work, 'status_changed', [
            'from' => $previousStatus,
            'to' => $nextStatus,
        ], 'Job status updated');

        $notifyStatuses = [Work::STATUS_TECH_COMPLETE, Work::STATUS_PENDING_REVIEW];
        if (!in_array($previousStatus, $notifyStatuses, true) && in_array($nextStatus, $notifyStatuses, true)) {
            $customer = $work->customer;
            if ($customer && $customer->email) {
                $customerLabel = $customer->company_name
                    ?: trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
                $usePublicLink = !(bool) ($customer->portal_access ?? true) || !$customer->portal_user_id;
                $actionUrl = route('dashboard');
                $actionLabel = 'Open dashboard';
                if ($usePublicLink) {
                    $expiresAt = now()->addDays(7);
                    $actionUrl = URL::temporarySignedRoute(
                        'public.works.show',
                        $expiresAt,
                        ['work' => $work->id]
                    );
                    $actionLabel = 'Review job';
                }

                NotificationDispatcher::send($customer, new ActionEmailNotification(
                    'Job ready for validation',
                    'A job is ready for your validation.',
                    [
                        ['label' => 'Job', 'value' => $work->job_title ?? $work->number ?? $work->id],
                        ['label' => 'Status', 'value' => $nextStatus],
                        ['label' => 'Customer', 'value' => $customerLabel ?: 'Client'],
                    ],
                    $actionUrl,
                    $actionLabel,
                    'Job ready for validation'
                ), [
                    'work_id' => $work->id,
                ]);
            }
        }

        if (in_array($nextStatus, [Work::STATUS_VALIDATED, Work::STATUS_AUTO_VALIDATED], true)) {
            $billingResolver = app(\App\Services\TaskBillingService::class);
            if ($billingResolver->shouldInvoiceOnWorkValidation($work)) {
                $billingService->createInvoiceFromWork($work, $user);
            }
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Job status updated.',
                'work' => $work->fresh(),
            ]);
        }

        return redirect()->back()->with('success', 'Job status updated.');
    }

    /**
     * Remove the specified work from storage.
     */
    public function destroy($id)
    {
        $user = Auth::user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();
        if (!$user || $user->id !== $accountId) {
            abort(403);
        }

        $work = Work::byUser($accountId)->findOrFail($id);
        ActivityLog::record(Auth::user(), $work, 'deleted', [
            'status' => $work->status,
            'total' => $work->total,
        ], 'Job deleted');
        $work->delete();

        if ($this->shouldReturnJson()) {
            return response()->json([
                'message' => 'Job deleted successfully.',
            ]);
        }

        return redirect()->back()->with('success', 'Job deleted successfully.');
    }

    public function addExtraQuote(Request $request, Work $work)
    {
        $user = Auth::user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();
        if (!$user || $user->id !== $accountId) {
            abort(403);
        }

        if ($work->user_id !== $accountId) {
            abort(403);
        }

        app(UsageLimitService::class)->enforceLimit($user, 'quotes');

        $itemType = $user->company_type === 'products'
            ? Product::ITEM_TYPE_PRODUCT
            : Product::ITEM_TYPE_SERVICE;

        $validated = $request->validate([
            'job_title' => 'required|string|max:255',
            'status' => ['nullable', Rule::in(['draft', 'sent', 'accepted', 'declined'])],
            'product' => 'required|array|min:1',
            'product.*.id' => [
                'required',
                Rule::exists('products', 'id')
                    ->where('user_id', $accountId)
                    ->where('item_type', $itemType),
            ],
            'product.*.quantity' => 'required|integer|min:1',
            'product.*.price' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            'messages' => 'nullable|string',
            'taxes' => 'nullable|array',
            'taxes.*' => ['integer', Rule::exists('taxes', 'id')],
        ]);

        $productIds = collect($validated['product'])->pluck('id')->map(fn($id) => (int) $id)->unique()->values();
        $productMap = $productIds->isNotEmpty()
            ? Product::byUser($accountId)
                ->where('item_type', $itemType)
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id')
            : collect();

        $items = collect($validated['product'])->map(function ($product) use ($productMap) {
            $quantity = (int) $product['quantity'];
            $price = (float) $product['price'];
            $model = $productMap->get((int) $product['id']);
            return [
                'id' => (int) $product['id'],
                'quantity' => $quantity,
                'price' => $price,
                'total' => round($quantity * $price, 2),
                'description' => $product['description'] ?? $model?->description,
            ];
        });

        $subtotal = $items->sum('total');
        $selectedTaxes = Tax::whereIn('id', $validated['taxes'] ?? [])->get();
        $taxLines = $selectedTaxes->map(function ($tax) use ($subtotal) {
            $amount = round($subtotal * ((float) $tax->rate / 100), 2);
            return [
                'tax_id' => $tax->id,
                'rate' => (float) $tax->rate,
                'amount' => $amount,
            ];
        });
        $taxTotal = $taxLines->sum('amount');
        $total = round($subtotal + $taxTotal, 2);

        $quote = null;
        DB::transaction(function () use ($work, $validated, $items, $subtotal, $total, $taxLines, &$quote) {
            $quote = Quote::create([
                'user_id' => $work->user_id,
                'customer_id' => $work->customer_id,
                'property_id' => $work->quote?->property_id,
                'job_title' => $validated['job_title'],
                'subtotal' => $subtotal,
                'total' => $total,
                'notes' => $validated['notes'] ?? null,
                'messages' => $validated['messages'] ?? null,
                'initial_deposit' => 0,
                'status' => $validated['status'] ?? 'accepted',
                'is_fixed' => false,
                'parent_id' => $work->quote_id,
                'work_id' => $work->id,
                'signed_at' => now(),
                'accepted_at' => now(),
            ]);

            $pivotData = $items->mapWithKeys(function ($item) {
                return [
                    $item['id'] => [
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
                        'total' => $item['total'],
                        'description' => $item['description'],
                    ],
                ];
            });
            $quote->products()->sync($pivotData);

            if ($taxLines->isNotEmpty()) {
                $quote->taxes()->createMany($taxLines->toArray());
            }

            $lineItems = QuoteProduct::where('quote_id', $quote->id)
                ->with('product')
                ->orderBy('id')
                ->get();

            foreach ($lineItems as $index => $item) {
                WorkChecklistItem::firstOrCreate(
                    [
                        'work_id' => $work->id,
                        'quote_product_id' => $item->id,
                    ],
                    [
                        'quote_id' => $quote->id,
                        'title' => $item->product?->name ?? 'Line item',
                        'description' => $item->description ?: $item->product?->description,
                        'status' => 'pending',
                        'sort_order' => $index,
                    ]
                );
            }
        });

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Extra quote created.',
                'quote' => $quote?->load(['products', 'taxes', 'customer']),
            ], 201);
        }

        return redirect()->route('customer.quote.edit', $quote)->with('success', 'Extra quote created.');
    }

    private function applyQuoteSnapshotToWork(Work $work): void
    {
        if (!$work->quote_id) {
            return;
        }

        $work->loadMissing('quote.products');
        if (!$work->quote) {
            return;
        }

        $work->setRelation('products', $work->quote->products);
        $work->job_title = $work->quote->job_title;
        $work->instructions = $work->quote->notes ?: ($work->quote->messages ?: '');
        $work->subtotal = $work->quote->subtotal;
        $work->total = $work->quote->total;
    }

    private function autoScheduleTasksForWork(Work $work, ?User $actor): void
    {
        $customer = $work->relationLoaded('customer')
            ? $work->customer
            : Customer::query()->find($work->customer_id);

        if (!$customer) {
            return;
        }

        if ($work->status !== Work::STATUS_SCHEDULED) {
            return;
        }

        $scheduleService = app(WorkScheduleService::class);
        $pendingDates = $scheduleService->pendingDateStrings($work);
        if (!$pendingDates) {
            return;
        }

        if ($actor) {
            app(UsageLimitService::class)->enforceLimit($actor, 'tasks', count($pendingDates));
        }

        $scheduleService->generateTasksForDates($work, $pendingDates, $actor?->id);
    }

}
