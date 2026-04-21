<?php

namespace App\Http\Controllers;

use App\Actions\Quotes\UpsertQuoteAction;
use App\Http\Requests\Quotes\AcceptQuoteRequest;
use App\Http\Requests\Quotes\StoreQuoteRequest;
use App\Http\Requests\Quotes\UpdateQuoteRequest;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteProduct;
use App\Models\SavedSegment;
use App\Models\Task;
use App\Models\Tax;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkChecklistItem;
use App\Queries\Quotes\BuildQuoteRecoveryIndexData;
use App\Services\TemplateService;
use App\Services\UsageLimitService;
use App\Support\CRM\SalesActivityTaxonomy;
use App\Support\SequentialNumber;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class QuoteController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request, BuildQuoteRecoveryIndexData $quoteRecoveryIndexData)
    {
        $user = Auth::user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        $this->authorize('viewAny', Quote::class);

        $indexData = $quoteRecoveryIndexData->execute($accountId, $request);

        $customers = Customer::byUser($accountId)
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'first_name', 'last_name']);

        $canManageSavedSegments = (int) ($user?->id ?? 0) === (int) $accountId;
        $savedSegments = $canManageSavedSegments
            ? SavedSegment::query()
                ->byUser($accountId)
                ->where('module', SavedSegment::MODULE_QUOTE)
                ->orderByDesc('updated_at')
                ->orderBy('name')
                ->get([
                    'id',
                    'module',
                    'name',
                    'description',
                    'filters',
                    'sort',
                    'search_term',
                    'is_shared',
                    'cached_count',
                    'last_resolved_at',
                    'updated_at',
                ])
            : collect();

        return $this->inertiaOrJson('Quote/Index', [
            'quotes' => $indexData['quotes'],
            'filters' => $indexData['filters'],
            'count' => $indexData['count'],
            'stats' => $indexData['stats'],
            'topQuotes' => $indexData['topQuotes'],
            'customers' => $customers,
            'savedSegments' => $savedSegments,
            'canManageSavedSegments' => $canManageSavedSegments,
        ]);
    }

    public function create(Request $request, Customer $customer)
    {
        $user = $request->user();
        $accountOwnerId = $user?->accountOwnerId() ?? Auth::id();

        $this->authorize('create', Quote::class);

        if ($customer->user_id !== $accountOwnerId) {
            abort(403);
        }

        $customer->load('properties');
        $propertyId = $request->query('property_id');
        if ($propertyId && ! $customer->properties->contains('id', (int) $propertyId)) {
            $propertyId = null;
        }

        $accountOwner = $accountOwnerId === ($user?->id ?? null)
            ? $user
            : User::query()->find($accountOwnerId);

        $itemType = $accountOwner?->company_type === 'products'
            ? Product::ITEM_TYPE_PRODUCT
            : Product::ITEM_TYPE_SERVICE;

        $accountOwner = $accountOwner ?? User::query()->find($accountOwnerId);
        $templateService = app(TemplateService::class);
        $templateDefaults = $templateService->resolveQuoteDefaults($accountOwner);
        $templateExamples = $templateService->resolveQuoteExamples($accountOwner);

        return $this->inertiaOrJson('Quote/Create', [
            'lastQuotesNumber' => SequentialNumber::generateNext(
                $customer->quotes()->latest('created_at')->value('number')
            ),
            'customer' => $customer,
            'taxes' => Tax::all(),
            'selectedPropertyId' => $propertyId ? (int) $propertyId : null,
            'templateDefaults' => $templateDefaults,
            'templateExamples' => $templateExamples,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @return \Inertia\Response
     */
    public function edit(Quote $quote)
    {
        $this->authorize('edit', $quote);

        $customer = $quote->customer->load('properties');

        return $this->inertiaOrJson('Quote/Create', [
            'quote' => $quote->load('products', 'taxes'),
            'customer' => $customer,
            'taxes' => Tax::all(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Models\Customer  $customer
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(StoreQuoteRequest $request, UpsertQuoteAction $upsertQuote)
    {
        $this->authorize('create', Quote::class);

        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        app(UsageLimitService::class)->enforceLimit($user, 'quotes');

        $result = $upsertQuote->execute($request->validated(), $user);
        $quote = $result['quote'];
        $customer = $result['customer'];

        if ($quote) {
            ActivityLog::record($user, $quote, 'created', [
                'status' => $quote->status,
                'total' => $quote->total,
            ], 'Quote created');
        }

        if ($quote && $customer->auto_accept_quotes && $quote->status !== 'declined') {
            $this->autoAcceptQuote($quote);
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Quote created successfully!',
                'quote' => $quote?->fresh(['products', 'taxes', 'customer']),
            ], 201);
        }

        return redirect()->route('customer.show', $customer)->with('success', 'Quote created successfully!');
    }

    public function update(UpdateQuoteRequest $request, Quote $quote, UpsertQuoteAction $upsertQuote)
    {
        $this->authorize('edit', $quote);

        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $result = $upsertQuote->execute($request->validated(), $user, $quote);
        $customer = $result['customer'];
        $previousStatus = $result['previous_status'];

        if ($previousStatus !== $quote->status) {
            ActivityLog::record($user, $quote, 'status_changed', [
                'from' => $previousStatus,
                'to' => $quote->status,
            ], 'Quote status updated');
        }

        ActivityLog::record($user, $quote, 'updated', [
            'total' => $quote->total,
        ], 'Quote updated');

        if ($customer->auto_accept_quotes && $quote->status !== 'declined') {
            $this->autoAcceptQuote($quote);
            $quote->refresh();
        }

        $quote->syncRequestStatusFromQuote();

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Quote updated successfully!',
                'quote' => $quote->fresh(['products', 'taxes', 'customer']),
            ]);
        }

        return redirect()->route('customer.show', $quote->customer)->with('success', 'Quote updated successfully!');
    }

    public function accept(AcceptQuoteRequest $request, Quote $quote)
    {
        $this->authorize('edit', $quote);

        if ($quote->isArchived()) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Archived quotes cannot be accepted.',
                    'errors' => [
                        'status' => ['Archived quotes cannot be accepted.'],
                    ],
                ], 422);
            }

            return redirect()->back()->withErrors([
                'status' => 'Archived quotes cannot be accepted.',
            ]);
        }

        if ($quote->status === 'accepted') {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Quote already accepted.',
                ]);
            }

            return redirect()->back()->with('success', 'Quote already accepted.');
        }

        if ($quote->status === 'declined') {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Quote already declined.',
                    'errors' => [
                        'status' => ['Quote already declined.'],
                    ],
                ], 422);
            }

            return redirect()->back()->withErrors([
                'status' => 'Quote already declined.',
            ]);
        }

        $validated = $request->validated();

        $requiredDeposit = (float) ($quote->initial_deposit ?? 0);
        $depositAmount = (float) ($validated['deposit_amount'] ?? $requiredDeposit);

        if ($requiredDeposit > 0 && $depositAmount < $requiredDeposit) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Validation error.',
                    'errors' => [
                        'deposit_amount' => ['Deposit is below the required amount.'],
                    ],
                ], 422);
            }

            return redirect()->back()->withErrors([
                'deposit_amount' => 'Deposit is below the required amount.',
            ]);
        }

        $existingWork = Work::where('quote_id', $quote->id)->first();
        if (! $existingWork) {
            app(UsageLimitService::class)->enforceLimit(Auth::user(), 'jobs');
        }

        $work = null;
        DB::transaction(function () use ($quote, $validated, $depositAmount, $existingWork, &$work) {
            $work = $existingWork;
            if (! $work) {
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

                if (! $hasDeposit) {
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

        $quote->syncRequestStatusFromQuote();

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Quote accepted and job created.',
                'quote' => $quote->fresh(['customer', 'products', 'taxes']),
                'work' => $work->fresh(['customer', 'products', 'teamMembers']),
            ]);
        }

        return redirect()->route('work.edit', $work)->with('success', 'Quote accepted and job created.');
    }

    public function show(Quote $quote)
    {
        $this->authorize('show', $quote);

        $user = Auth::user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();
        $accountOwner = $accountId === ($user?->id ?? null)
            ? $user
            : User::query()->find($accountId);

        $itemType = $accountOwner?->company_type === 'products'
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

        $activity = ActivityLog::query()
            ->where('subject_type', $quote->getMorphClass())
            ->where('subject_id', $quote->id)
            ->with('user:id,name')
            ->latest()
            ->take(50)
            ->get();

        return $this->inertiaOrJson('Quote/Show', [
            'quote' => $quote,
            'activity' => $activity,
            'products' => Product::byUser($accountId)->where('item_type', $itemType)->get(),
            'taxes' => Tax::all(),
            'canLogSalesActivity' => true,
            'salesActivityQuickActions' => array_values(SalesActivityTaxonomy::quickActions()),
            'salesActivityManualActions' => SalesActivityTaxonomy::manualActionDefinitions(),
        ]);
    }

    public function destroy(Request $request, Quote $quote)
    {
        $this->authorize('destroy', $quote);

        $quote->update(['archived_at' => now()]);

        ActivityLog::record(Auth::user(), $quote, 'archived', [
            'status' => $quote->status,
            'total' => $quote->total,
        ], 'Quote archived');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Quote archived successfully!',
                'quote' => $quote->fresh(),
            ]);
        }

        return redirect()->back()->with('success', 'Quote archived successfully!');
    }

    public function restore(Quote $quote)
    {
        $this->authorize('restore', $quote);

        $quote->update(['archived_at' => null]);

        ActivityLog::record(Auth::user(), $quote, 'restored', [
            'status' => $quote->status,
            'total' => $quote->total,
        ], 'Quote restored');

        if ($this->shouldReturnJson()) {
            return response()->json([
                'message' => 'Quote restored successfully!',
                'quote' => $quote->fresh(),
            ]);
        }

        return redirect()->back()->with('success', 'Quote restored successfully!');
    }

    public function convertToWork(Quote $quote)
    {
        $this->authorize('edit', $quote);

        if ($quote->isArchived()) {
            if ($this->shouldReturnJson()) {
                return response()->json([
                    'message' => 'Archived quotes cannot be converted.',
                    'errors' => [
                        'status' => ['Archived quotes cannot be converted.'],
                    ],
                ], 422);
            }

            return redirect()->back()->withErrors([
                'status' => 'Archived quotes cannot be converted.',
            ]);
        }

        $quote->load(['products', 'customer']);

        $existingWork = Work::where('quote_id', $quote->id)->first();
        if ($existingWork) {
            if ($this->shouldReturnJson()) {
                return response()->json([
                    'message' => 'Job already created for this quote.',
                    'work' => $existingWork,
                ]);
            }

            return redirect()->route('work.edit', $existingWork)->with('success', 'Job already created for this quote.');
        }

        app(UsageLimitService::class)->enforceLimit(Auth::user(), 'jobs');

        $work = DB::transaction(function () use ($quote) {
            $work = Work::create([
                'user_id' => $quote->user_id,
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
            } elseif (! $quote->work_id) {
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

        $quote->syncRequestStatusFromQuote();

        if ($this->shouldReturnJson()) {
            return response()->json([
                'message' => 'Job created from quote.',
                'work' => $work->fresh(['customer', 'products']),
                'quote' => $quote->fresh(),
            ]);
        }

        return redirect()->route('work.edit', $work)->with('success', 'Job created from quote.');
    }

    public function updateRecovery(Request $request, Quote $quote)
    {
        $this->authorize('edit', $quote);

        if (! $this->canManageRecovery($quote)) {
            return $this->recoveryUnavailableResponse($request);
        }

        $validated = $request->validate([
            'next_follow_up_at' => ['nullable', 'date'],
            'mark_followed_up' => ['nullable', 'boolean'],
        ]);

        $markFollowedUp = (bool) ($validated['mark_followed_up'] ?? false);
        $hasFollowUpField = array_key_exists('next_follow_up_at', $validated);

        if (! $markFollowedUp && ! $hasFollowUpField) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'No recovery change provided.',
                    'errors' => [
                        'recovery' => ['No recovery change provided.'],
                    ],
                ], 422);
            }

            return redirect()->back()->withErrors([
                'recovery' => 'No recovery change provided.',
            ]);
        }

        $nextFollowUpAt = $hasFollowUpField && $validated['next_follow_up_at']
            ? Carbon::parse((string) $validated['next_follow_up_at'])
            : null;
        $previousNextFollowUpAt = $quote->next_follow_up_at?->copy();
        $previousFollowUpState = $quote->follow_up_state;
        $previousFollowUpCount = (int) ($quote->follow_up_count ?? 0);

        $updates = [];
        if ($hasFollowUpField) {
            $updates['next_follow_up_at'] = $nextFollowUpAt;
        }

        if ($markFollowedUp) {
            $updates['last_followed_up_at'] = now();
            $updates['follow_up_count'] = (int) ($quote->follow_up_count ?? 0) + 1;
            $updates['next_follow_up_at'] = $nextFollowUpAt;
        }

        $effectiveNextFollowUpAt = array_key_exists('next_follow_up_at', $updates)
            ? $updates['next_follow_up_at']
            : $quote->next_follow_up_at;

        $updates['follow_up_state'] = $effectiveNextFollowUpAt
            ? ($effectiveNextFollowUpAt->lte(now()) ? 'due' : 'scheduled')
            : ($markFollowedUp ? 'completed' : null);

        $quote->update($updates);
        $this->recordQuoteRecoveryActivity(
            $request->user(),
            $quote,
            $previousNextFollowUpAt,
            $previousFollowUpState,
            $previousFollowUpCount,
            $markFollowedUp,
            $hasFollowUpField,
        );

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $markFollowedUp
                    ? 'Quote follow-up completed.'
                    : 'Quote follow-up updated.',
                'quote' => $quote->fresh(['customer']),
            ]);
        }

        return redirect()->back()->with('success', $markFollowedUp
            ? 'Quote follow-up completed.'
            : 'Quote follow-up updated.');
    }

    public function storeRecoveryTask(Request $request, Quote $quote)
    {
        $this->authorize('edit', $quote);

        if (! $this->canManageRecovery($quote)) {
            return $this->recoveryUnavailableResponse($request);
        }

        $validated = $request->validate([
            'due_date' => ['nullable', 'date'],
        ]);

        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        app(UsageLimitService::class)->enforceLimit($user, 'tasks');

        $accountId = (int) ($user->accountOwnerId() ?? $user->id);
        $dueDate = isset($validated['due_date']) && $validated['due_date']
            ? Carbon::parse((string) $validated['due_date'])->toDateString()
            : ($quote->next_follow_up_at?->toDateString() ?? now()->addDay()->toDateString());
        $quoteNumber = $quote->number ?: 'Quote';

        $task = Task::create([
            'account_id' => $accountId,
            'created_by_user_id' => (int) $user->id,
            'customer_id' => $quote->customer_id,
            'work_id' => $quote->work_id,
            'request_id' => $quote->request_id,
            'title' => "Follow up {$quoteNumber}",
            'description' => $quote->job_title
                ? "Recovery follow-up for {$quoteNumber}: {$quote->job_title}"
                : "Recovery follow-up for {$quoteNumber}",
            'status' => 'todo',
            'due_date' => $dueDate,
        ]);

        ActivityLog::record($user, $quote, 'quote_follow_up_task_created', [
            'task_id' => $task->id,
            'task_title' => $task->title,
            'task_due_date' => $task->due_date?->toDateString(),
            'follow_up_state' => $quote->follow_up_state,
            'follow_up_count' => (int) ($quote->follow_up_count ?? 0),
        ], 'Recovery task created from quote');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Recovery task created.',
                'task' => $task->fresh(),
            ], 201);
        }

        return redirect()->back()->with('success', 'Recovery task created.');
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
        if (! $existingWork) {
            app(UsageLimitService::class)->enforceLimit(Auth::user(), 'jobs');
        }

        $work = null;
        DB::transaction(function () use ($quote, $existingWork, &$work) {
            $work = $existingWork;
            if (! $work) {
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

        if (! $existingWork && $work) {
            ActivityLog::record(Auth::user(), $work, 'created', [
                'from_quote_id' => $quote->id,
                'total' => $work->total,
            ], 'Job created from auto-accepted quote');
        }

        $quote->syncRequestStatusFromQuote();

        return $work;
    }

    private function recordQuoteRecoveryActivity(
        ?User $user,
        Quote $quote,
        ?Carbon $previousNextFollowUpAt,
        ?string $previousFollowUpState,
        int $previousFollowUpCount,
        bool $markFollowedUp,
        bool $hasFollowUpField,
    ): void {
        $currentNextFollowUpAt = $quote->next_follow_up_at;
        $nextFollowUpChanged = ! $this->sameCarbonMoment($previousNextFollowUpAt, $currentNextFollowUpAt);
        $properties = [
            'previous_next_follow_up_at' => $previousNextFollowUpAt?->toIso8601String(),
            'next_follow_up_at' => $currentNextFollowUpAt?->toIso8601String(),
            'previous_follow_up_state' => $previousFollowUpState,
            'follow_up_state' => $quote->follow_up_state,
            'previous_follow_up_count' => $previousFollowUpCount,
            'follow_up_count' => (int) ($quote->follow_up_count ?? 0),
            'last_followed_up_at' => $quote->last_followed_up_at?->toIso8601String(),
        ];

        if ($markFollowedUp) {
            ActivityLog::record(
                $user,
                $quote,
                $currentNextFollowUpAt ? 'quote_follow_up_completed_and_rescheduled' : 'quote_follow_up_completed',
                $properties,
                $currentNextFollowUpAt
                    ? 'Quote follow-up completed and rescheduled'
                    : 'Quote follow-up completed',
            );

            return;
        }

        if (! $hasFollowUpField || ! $nextFollowUpChanged) {
            return;
        }

        if ($currentNextFollowUpAt) {
            ActivityLog::record(
                $user,
                $quote,
                'quote_follow_up_scheduled',
                $properties,
                'Quote follow-up scheduled',
            );

            return;
        }

        if ($previousNextFollowUpAt) {
            ActivityLog::record(
                $user,
                $quote,
                'quote_follow_up_cleared',
                $properties,
                'Quote follow-up cleared',
            );
        }
    }

    private function sameCarbonMoment(?Carbon $first, ?Carbon $second): bool
    {
        if ($first === null && $second === null) {
            return true;
        }

        if ($first === null || $second === null) {
            return false;
        }

        return $first->equalTo($second);
    }

    private function canManageRecovery(Quote $quote): bool
    {
        return ! $quote->isArchived()
            && in_array((string) $quote->status, ['draft', 'sent'], true);
    }

    private function recoveryUnavailableResponse(Request $request)
    {
        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Recovery actions are only available on open quotes.',
                'errors' => [
                    'quote' => ['Recovery actions are only available on open quotes.'],
                ],
            ], 422);
        }

        return redirect()->back()->withErrors([
            'quote' => 'Recovery actions are only available on open quotes.',
        ]);
    }
}
