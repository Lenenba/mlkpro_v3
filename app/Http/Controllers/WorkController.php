<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Work;
use App\Models\Product;
use App\Models\Customer;
use App\Models\ProductWork;
use Illuminate\Http\Request;
use App\Http\Requests\WorkRequest;
use Illuminate\Support\Facades\Auth;
use App\Traits\GeneratesSequentialNumber;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class WorkController extends Controller
{
    use AuthorizesRequests, GeneratesSequentialNumber;

    /**
     * Display a listing of the works.
     */
    public function index()
    {
        $works = Work::with(['customer', 'products', 'ratings'])
            ->byUser(Auth::user()->id)
            ->orderBy('work_date', 'desc')
            ->paginate(10);

            return inertia('Work/Index', [
                'works' => $works->map(function ($work) {
                    return [
                        'id' => $work->id,
                        'title' => $work->number, // Remplacez par le champ utilisé pour le titre
                        'date' => Carbon::parse($work->work_date)->format('Y-m-d'), // Format ISO
                    ];
                }),
            ]);
    }

    /**
     * Show the form for creating a new work.
     */
    public function create(Customer $customer)
    {
        $works = Work::where('user_id', Auth::user()->id)->latest()->get();
        return inertia('Work/Create', [
            'lastWorkNumber' => $this->generateNextNumber($customer->works->last()->number ?? null),
            'works' => $works,
            'customer' => $customer->load('properties'),
            'products' => Product::all(),]);
    }

    /**
     * Display the specified work.
     */
    public function show($id)
    {
        $work = Work::with(['customer', 'invoice', 'products', 'ratings'])->findOrFail($id);

        $this->authorize('view', $work);
        return inertia(
            'Work/Show',
            [
                'work' => $work,
                'customer' => $work->customer
            ]
        );
    }

    /**
     * Store a newly created work in storage.
     */
    public function store(WorkRequest $request)
    {
        $validated = $request->validated();
        $customer = Customer::with(['works'])->findOrFail($validated['customer_id']);
        $validated['user_id'] = Auth::user()->id;
        $work = $customer->works()->create($validated);

        $work->base_cost = $validated['base_cost'];
        $work->cost = $work->base_cost;

        $work->number = 'WORK' . str_pad($work->id, 6, '0', STR_PAD_LEFT);
        $work->is_completed = 0;
        $work->save();

        return redirect()->route('work.edit', [
            'work_id' => $work->id,
            'work' => $work,
            'customer' => $customer
        ])->with('success', 'Work created successfully.');
    }

    /**
     * Show the form for editing the specified work.
     */
    public function edit(int $work_id, ?Request $request)
    {
        // Fetch work with relationships and ensure authorization
        $work = Work::with(['customer', 'invoice', 'products', 'ratings'])->findOrFail($work_id);
        $this->authorize('edit', $work);

        // Mettre à jour le prix du travail
        $this->updateWorkCost($work);

        // Handle request filters for products
        $filters = $request->only(['category_id', 'name', 'stock']);

        // Default empty array for work products
        $workProducts = $work->products()->with('category')->get() ?: [];

        // Improved query for products with eager loading, filters, and pagination
        $productsQuery = Product::mostRecent()->filter($filters)->with(['category', 'works']);
        $products = $productsQuery->simplePaginate(8)->withQueryString();

        // Retrieve the customer by authenticated user, optimizing the query
        $customer = Customer::with(['works'])
            ->byUser(Auth::user()->id)
            ->findOrFail($work->customer_id);



        // Return the data to the Inertia component
        return inertia('Work/Edit', [
            'work' => $work,
            'customer' => $customer,
            'filters' => $filters,
            'workProducts' => $workProducts,
            'products' => $products,
        ]);
    }


    /**
     * Update the specified work in storage.
     */
    public function update(WorkRequest $request, $id)
    {
        $work = Work::findOrFail($id);

        $validated = $request->validated();

        $work->update($validated);

        // Update products if provided
        if ($request->has('products')) {
            ProductWork::where('work_id', $work->id)->delete();

            foreach ($validated['products'] as $product) {
                ProductWork::create([
                    'work_id' => $work->id,
                    'product_id' => $product['product_id'],
                    'quantity_used' => $product['quantity_used'],
                ]);
            }
        }

        return response()->json(['message' => 'Work updated successfully', 'work' => $work]);
    }

    /**
     * Remove the specified work from storage.
     */
    public function destroy($id)
    {
        $work = Work::findOrFail($id);
        $work->delete();

        return response()->json(['message' => 'Work deleted successfully']);
    }

    /**
     * Update the total cost of the work based on the products attached.
     *
     * @param \App\Models\Work $work
     * @return void
     */
    private function updateWorkCost(Work $work)
    {
        // Calculer le coût total des produits ajoutés
        $productsCost = 0;

        foreach ($work->products as $product) {
            $productsCost += $product->price * $product->pivot->quantity_used;
        }

        // Mettre à jour le coût du travail, en ajoutant les produits au prix de base
        $work->cost = $work->base_cost + $productsCost;
        $work->save();
    }
}
