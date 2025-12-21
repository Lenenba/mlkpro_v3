<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Work;
use App\Models\Product;
use App\Models\Customer;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use App\Http\Requests\WorkRequest;
use Illuminate\Support\Facades\Auth;
use App\Traits\GeneratesSequentialNumber;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;

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

        $userId = Auth::id();

        $baseQuery = Work::query()
            ->filter($filters)
            ->byUser($userId);

        $sort = in_array($filters['sort'] ?? null, ['start_date', 'status', 'total', 'job_title'], true)
            ? $filters['sort']
            : 'start_date';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $works = (clone $baseQuery)
            ->with(['customer', 'invoice'])
            ->orderBy($sort, $direction)
            ->simplePaginate(10)
            ->withQueryString();

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'scheduled' => (clone $baseQuery)->where('status', 'scheduled')->count(),
            'in_progress' => (clone $baseQuery)->where('status', 'in_progress')->count(),
            'completed' => (clone $baseQuery)->where('status', 'completed')->count(),
            'cancelled' => (clone $baseQuery)->where('status', 'cancelled')->count(),
        ];

        $customers = Customer::byUser($userId)
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'first_name', 'last_name']);

        return inertia('Work/Index', [
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
        if ($customer->user_id !== Auth::id()) {
            abort(403);
        }

        $works = Work::where('user_id', Auth::id())->latest()->get();

        return inertia('Work/Create', [
            'lastWorkNumber' => $this->generateNextNumber($customer->works->last()->number ?? null),
            'works' => $works,
            'customer' => $customer->load('properties'),
            'products' => Product::byUser(Auth::id())->get(),
        ]);
    }

    /**
     * Display the specified work.
     */
    public function show($id)
    {
        $work = Work::with(['customer', 'invoice', 'products'])->findOrFail($id);

        $this->authorize('view', $work);

        return inertia('Work/Show', [
            'work' => $work,
            'customer' => $work->customer,
        ]);
    }

    /**
     * Store a newly created work in storage.
     */
    public function store(WorkRequest $request)
    {
        $validated = $request->validated();
        $customer = Customer::byUser(Auth::id())->with(['works'])->findOrFail($validated['customer_id']);
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

        $validated['user_id'] = Auth::id();
        $validated['status'] = $validated['status'] ?? 'scheduled';

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
                $productMap = Product::byUser(Auth::id())
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

        $work = DB::transaction(function () use ($customer, $validated, $lines) {
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

            return $work;
        });

        ActivityLog::record(Auth::user(), $work, 'created', [
            'status' => $work->status,
            'total' => $work->total,
        ], 'Job created');

        return redirect()->route('customer.show', $customer)->with('success', 'Job created successfully!');
    }

    /**
     * Show the form for editing the specified work.
     */
    public function edit(int $work_id, ?Request $request)
    {
        $work = Work::byUser(Auth::id())
            ->with(['customer', 'invoice', 'products', 'ratings'])
            ->findOrFail($work_id);
        $this->authorize('edit', $work);

        $filters = $request->only(['category_id', 'name', 'stock']);
        $workProducts = $work->products()->with('category')->get() ?: [];

        $productsQuery = Product::mostRecent()->filter($filters)->with(['category', 'works']);
        $products = $productsQuery->simplePaginate(8)->withQueryString();

        $customer = Customer::with(['works'])
            ->byUser(Auth::id())
            ->findOrFail($work->customer_id);

        return inertia('Work/Create', [
            'work' => $work,
            'lastWorkNumber' => $work->number,
            'customer' => $customer,
            'filters' => $filters,
            'workProducts' => $workProducts,
            'products' => $products,
            'works' => Work::byUser(Auth::id())->latest()->get(),
        ]);
    }

    /**
     * Update the specified work in storage.
     */
    public function update(WorkRequest $request, $id)
    {
        $work = Work::byUser(Auth::id())->findOrFail($id);

        $validated = $request->validated();
        $previousStatus = $work->status;
        $validated['instructions'] = $validated['instructions'] ?? $work->instructions ?? '';

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
            $productMap = Product::byUser(Auth::id())
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
        $validated['status'] = $validated['status'] ?? $work->status ?? 'scheduled';

        DB::transaction(function () use ($work, $validated, $lines) {
            $work->update($validated);

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

        return redirect()->back()->with('success', 'Job updated successfully.');
    }

    /**
     * Remove the specified work from storage.
     */
    public function destroy($id)
    {
        $work = Work::byUser(Auth::id())->findOrFail($id);
        ActivityLog::record(Auth::user(), $work, 'deleted', [
            'status' => $work->status,
            'total' => $work->total,
        ], 'Job deleted');
        $work->delete();

        return redirect()->back()->with('success', 'Job deleted successfully.');
    }
}
