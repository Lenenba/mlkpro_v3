<?php

namespace App\Http\Controllers;

use App\Actions\Works\UpdateWorkStatusAction;
use App\Http\Requests\WorkRequest;
use App\Http\Requests\Works\StoreExtraQuoteRequest;
use App\Http\Requests\Works\UpdateWorkStatusRequest;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Quote;
use App\Models\QuoteProduct;
use App\Models\Task;
use App\Models\Tax;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkChecklistItem;
use App\Queries\Works\BuildWorkIndexData;
use App\Services\UsageLimitService;
use App\Services\WorkBillingService;
use App\Services\WorkScheduleService;
use App\Support\SequentialNumber;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class WorkController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the works.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();
        $isAccountOwner = ($user?->id ?? Auth::id()) === $accountId;
        $props = app(BuildWorkIndexData::class)->execute($user, $accountId, $isAccountOwner, $request);

        return $this->inertiaOrJson('Work/Index', $props);
    }

    /**
     * Show the form for creating a new work.
     */
    public function create(Customer $customer)
    {
        $user = Auth::user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();
        if (! $user || $user->id !== $accountId) {
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
        $creatorId = $user?->id ?? $accountId;

        return $this->inertiaOrJson('Work/Create', [
            'lastWorkNumber' => SequentialNumber::generateNext($customer->works->last()->number ?? null),
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
            'checklistItems' => fn ($query) => $query->orderBy('sort_order'),
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
        if (! $user || $user->id !== $accountId) {
            abort(403);
        }

        app(UsageLimitService::class)->enforceLimit($user, 'jobs');

        $itemType = $user->company_type === 'products'
            ? Product::ITEM_TYPE_PRODUCT
            : Product::ITEM_TYPE_SERVICE;

        $validated = $request->validated();
        $customer = Customer::byUser($accountId)->with(['works'])->findOrFail($validated['customer_id']);
        $validated['instructions'] = $validated['instructions'] ?? '';

        $validated['start_date'] = ! empty($validated['start_date'])
            ? Carbon::parse($validated['start_date'])->toDateString()
            : null;
        $validated['end_date'] = ! empty($validated['end_date'])
            ? Carbon::parse($validated['end_date'])->toDateString()
            : null;

        $validated['start_time'] = ! empty($validated['start_time'])
            ? Carbon::parse($validated['start_time'])->format('H:i:s')
            : null;
        $validated['end_time'] = ! empty($validated['end_time'])
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
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();
        $creatorId = $user?->id ?? $accountId;

        $lines = collect();
        if (array_key_exists('products', $validated)) {
            $lines = collect($this->buildWorkItems(
                $validated['products'] ?? [],
                $itemType,
                $accountId,
                $accountId,
                $creatorId
            ));

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
                            'description' => $line['description'] ?? null,
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

        $conflictCount = $this->autoScheduleTasksForWork($work, $user);
        $warning = $conflictCount > 0
            ? "{$conflictCount} task(s) were left unassigned because the selected team members were busy."
            : null;

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Job created successfully!',
                'work' => $work->load(['customer', 'products', 'teamMembers']),
                'warning' => $warning,
            ], 201);
        }

        return redirect()
            ->route('customer.show', $customer)
            ->with([
                'success' => 'Job created successfully!',
                'warning' => $warning,
            ]);
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
            $creatorId = $user?->id ?? $accountId;
            $lines = collect($this->buildWorkItems(
                $validated['products'] ?? [],
                $itemType,
                $accountId,
                $accountId,
                $creatorId
            ));

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
            if (! array_key_exists($field, $validated)) {
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
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
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
                            'description' => $line['description'] ?? null,
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

        $conflictCount = $this->autoScheduleTasksForWork($work, $user);
        $warning = $conflictCount > 0
            ? "{$conflictCount} task(s) were left unassigned because the selected team members were busy."
            : null;

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Job updated successfully.',
                'work' => $work->fresh(['customer', 'products', 'teamMembers']),
                'warning' => $warning,
            ]);
        }

        return redirect()
            ->back()
            ->with([
                'success' => 'Job updated successfully.',
                'warning' => $warning,
            ]);
    }

    public function updateStatus(UpdateWorkStatusRequest $request, Work $work, WorkBillingService $billingService, UpdateWorkStatusAction $updateWorkStatus)
    {
        $user = Auth::user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();
        if (! $user || $work->user_id !== $accountId) {
            abort(403);
        }

        $work = $updateWorkStatus->execute(
            $work,
            (string) $request->validated('status'),
            $user,
            $billingService
        );

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
        if (! $user || $user->id !== $accountId) {
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

    public function addExtraQuote(StoreExtraQuoteRequest $request, Work $work)
    {
        $user = Auth::user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();
        if (! $user || $user->id !== $accountId) {
            abort(403);
        }

        if ($work->user_id !== $accountId) {
            abort(403);
        }

        app(UsageLimitService::class)->enforceLimit($user, 'quotes');

        $itemType = $user->company_type === 'products'
            ? Product::ITEM_TYPE_PRODUCT
            : Product::ITEM_TYPE_SERVICE;

        $validated = $request->validated();

        $productIds = collect($validated['product'])->pluck('id')->map(fn ($id) => (int) $id)->unique()->values();
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

    private function buildWorkItems(array $lines, string $itemType, int $userId, int $accountId, int $creatorId): array
    {
        $lines = collect($lines);
        $productIds = $lines->pluck('id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $productMap = $productIds->isNotEmpty()
            ? Product::byUser($userId)
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id')
            : collect();

        return $lines->map(function (array $line) use ($productMap, $itemType, $userId, $accountId, $creatorId) {
            $quantity = (int) ($line['quantity'] ?? 1);
            $price = (float) ($line['price'] ?? 0);
            $description = $line['description'] ?? null;
            $sourceDetails = $this->normalizeSourceDetails($line['source_details'] ?? null);
            $productId = isset($line['id']) && $line['id'] !== null ? (int) $line['id'] : null;
            $lineItemType = $line['item_type'] ?? $itemType;

            if (! $productId) {
                $name = trim((string) ($line['name'] ?? ''));
                if ($name === '') {
                    return null;
                }

                $product = $this->createProductFromLine($userId, $accountId, $creatorId, $lineItemType, $line, $sourceDetails);
                $productId = $product->id;
                if (! $description) {
                    $description = $product->description;
                }
            } else {
                $model = $productMap->get($productId);
                if (! $model) {
                    return null;
                }
                $lineItemType = $model?->item_type ?? $lineItemType;
                if (! $description) {
                    $description = $model?->description;
                }
                $price = $price > 0 ? $price : (float) $model->price;
            }

            return [
                'product_id' => $productId,
                'quantity' => $quantity,
                'price' => $price,
                'total' => round($quantity * $price, 2),
                'description' => $description,
            ];
        })->filter()->values()->all();
    }

    private function normalizeSourceDetails($details): ?array
    {
        if (! $details) {
            return null;
        }

        if (is_string($details)) {
            $decoded = json_decode($details, true);

            return is_array($decoded) ? $decoded : null;
        }

        if (is_object($details)) {
            $details = json_decode(json_encode($details), true);
        }

        return is_array($details) ? $details : null;
    }

    private function createProductFromLine(
        int $userId,
        int $accountId,
        int $creatorId,
        string $itemType,
        array $line,
        ?array $sourceDetails
    ): Product {
        $name = trim((string) ($line['name'] ?? ''));
        $query = Product::byUser($userId)
            ->where('item_type', $itemType)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)]);

        $existing = $query->first();
        if ($existing) {
            return $existing;
        }

        $category = $this->resolveCategory($accountId, $creatorId, $itemType);

        $selected = $sourceDetails['selected_source'] ?? null;
        $best = $sourceDetails['best_source'] ?? null;
        $source = is_array($selected) ? $selected : (is_array($best) ? $best : null);
        $supplierName = is_array($source) ? ($source['name'] ?? null) : null;
        $imageUrl = is_array($source) ? ($source['image_url'] ?? null) : null;
        $sourcePrice = is_array($source) && isset($source['price']) ? (float) $source['price'] : null;

        $price = (float) ($line['price'] ?? 0);
        $costPrice = $sourcePrice ?? $price;
        $marginPercent = 0.0;
        if ($price > 0 && $costPrice > 0) {
            $marginPercent = round((($price - $costPrice) / $price) * 100, 2);
        }

        $description = $line['description'] ?? null;
        if (! $description && is_array($source)) {
            $description = $source['title'] ?? null;
        }

        return Product::create([
            'user_id' => $userId,
            'name' => $name ?: 'Job line',
            'description' => $description ?: 'Auto-generated from job line.',
            'category_id' => $category->id,
            'price' => $price,
            'cost_price' => $costPrice,
            'margin_percent' => $marginPercent,
            'unit' => $line['unit'] ?? null,
            'supplier_name' => $supplierName,
            'stock' => 0,
            'minimum_stock' => 0,
            'is_active' => true,
            'item_type' => $itemType,
            'image' => $imageUrl,
        ]);
    }

    private function resolveCategory(int $accountId, int $creatorId, string $itemType): ProductCategory
    {
        $name = $itemType === 'product' ? 'Products' : 'Services';

        return ProductCategory::resolveForAccount($accountId, $creatorId, $name);
    }

    private function applyQuoteSnapshotToWork(Work $work): void
    {
        if (! $work->quote_id) {
            return;
        }

        $work->loadMissing('quote.products');
        if (! $work->quote) {
            return;
        }

        $work->setRelation('products', $work->quote->products);
        $work->job_title = $work->quote->job_title;
        $work->instructions = $work->quote->notes ?: ($work->quote->messages ?: '');
        $work->subtotal = $work->quote->subtotal;
        $work->total = $work->quote->total;
    }

    private function autoScheduleTasksForWork(Work $work, ?User $actor): int
    {
        $customer = $work->relationLoaded('customer')
            ? $work->customer
            : Customer::query()->find($work->customer_id);

        if (! $customer) {
            return 0;
        }

        if ($work->status !== Work::STATUS_SCHEDULED) {
            return 0;
        }

        $scheduleService = app(WorkScheduleService::class);
        $pendingDates = $scheduleService->pendingDateStrings($work);
        if (! $pendingDates) {
            return 0;
        }

        if ($actor) {
            app(UsageLimitService::class)->enforceLimit($actor, 'tasks', count($pendingDates));
        }

        $conflicts = [];
        $scheduleService->generateTasksForDates($work, $pendingDates, $actor?->id, null, null, $conflicts);

        return count($conflicts);
    }
}
