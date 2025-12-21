<?php

namespace App\Http\Controllers;

use App\Models\Tax;
use Inertia\Inertia;
use App\Models\Quote;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Work;
use App\Models\ActivityLog;
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

        $baseQuery = Quote::query()
            ->filter($filters)
            ->byUser($userId);

        $sort = in_array($filters['sort'] ?? null, ['created_at', 'total', 'status', 'number', 'job_title'], true)
            ? $filters['sort']
            : 'created_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $quotes = (clone $baseQuery)
            ->with(['customer', 'property'])
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

        return Inertia::render('Quote/Create', [
            'lastQuotesNumber' => $this->generateNextNumber(
                $customer->quotes()->latest('created_at')->value('number')
            ),
            'customer' => $customer,
            'products' => Product::all(),
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

        return Inertia::render('Quote/Create', [
            'quote' => $quote->load('products', 'taxes'),
            'products' => Product::all(),
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
        $validated = $request->validate([
            'job_title' => 'required|string',
            'property_id' => 'nullable|integer',
            'customer_id' => ['required', Rule::exists('customers', 'id')],
            'status' => ['nullable', Rule::in(['draft', 'sent', 'accepted', 'declined'])],
            'product' => 'required|array|min:1',
            'product.*.id' => [
                'required',
                Rule::exists('products', 'id')->where('user_id', Auth::id()),
            ],
            'product.*.quantity' => 'required|integer|min:1',
            'product.*.price' => 'required|numeric|min:0',
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

        $items = collect($validated['product'])->map(function ($product) {
            $quantity = (int) $product['quantity'];
            $price = (float) $product['price'];
            return [
                'id' => (int) $product['id'],
                'quantity' => $quantity,
                'price' => $price,
                'total' => round($quantity * $price, 2),
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

        return redirect()->route('customer.show', $customer)->with('success', 'Quote created successfully!');
    }

    public function update(Request $request, Quote $quote)
    {
        $validated = $request->validate([
            'job_title' => 'required|string',
            'property_id' => 'nullable|integer',
            'customer_id' => ['required', Rule::exists('customers', 'id')],
            'status' => ['nullable', Rule::in(['draft', 'sent', 'accepted', 'declined'])],
            'product' => 'required|array|min:1',
            'product.*.id' => [
                'required',
                Rule::exists('products', 'id')->where('user_id', Auth::id()),
            ],
            'product.*.quantity' => 'required|integer|min:1',
            'product.*.price' => 'required|numeric|min:0',
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

        $items = collect($validated['product'])->map(function ($product) {
            $quantity = (int) $product['quantity'];
            $price = (float) $product['price'];
            return [
                'id' => (int) $product['id'],
                'quantity' => $quantity,
                'price' => $price,
                'total' => round($quantity * $price, 2),
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

        return redirect()->route('customer.show', $quote->customer)->with('success', 'Quote updated successfully!');
    }

    public function show(Quote $quote)
    {
        $this->authorize('show', $quote);

        return Inertia::render('Quote/Show', [
            'quote' =>$quote->load([
                'products',
                'taxes.tax',
                'customer',
                'property',
                'customer.properties',
            ]),
            'products' => Product::all(),
            'taxes' => Tax::all(),
        ]);
    }

    public function destroy(Quote $quote)
    {
        $this->authorize('destroy', $quote);

        ActivityLog::record(Auth::user(), $quote, 'deleted', [
            'status' => $quote->status,
            'total' => $quote->total,
        ], 'Quote deleted');
        $quote->delete();

        return redirect()->route('customer.show', $quote->customer)->with('success', 'Quote deleted successfully!');
    }

    public function convertToWork(Quote $quote)
    {
        $this->authorize('edit', $quote);

        $quote->load(['products', 'customer']);

        $existingWork = Work::where('quote_id', $quote->id)->first();
        if ($existingWork) {
            return redirect()->route('work.edit', $existingWork)->with('success', 'Job already created for this quote.');
        }

        $work = DB::transaction(function () use ($quote) {
            $work = Work::create([
                'user_id' => Auth::id(),
                'customer_id' => $quote->customer_id,
                'quote_id' => $quote->id,
                'job_title' => $quote->job_title,
                'instructions' => $quote->notes ?: ($quote->messages ?: ''),
                'start_date' => now()->toDateString(),
                'status' => 'scheduled',
                'subtotal' => $quote->subtotal,
                'total' => $quote->total,
            ]);

            if ($quote->products->isNotEmpty()) {
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
                $work->products()->sync($pivotData);
            }

            if (in_array($quote->status, ['draft', 'sent'], true)) {
                $quote->update(['status' => 'accepted']);
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
}
