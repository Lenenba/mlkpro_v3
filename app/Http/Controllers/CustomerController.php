<?php

namespace App\Http\Controllers;

use App\Models\Work;
use Inertia\Inertia;
use App\Models\Customer;
use App\Utils\FileHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        ]);
        $userId = Auth::user()->id;

        // Fetch customers with pagination
        $customers = Customer::mostRecent()
            ->with(['works' => function ($query) use ($userId) {
                $query->where('user_id', $userId)->with('products');
            }])
            ->filter($filters)
            ->byUser($userId)
            ->simplePaginate(12)
            ->withQueryString();

        // Pass data to Inertia view
        return Inertia::render('Customer/Index', [
            'customers' => $customers,
            'filters' => $filters,
            'count' => Customer::count(),
        ]);
    }

    /**
     * Show the form for creating a new customer.
     *
     * @return \Inertia\Response
     */
    public function create()
    {
        return Inertia::render('Customer/Create', [
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

        // Valider les filtres uniquement si la requête contient des données
        $filters = $request->only([
            'name',
            'status',
            'month',
        ]);

        // Fetch works for the retrieved customers
        $works = Work::with(['products', 'ratings', 'customer'])
            ->byCustomer($customer->id)
            ->byUser(Auth::user()->id)
            ->filter($filters)
            ->latest()
            ->paginate(10) // Paginer avec 10 résultats par page
            ->withQueryString(); // Conserver les paramètres de requête dans l'URL

        return Inertia::render('Customer/Show', [
            'customer' => $customer,
            'properties' => $customer->properties,
            'works' => $works,
            'filters' => $filters,
        ]);
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
        $validated['logo'] = FileHandler::handleImageUpload($request, 'logo', 'customers/corporateHeader.webp');

        $customer = $request->user()->customers()->create($validated);

        $customer->description = $validated['description'];
        $customer->number = 'CUST' . str_pad($customer->id, 6, '0', STR_PAD_LEFT);
        $customer->logo = $validated['logo'];
        $customer->save();

        return redirect()->route('customer.index')->with('success', 'Customer created successfully.');
    }

    /**
     * Update the specified customer in the database.
     */
    public function update(CustomerRequest $request, Customer $customer)
    {
        $this->authorize('update', $customer);

        $validated = $request->validated();
        $validated['logo'] = FileHandler::handleImageUpload($request, 'logo', 'customers/corporateHeader.webp');
        $validated['header_image'] = FileHandler::handleImageUpload($request, 'header_image', 'customers/corporateHeader.webp');

        $customer->header_image = $validated['header_image'];
        $customer->logo = $validated['logo'];
        $customer->update($validated);

        return redirect()->route('customer.index')->with('success', 'customer updated successfully.');
    }

    /**
     * Remove the specified customer from the database.
     */
    public function destroy(Customer $customer)
    {
        $this->authorize('delete', $customer);

        FileHandler::deleteFile($customer->logo, 'customers/corporateHeader.webp');
        FileHandler::deleteFile($customer->header_image, 'customers/corporateHeader.webp');
        $customer->delete();

        return redirect()->route('customer.index')->with('success', 'customer deleted successfully.');
    }
}
