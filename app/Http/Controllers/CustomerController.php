<?php

namespace App\Http\Controllers;

use App\Models\Work;
use Inertia\Inertia;
use App\Models\Customer;
use App\Utils\FileHandler;
use App\Models\ActivityLog;
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
            ])
            ->orderByDesc('quotes_count')
            ->orderByDesc('works_count')
            ->limit(5)
            ->get(['id', 'company_name', 'first_name', 'last_name', 'logo', 'header_image']);

        // Pass data to Inertia view
        return Inertia::render('Customer/Index', [
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
                $query->orderBy('id');
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

        $customer->load(['properties', 'quotes', 'works']);
        return Inertia::render('Customer/Show', [
            'customer' => $customer,
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
        $validated['logo'] = FileHandler::handleImageUpload('customers', $request, 'logo', 'customers/customer.png');
        $validated['header_image'] = FileHandler::handleImageUpload('customers', $request, 'header_image', 'customers/customer.png');
        $customer = $request->user()->customers()->create($validated);

        // Add properties if provided
        if (!empty($validated['properties'])) {
            $customer->properties()->create($validated['properties']);
        }

        ActivityLog::record($request->user(), $customer, 'created', [
            'company_name' => $customer->company_name,
            'email' => $customer->email,
        ], 'Customer created');

        return redirect()->route('customer.index')->with('success', 'Customer created successfully.');
    }

    /**
     * Store a customer from quick-create dialogs.
     */
    public function storeQuick(CustomerRequest $request)
    {
        $validated = $request->validated();

        $customer = $request->user()->customers()->create($validated);

        $property = null;
        if (!empty($validated['properties'])) {
            $propertyPayload = $validated['properties'];
            $propertyPayload['type'] = $propertyPayload['type'] ?? 'physical';
            if (!empty($propertyPayload['city'])) {
                $property = $customer->properties()->create($propertyPayload);
            }
        }

        ActivityLog::record($request->user(), $customer, 'created', [
            'company_name' => $customer->company_name,
            'email' => $customer->email,
        ], 'Customer created');

        $propertyData = [];
        if ($property) {
            $propertyData[] = [
                'id' => $property->id,
                'type' => $property->type,
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

        ActivityLog::record($request->user(), $customer, 'updated', [
            'company_name' => $customer->company_name,
            'email' => $customer->email,
        ], 'Customer updated');

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

        return redirect()->route('customer.index')->with('success', 'Customer deleted successfully.');
    }
}
