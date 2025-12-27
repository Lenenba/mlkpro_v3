<?php

namespace App\Http\Controllers;

use App\Models\Tax;
use Inertia\Inertia;
use App\Models\Quote;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Customer;
use App\Models\Work;
use App\Models\ActivityLog;
use App\Models\QuoteProduct;
use App\Models\Transaction;
use App\Models\WorkChecklistItem;
use App\Services\UsageLimitService;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Traits\GeneratesSequentialNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class QuoteController extends Controller
{
    use AuthorizesRequests, GeneratesSequentialNumber;

    public function index(Request $request)
    {
        $filters = $request->only([
            'search',
            'status',
            'customer_id',
            'total_min',
            'total_max',
            'created_from',
            'created_to',
            'has_deposit',
            'has_tax',
            'sort',
            'direction',
        ]);

        $userId = Auth::id();

        $statusFilter = $filters['status'] ?? null;
        $showArchived = $statusFilter === 'archived';
        $filtersForQuery = $filters;
        if ($showArchived) {
            $filtersForQuery['status'] = null;
        }

        $baseQuery = Quote::query()
            ->filter($filtersForQuery)
            ->when(
                $showArchived,
                fn($query) => $query->byUserWithArchived($userId)->archived(),
                fn($query) => $query->byUser($userId)
            );

        $sort = in_array($filters['sort'] ?? null, ['created_at', 'total', 'status', 'number', 'job_title'], true)
            ? $filters['sort']
            : 'created_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $quotes = (clone $baseQuery)
            ->with(['customer', 'property'])
            ->withAvg('ratings', 'rating')
            ->withCount('ratings')
            ->orderBy($sort, $direction)
            ->simplePaginate(10)
            ->withQueryString();

        $totalCount = (clone $baseQuery)->count();
        $totalValue = (clone $baseQuery)->sum('total');
        $averageValue = $totalCount > 0 ? round($totalValue / $totalCount, 2) : 0;
        $openCount = (clone $baseQuery)->whereIn('status', ['draft', 'sent'])->count();
        $acceptedCount = (clone $baseQuery)->where('status', 'accepted')->count();
        $declinedCount = (clone $baseQuery)->where('status', 'declined')->count();

        $stats = [
            'total' => $totalCount,
            'total_value' => $totalValue,
            'average_value' => $averageValue,
            'open' => $openCount,
            'accepted' => $acceptedCount,
            'declined' => $declinedCount,
        ];

        $topQuotes = (clone $baseQuery)
            ->with('customer')
            ->orderByDesc('total')
            ->limit(5)
            ->get(['id', 'number', 'customer_id', 'status', 'total', 'created_at']);

        $customers = Customer::byUser($userId)
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'first_name', 'last_name']);

        return Inertia::render('Quote/Index', [
            'quotes' => $quotes,
            'filters' => $filters,
            'count' => $totalCount,
            'stats' => $stats,
            'topQuotes' => $topQuotes,
            'customers' => $customers,
        ]);
    }

    public function create(Request $request, Customer $customer)
    {
        if($customer->user_id !== Auth::user()->id){
            abort(403);
        }

        $customer->load('properties');
        $propertyId = $request->query('property_id');
        if ($propertyId && !$customer->properties->contains('id', (int) $propertyId)) {
            $propertyId = null;
        }

        $itemType = Auth::user()?->company_type === 'products'
            ? Product::ITEM_TYPE_PRODUCT
            : Product::ITEM_TYPE_SERVICE;

        return Inertia::render('Quote/Create', [
            'lastQuotesNumber' => $this->generateNextNumber(
                $customer->quotes()->latest('created_at')->value('number')
            ),
            'customer' => $customer,
            'taxes' => Tax::all(),
            'selectedPropertyId' => $propertyId ? (int) $propertyId : null,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     * @param  \App\Models\Quote  $quote
     * @return \Inertia\Response
     */
    public function edit (Quote $quote)
    {
        $this->authorize('edit', $quote);

        $customer = $quote->customer->load('properties');
        $itemType = Auth::user()?->company_type === 'products'
            ? Product::ITEM_TYPE_PRODUCT
            : Product::ITEM_TYPE_SERVICE;

        return Inertia::render('Quote/Create', [
            'quote' => $quote->load('products', 'taxes'),
            'customer' =>  $customer,
            'taxes' => Tax::all(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     * @param  \App\Models\Customer  $customer
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        app(UsageLimitService::class)->enforceLimit($request->user(), 'quotes');

        $itemType = Auth::user()?->company_type === 'products'
            ? Product::ITEM_TYPE_PRODUCT
            : Product::ITEM_TYPE_SERVICE;

        $validated = $request->validate([
            'job_title' => 'required|string',
            'property_id' => 'nullable|integer',
            'customer_id' => ['required', Rule::exists('customers', 'id')],
            'status' => ['nullable', Rule::in(['draft', 'sent', 'accepted', 'declined'])],
            'product' => 'required|array|min:1',
            'product.*.id' => [
                'nullable',
                Rule::exists('products', 'id')
                    ->where('user_id', Auth::id()),
            ],
            'product.*.item_type' => ['nullable', Rule::in([Product::ITEM_TYPE_PRODUCT, Product::ITEM_TYPE_SERVICE])],
            'product.*.name' => 'required_without:product.*.id|string',
            'product.*.description' => 'nullable|string',
            'product.*.quantity' => 'required|integer|min:1',
            'product.*.price' => 'required|numeric|min:0',
            'product.*.source_details' => 'nullable',
            'notes' => 'nullable|string',
            'messages' => 'nullable|string',
            'initial_deposit' => 'nullable|numeric|min:0',
            'taxes' => 'nullable|array',
            'taxes.*' => ['integer', Rule::exists('taxes', 'id')],
        ]);

        $customer = Customer::byUser(Auth::id())->findOrFail($validated['customer_id']);

        $propertyId = $validated['property_id'] ?? null;
        if ($propertyId && !$customer->properties()->whereKey($propertyId)->exists()) {
            return back()->withErrors(['property_id' => 'Invalid property for this customer.']);
        }

        $accountId = $request->user()?->accountOwnerId() ?? Auth::id();
        $creatorId = $request->user()?->id ?? Auth::id();
        $items = collect($this->buildQuoteItems($validated['product'], $itemType, Auth::id(), $accountId, $creatorId));

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
        $deposit = (float) ($validated['initial_deposit'] ?? 0);
        if ($deposit > $total) {
            $deposit = $total;
        }

        $quote = null;
        DB::transaction(function () use (&$quote, $customer, $propertyId, $validated, $items, $subtotal, $total, $deposit, $taxLines) {
            $quote = $customer->quotes()->create([
                'user_id' => Auth::id(),
                'property_id' => $propertyId,
                'job_title' => $validated['job_title'],
                'subtotal' => $subtotal,
                'total' => $total,
                'notes' => $validated['notes'] ?? null,
                'messages' => $validated['messages'] ?? null,
                'initial_deposit' => $deposit,
                'status' => $validated['status'] ?? 'draft',
                'is_fixed' => false,
            ]);

            $pivotData = $items->mapWithKeys(function ($item) {
                return [
                    $item['id'] => [
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
                        'total' => $item['total'],
                        'description' => $item['description'],
                        'source_details' => $item['source_details'] ? json_encode($item['source_details']) : null,
                    ],
                ];
            });
            $quote->products()->sync($pivotData);

            if ($taxLines->isNotEmpty()) {
                $quote->taxes()->createMany($taxLines->toArray());
            }
        });

        if ($quote) {
            ActivityLog::record(Auth::user(), $quote, 'created', [
                'status' => $quote->status,
                'total' => $quote->total,
            ], 'Quote created');
        }

        if ($quote && $customer->auto_accept_quotes && $quote->status !== 'declined') {
            $this->autoAcceptQuote($quote);
        }

        return redirect()->route('customer.show', $customer)->with('success', 'Quote created successfully!');
    }

    public function update(Request $request, Quote $quote)
    {
        $itemType = Auth::user()?->company_type === 'products'
            ? Product::ITEM_TYPE_PRODUCT
            : Product::ITEM_TYPE_SERVICE;

        $validated = $request->validate([
            'job_title' => 'required|string',
            'property_id' => 'nullable|integer',
            'customer_id' => ['required', Rule::exists('customers', 'id')],
            'status' => ['nullable', Rule::in(['draft', 'sent', 'accepted', 'declined'])],
            'product' => 'required|array|min:1',
            'product.*.id' => [
                'nullable',
                Rule::exists('products', 'id')
                    ->where('user_id', Auth::id()),
            ],
            'product.*.item_type' => ['nullable', Rule::in([Product::ITEM_TYPE_PRODUCT, Product::ITEM_TYPE_SERVICE])],
            'product.*.name' => 'required_without:product.*.id|string',
            'product.*.description' => 'nullable|string',
            'product.*.quantity' => 'required|integer|min:1',
            'product.*.price' => 'required|numeric|min:0',
            'product.*.source_details' => 'nullable',
            'notes' => 'nullable|string',
            'messages' => 'nullable|string',
            'initial_deposit' => 'nullable|numeric|min:0',
            'taxes' => 'nullable|array',
            'taxes.*' => ['integer', Rule::exists('taxes', 'id')],
        ]);

        $this->authorize('edit', $quote);

        $customer = Customer::byUser(Auth::id())->findOrFail($validated['customer_id']);
        $propertyId = $validated['property_id'] ?? null;
        if ($propertyId && !$customer->properties()->whereKey($propertyId)->exists()) {
            return back()->withErrors(['property_id' => 'Invalid property for this customer.']);
        }

        $accountId = $request->user()?->accountOwnerId() ?? Auth::id();
        $creatorId = $request->user()?->id ?? Auth::id();
        $items = collect($this->buildQuoteItems($validated['product'], $itemType, Auth::id(), $accountId, $creatorId));

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
        $deposit = (float) ($validated['initial_deposit'] ?? 0);
        if ($deposit > $total) {
            $deposit = $total;
        }

        $previousStatus = $quote->status;
        DB::transaction(function () use ($quote, $customer, $propertyId, $validated, $items, $subtotal, $total, $deposit, $taxLines) {
            $quote->update([
                'customer_id' => $customer->id,
                'job_title' => $validated['job_title'],
                'property_id' => $propertyId,
                'subtotal' => $subtotal,
                'total' => $total,
                'notes' => $validated['notes'] ?? null,
                'messages' => $validated['messages'] ?? null,
                'initial_deposit' => $deposit,
                'status' => $validated['status'] ?? $quote->status ?? 'draft',
                'is_fixed' => false,
            ]);

            $pivotData = $items->mapWithKeys(function ($item) {
                return [
                    $item['id'] => [
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
                        'total' => $item['total'],
                        'description' => $item['description'],
                        'source_details' => $item['source_details'] ? json_encode($item['source_details']) : null,
                    ],
                ];
            });
            $quote->products()->sync($pivotData);

            $quote->taxes()->delete();
            if ($taxLines->isNotEmpty()) {
                $quote->taxes()->createMany($taxLines->toArray());
            }
        });

        if ($previousStatus !== $quote->status) {
            ActivityLog::record(Auth::user(), $quote, 'status_changed', [
                'from' => $previousStatus,
                'to' => $quote->status,
            ], 'Quote status updated');
        }

        ActivityLog::record(Auth::user(), $quote, 'updated', [
            'total' => $quote->total,
        ], 'Quote updated');

        if ($customer->auto_accept_quotes && $quote->status !== 'declined') {
            $this->autoAcceptQuote($quote);
        }

        return redirect()->route('customer.show', $quote->customer)->with('success', 'Quote updated successfully!');
    }

    public function accept(Request $request, Quote $quote)
    {
        $this->authorize('show', $quote);

        if ($quote->isArchived()) {
            return redirect()->back()->withErrors([
                'status' => 'Archived quotes cannot be accepted.',
            ]);
        }

        if ($quote->status === 'accepted') {
            return redirect()->back()->with('success', 'Quote already accepted.');
        }

        if ($quote->status === 'declined') {
            return redirect()->back()->withErrors([
                'status' => 'Quote already declined.',
            ]);
        }

        $validated = $request->validate([
            'deposit_amount' => 'nullable|numeric|min:0',
            'method' => 'nullable|string|max:50',
            'reference' => 'nullable|string|max:120',
            'signed_at' => 'nullable|date',
        ]);

        $requiredDeposit = (float) ($quote->initial_deposit ?? 0);
        $depositAmount = (float) ($validated['deposit_amount'] ?? $requiredDeposit);

        if ($requiredDeposit > 0 && $depositAmount < $requiredDeposit) {
            return redirect()->back()->withErrors([
                'deposit_amount' => 'Deposit is below the required amount.',
            ]);
        }

        $existingWork = Work::where('quote_id', $quote->id)->first();
        if (!$existingWork) {
            app(UsageLimitService::class)->enforceLimit(Auth::user(), 'jobs');
        }

        $work = null;
        DB::transaction(function () use ($quote, $validated, $depositAmount, $existingWork, &$work) {
            $work = $existingWork;
            if (!$work) {
                $work = Work::create([
                    'user_id' => $quote->user_id,
                    'customer_id' => $quote->customer_id,
                    'quote_id' => $quote->id,
                    'job_title' => $quote->job_title,
                    'instructions' => $quote->notes ?: ($quote->messages ?: ''),
                    'status' => Work::STATUS_TO_SCHEDULE,
                    'subtotal' => $quote->subtotal,
                    'total' => $quote->total,
                ]);
            } else {
                $work->update([
                    'job_title' => $quote->job_title,
                    'instructions' => $quote->notes ?: ($quote->messages ?: ''),
                    'subtotal' => $quote->subtotal,
                    'total' => $quote->total,
                ]);
            }

            $quote->update([
                'status' => 'accepted',
                'accepted_at' => now(),
                'signed_at' => $validated['signed_at'] ?? now(),
                'work_id' => $work->id,
            ]);

            if ($depositAmount > 0) {
                $hasDeposit = Transaction::where('quote_id', $quote->id)
                    ->where('type', 'deposit')
                    ->where('status', 'completed')
                    ->exists();

                if (!$hasDeposit) {
                    Transaction::create([
                        'quote_id' => $quote->id,
                        'work_id' => $work->id,
                        'customer_id' => $quote->customer_id,
                        'user_id' => $quote->user_id,
                        'amount' => $depositAmount,
                        'type' => 'deposit',
                        'method' => $validated['method'] ?? null,
                        'status' => 'completed',
                        'reference' => $validated['reference'] ?? null,
                        'paid_at' => now(),
                    ]);
                }
            }

            $this->syncWorkProductsFromQuote($quote, $work);

            $items = QuoteProduct::query()
                ->where('quote_id', $quote->id)
                ->with('product')
                ->orderBy('id')
                ->get();

            foreach ($items as $index => $item) {
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

        return redirect()->route('work.edit', $work)->with('success', 'Quote accepted and job created.');
    }

    public function show(Quote $quote)
    {
        $this->authorize('show', $quote);

        $itemType = Auth::user()?->company_type === 'products'
            ? Product::ITEM_TYPE_PRODUCT
            : Product::ITEM_TYPE_SERVICE;

        $quote->load([
            'products',
            'taxes.tax',
            'customer',
            'property',
            'customer.properties',
            'ratings',
        ])->loadAvg('ratings', 'rating')
            ->loadCount('ratings');

        foreach ($quote->products as $product) {
            $details = $product->pivot?->source_details;
            if (is_string($details)) {
                $decoded = json_decode($details, true);
                $product->pivot->source_details = is_array($decoded) ? $decoded : null;
            }
        }

        return Inertia::render('Quote/Show', [
            'quote' => $quote,
            'products' => Product::byUser(Auth::id())->where('item_type', $itemType)->get(),
            'taxes' => Tax::all(),
        ]);
    }

    public function destroy(Quote $quote)
    {
        $this->authorize('destroy', $quote);

        $quote->update(['archived_at' => now()]);

        ActivityLog::record(Auth::user(), $quote, 'archived', [
            'status' => $quote->status,
            'total' => $quote->total,
        ], 'Quote archived');

        return redirect()->route('customer.show', $quote->customer)->with('success', 'Quote archived successfully!');
    }

    public function restore(Quote $quote)
    {
        $this->authorize('restore', $quote);

        $quote->update(['archived_at' => null]);

        ActivityLog::record(Auth::user(), $quote, 'restored', [
            'status' => $quote->status,
            'total' => $quote->total,
        ], 'Quote restored');

        return redirect()->back()->with('success', 'Quote restored successfully!');
    }

    public function convertToWork(Quote $quote)
    {
        $this->authorize('show', $quote);

        if ($quote->isArchived()) {
            return redirect()->back()->withErrors([
                'status' => 'Archived quotes cannot be converted.',
            ]);
        }

        $quote->load(['products', 'customer']);

        $existingWork = Work::where('quote_id', $quote->id)->first();
        if ($existingWork) {
            return redirect()->route('work.edit', $existingWork)->with('success', 'Job already created for this quote.');
        }

        app(UsageLimitService::class)->enforceLimit(Auth::user(), 'jobs');

        $work = DB::transaction(function () use ($quote) {
            $work = Work::create([
                'user_id' => Auth::id(),
                'customer_id' => $quote->customer_id,
                'quote_id' => $quote->id,
                'job_title' => $quote->job_title,
                'instructions' => $quote->notes ?: ($quote->messages ?: ''),
                'start_date' => now()->toDateString(),
                'status' => Work::STATUS_TO_SCHEDULE,
                'subtotal' => $quote->subtotal,
                'total' => $quote->total,
            ]);

            $this->syncWorkProductsFromQuote($quote, $work);

            if (in_array($quote->status, ['draft', 'sent'], true)) {
                $quote->update([
                    'status' => 'accepted',
                    'accepted_at' => now(),
                    'work_id' => $work->id,
                ]);
            } elseif (!$quote->work_id) {
                $quote->update(['work_id' => $work->id]);
            }

            $items = QuoteProduct::query()
                ->where('quote_id', $quote->id)
                ->with('product')
                ->orderBy('id')
                ->get();

            foreach ($items as $index => $item) {
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

            return $work;
        });

        ActivityLog::record(Auth::user(), $work, 'created', [
            'from_quote_id' => $quote->id,
            'total' => $work->total,
        ], 'Job created from quote');

        ActivityLog::record(Auth::user(), $quote, 'converted', [
            'work_id' => $work->id,
        ], 'Quote converted to job');

        return redirect()->route('work.edit', $work)->with('success', 'Job created from quote.');
    }

    private function buildQuoteItems(array $lines, string $itemType, int $userId, int $accountId, int $creatorId): array
    {
        $lines = collect($lines);
        $productIds = $lines->pluck('id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $productMap = $productIds->isNotEmpty()
            ? Product::byUser($userId)
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id')
            : collect();

        return $lines->map(function (array $line) use ($productMap, $itemType, $userId) {
            $quantity = (int) ($line['quantity'] ?? 1);
            $price = (float) ($line['price'] ?? 0);
            $description = $line['description'] ?? null;
            $sourceDetails = $this->normalizeSourceDetails($line['source_details'] ?? null);
            $productId = isset($line['id']) && $line['id'] !== null ? (int) $line['id'] : null;
            $lineItemType = $line['item_type'] ?? $itemType;

            if (!$productId) {
                $product = $this->createProductFromLine($userId, $accountId, $creatorId, $lineItemType, $line, $sourceDetails);
                $productId = $product->id;
                if (!$description) {
                    $description = $product->description;
                }
            } else {
                $model = $productMap->get($productId);
                $lineItemType = $model?->item_type ?? $lineItemType;
                if (!$description) {
                    $description = $model?->description;
                }
            }

            return [
                'id' => $productId,
                'quantity' => $quantity,
                'price' => $price,
                'total' => round($quantity * $price, 2),
                'description' => $description,
                'source_details' => $sourceDetails,
            ];
        })->values()->all();
    }

    private function normalizeSourceDetails($details): ?array
    {
        if (!$details) {
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
    ): Product
    {
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
        if (!$description && is_array($source)) {
            $description = $source['title'] ?? null;
        }

        return Product::create([
            'user_id' => $userId,
            'name' => $name ?: 'Quote line',
            'description' => $description ?: 'Auto-generated from quote line.',
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

    private function syncWorkProductsFromQuote(Quote $quote, Work $work): void
    {
        $quote->loadMissing('products');

        $pivotData = $quote->products->mapWithKeys(function ($product) use ($quote) {
            return [
                $product->id => [
                    'quote_id' => $quote->id,
                    'quantity' => (int) $product->pivot->quantity,
                    'price' => (float) $product->pivot->price,
                    'description' => $product->pivot->description,
                    'total' => (float) $product->pivot->total,
                ],
            ];
        });

        $work->products()->sync($pivotData->toArray());
    }

    private function autoAcceptQuote(Quote $quote): ?Work
    {
        if ($quote->isArchived() || $quote->status === 'declined') {
            return null;
        }

        $previousStatus = $quote->status;
        $existingWork = Work::where('quote_id', $quote->id)->first();
        if (!$existingWork) {
            app(UsageLimitService::class)->enforceLimit(Auth::user(), 'jobs');
        }

        $work = null;
        DB::transaction(function () use ($quote, $existingWork, &$work) {
            $work = $existingWork;
            if (!$work) {
                $work = Work::create([
                    'user_id' => $quote->user_id,
                    'customer_id' => $quote->customer_id,
                    'quote_id' => $quote->id,
                    'job_title' => $quote->job_title,
                    'instructions' => $quote->notes ?: ($quote->messages ?: ''),
                    'status' => Work::STATUS_TO_SCHEDULE,
                    'subtotal' => $quote->subtotal,
                    'total' => $quote->total,
                ]);
            } else {
                $work->update([
                    'job_title' => $quote->job_title,
                    'instructions' => $quote->notes ?: ($quote->messages ?: ''),
                    'subtotal' => $quote->subtotal,
                    'total' => $quote->total,
                ]);
            }

            $quote->update([
                'status' => 'accepted',
                'accepted_at' => $quote->accepted_at ?? now(),
                'signed_at' => $quote->signed_at ?? now(),
                'work_id' => $work->id,
            ]);

            $this->syncWorkProductsFromQuote($quote, $work);

            $items = QuoteProduct::query()
                ->where('quote_id', $quote->id)
                ->with('product')
                ->orderBy('id')
                ->get();

            foreach ($items as $index => $item) {
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

        if ($previousStatus !== 'accepted') {
            ActivityLog::record(Auth::user(), $quote, 'auto_accepted', [
                'total' => $quote->total,
            ], 'Quote auto-accepted');
        }

        if (!$existingWork && $work) {
            ActivityLog::record(Auth::user(), $work, 'created', [
                'from_quote_id' => $quote->id,
                'total' => $work->total,
            ], 'Job created from auto-accepted quote');
        }

        return $work;
    }
}
