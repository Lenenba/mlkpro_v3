<?php

namespace App\Http\Controllers;

use App\Models\Tax;
use Inertia\Inertia;
use App\Models\Quote;
use App\Models\Product;
use App\Models\Customer;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Traits\GeneratesSequentialNumber;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class QuoteController extends Controller
{
    use AuthorizesRequests, GeneratesSequentialNumber;

    public function create(Customer $customer)
    {
        if($customer->user_id !== Auth::user()->id){
            abort(403);
        }

        return Inertia::render('Quote/Create', [
            'lastQuotesNumber' => $this->generateNextNumber($customer->quotes->last()->number ?? null),
            'customer' => $customer->load('properties'),
            'products' => Product::all(),
            'taxes' => Tax::all(),
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

        $customer = $quote->customer->load([
            'properties' => function ($query) use ($quote) {
                $query->where('id', $quote->property_id);
            }
        ]);

        return Inertia::render('Quote/Create', [
            'quote' => $quote->load('products', 'taxes'),
            'products' => Product::all(),
            'customer' =>  $customer->load('properties'),
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
            'property_id' => 'nullable|exists:properties,id',
            'customer_id' => 'required|exists:customers,id',
            'product' => 'required|array',
            'product.*.id' => 'required|exists:products,id',
            'product.*.quantity' => 'required|integer|min:1',
            'product.*.price' => 'required|numeric|min:0',
            'product.*.total' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            'total' => 'required|numeric|min:0',
            'subtotal' => 'required|numeric|min:0',
            'messages' => 'nullable|string',
            'initial_deposit' => 'nullable|numeric|min:0',
        ]);

        // dd($validated);
        // Récupérer le client
        $customer = Customer::findOrFail($validated['customer_id']);

        // Créer un nouveau devis
        $quote = $customer->quotes()->create([
            'user_id' => Auth::user()->id,
            'property_id' => $validated['property_id'],
            'job_title' => $validated['job_title'],
            'subtotal' => $validated['subtotal'],
            'total' => $validated['total'], // Total sera ajusté avec les taxes
            'notes' => $validated['notes'] ?? null,
            'messages' => $validated['messages'] ?? null,
            'initial_deposit' => $validated['initial_deposit'] ?? 0,
            'status' => 'draft', // Par défaut
            'is_fixed' => false, // Par défaut
        ]);

        $quote->subtotal = $validated['subtotal'];
        $quote->save();

        foreach ($validated['product'] as $product) {
            $quote->products()->attach($product['id'], [
                'quantity' => $product['quantity'],
                'price' => $product['price'],
                'total' => $product['total']
            ]);
        }
        return redirect()->route('customer.show', $customer)->with('success', 'Quote created successfully!');
    }

    public function update(Request $request, Quote $quote){

        $validated = $request->validate([
            'job_title' => 'required|string',
            'property_id' => 'nullable|exists:properties,id',
            'customer_id' => 'required|exists:customers,id',
            'product' => 'required|array',
            'product.*.id' => 'required|exists:products,id',
            'product.*.quantity' => 'required|integer|min:1',
            'product.*.price' => 'required|numeric|min:0',
            'product.*.total' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            'total' => 'required|numeric|min:0',
            'subtotal' => 'required|numeric|min:0',
            'messages' => 'nullable|string',
            'initial_deposit' => 'nullable|numeric|min:0',
        ]);

        $quote->update([
            'job_title' => $validated['job_title'],
            'property_id' => $validated['property_id'],
            'subtotal' => $validated['subtotal'],
            'total' => $validated['total'], // Total sera ajusté avec les taxes
            'notes' => $validated['notes'] ?? null,
            'messages' => $validated['messages'] ?? null,
            'initial_deposit' => $validated['initial_deposit'] ?? 0,
            'status' => 'draft', // Par défaut
            'is_fixed' => false, // Par défaut
        ]);

        $quote->messages = $validated['messages'];
        $quote->subtotal = $validated['subtotal'];
        $quote->save();

        $quote->products()->detach();
        foreach ($validated['product'] as $product) {
            $quote->products()->attach($product['id'], [
                'quantity' => $product['quantity'],
                'price' => $product['price'],
                'total' => $product['total']
            ]);
        }
        return redirect()->route('customer.show', $quote->customer)->with('success', 'Quote updated successfully!');
    }

    public function show(Quote $quote)
    {
        $this->authorize('show', $quote);

        return Inertia::render('Quote/Show', [
            'quote' =>$quote->load([
                'products',
                'taxes',
                'customer',
                'customer.properties',
            ]),
            'products' => Product::all(),
            'taxes' => Tax::all(),
        ]);
    }

    public function destroy(Quote $quote)
    {
        $this->authorize('destroy', $quote);

        $quote->delete();

        return redirect()->route('customer.show', $quote->customer)->with('success', 'Quote deleted successfully!');
    }
}
