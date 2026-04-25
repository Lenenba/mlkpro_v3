<?php

namespace App\Http\Controllers;

use App\Enums\CustomerClientType;
use App\Http\Requests\CustomerRequest;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\SavedSegment;
use App\Models\User;
use App\Notifications\InviteUserNotification;
use App\Queries\Customers\BuildCustomerDetailViewData;
use App\Queries\Customers\CustomerReadSelects;
use App\Services\CompanyFeatureService;
use App\Services\Customers\CustomerBulkAudienceBridgeService;
use App\Services\Customers\CustomerBulkContactService;
use App\Support\BulkActions\BulkActionRegistry;
use App\Support\CRM\SalesActivityTaxonomy;
use App\Support\Database\UserSelects;
use App\Support\NotificationDispatcher;
use App\Utils\FileHandler;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
            'status',
            'created_from',
            'created_to',
            'sort',
            'direction',
        ]);
        $filters['per_page'] = $this->resolveDataTablePerPage($request);
        $user = $request->user();
        if (! $user) {
            abort(403);
        }
        [$accountOwner, $accountId] = $this->resolveCustomerAccount($user);
        $canEdit = $user->id === $accountId;
        $canManageSavedSegments = (int) $user->id === (int) $user->accountOwnerId();
        $campaignsFeatureEnabled = app(CompanyFeatureService::class)->hasFeature($accountOwner, 'campaigns');

        $baseQuery = Customer::query()
            ->filter($filters)
            ->byUser($accountId);

        $sort = in_array($filters['sort'] ?? null, ['company_name', 'first_name', 'created_at', 'quotes_count', 'works_count'], true)
            ? $filters['sort']
            : 'created_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        // Fetch customers with pagination
        $customers = (clone $baseQuery)
            ->with(['properties'])
            ->withCount([
                'quotes as quotes_count' => fn ($query) => $query->where('user_id', $accountId),
                'works as works_count' => fn ($query) => $query->where('user_id', $accountId),
                'invoices as invoices_count' => fn ($query) => $query->where('user_id', $accountId),
            ])
            ->orderBy($sort, $direction)
            ->paginate((int) $filters['per_page'])
            ->withQueryString();

        $totalCount = (clone $baseQuery)->count();
        $recentThreshold = now()->subDays(30);
        $newCount = (clone $baseQuery)
            ->whereDate('created_at', '>=', $recentThreshold)
            ->count();
        $withQuotes = (clone $baseQuery)
            ->whereHas('quotes', fn ($query) => $query->where('user_id', $accountId))
            ->count();
        $withWorks = (clone $baseQuery)
            ->whereHas('works', fn ($query) => $query->where('user_id', $accountId))
            ->count();
        $activeCount = (clone $baseQuery)
            ->where(function ($query) use ($accountId, $recentThreshold) {
                $query->whereHas('quotes', function ($sub) use ($accountId, $recentThreshold) {
                    $sub->where('user_id', $accountId)
                        ->where('created_at', '>=', $recentThreshold);
                })->orWhereHas('works', function ($sub) use ($accountId, $recentThreshold) {
                    $sub->where('user_id', $accountId)
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
                'quotes as quotes_count' => fn ($query) => $query->where('user_id', $accountId),
                'works as works_count' => fn ($query) => $query->where('user_id', $accountId),
                'invoices as invoices_count' => fn ($query) => $query->where('user_id', $accountId),
            ])
            ->orderByDesc('quotes_count')
            ->orderByDesc('works_count')
            ->limit(5)
            ->get(['id', 'company_name', 'first_name', 'last_name', 'logo', 'header_image']);

        $savedSegments = $canManageSavedSegments
            ? SavedSegment::query()
                ->byUser($accountOwner->id)
                ->where('module', SavedSegment::MODULE_CUSTOMER)
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

        // Pass data to Inertia view
        return $this->inertiaOrJson('Customer/Index', [
            'customers' => $customers,
            'filters' => $filters,
            'count' => $totalCount,
            'stats' => $stats,
            'topCustomers' => $topCustomers,
            'canEdit' => $canEdit,
            'savedSegments' => $savedSegments,
            'canManageSavedSegments' => $canManageSavedSegments,
            'bulkActions' => app(BulkActionRegistry::class)->definitionFor('customer', [
                'can_edit' => $canEdit,
                'contact_enabled' => $canEdit && $campaignsFeatureEnabled,
                'campaign_bridge_enabled' => $campaignsFeatureEnabled,
            ]),
        ]);
    }

    /**
     * Return customers and properties for quick-create dialogs.
     */
    public function options(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }
        [, $accountId] = $this->resolveCustomerAccount($user);
        $scope = $this->normalizeCustomerOptionScope((string) $request->query('scope', 'full'));
        $search = trim((string) $request->query('search', ''));
        $limit = (int) $request->query('limit', 0);

        $customersQuery = Customer::byUser($accountId)
            ->orderBy('company_name')
            ->orderBy('last_name');

        if ($search !== '') {
            $customersQuery->where(function ($query) use ($search) {
                $query->where('company_name', 'like', '%'.$search.'%')
                    ->orWhere('first_name', 'like', '%'.$search.'%')
                    ->orWhere('last_name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%');
            });
        }

        if ($this->customerOptionScopeIncludesProperties($scope)) {
            $propertyColumns = $this->customerPropertyOptionColumns($scope);
            $customersQuery->with(['properties' => function ($query) use ($propertyColumns) {
                $query->select($propertyColumns)
                    ->orderByDesc('is_default')
                    ->orderBy('id');
            }]);
        }

        if ($limit > 0) {
            $customersQuery->limit(max(1, min($limit, 50)));
        }

        $customers = $customersQuery->get($this->customerOptionColumns($scope));
        $payload = $customers
            ->map(fn (Customer $customer) => $this->mapCustomerOptionPayload($customer, $scope))
            ->values();

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
        $user = Auth::user();
        if ($user) {
            $this->resolveCustomerAccount($user);
        }

        return $this->inertiaOrJson('Customer/Create', [
            'customer' => new Customer([
                'client_type' => CustomerClientType::default()->value,
            ]),
        ]);
    }

    /**
     * Show the form for editing the specified customer.
     *
     * @return \Inertia\Response
     */
    public function edit(Customer $customer, ?Request $request)
    {
        $this->authorize('update', $customer);

        $user = $request?->user();
        if ($user) {
            $this->resolveCustomerAccount($user);
        }

        $customer->load('properties');

        return $this->inertiaOrJson('Customer/Create', [
            'customer' => $customer,
        ]);
    }

    /**
     * Display the specified customer.
     *
     * @return \Inertia\Response
     */
    public function show(Customer $customer, ?Request $request)
    {
        $this->authorize('view', $customer);

        $user = $request?->user();
        $accountOwner = null;
        $accountId = $user?->id ?? Auth::id();
        if ($user) {
            [$accountOwner, $accountId] = $this->resolveCustomerAccount($user);
        }
        $canEdit = $user ? $user->can('update', $customer) : false;
        $filters = $request?->only([
            'name',
            'status',
            'month',
        ]) ?? [];

        $props = app(BuildCustomerDetailViewData::class)->execute(
            $customer,
            $user,
            $accountOwner,
            $accountId,
            $canEdit,
            $filters
        );

        $props['canLogSalesActivity'] = true;
        $props['salesActivityQuickActions'] = array_values(SalesActivityTaxonomy::quickActions());
        $props['salesActivityManualActions'] = SalesActivityTaxonomy::manualActionDefinitions();

        return $this->inertiaOrJson('Customer/Show', $props);
    }

    public function updateNotes(Request $request, Customer $customer)
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'description' => 'nullable|string|max:255',
        ]);

        $customer->update([
            'description' => $validated['description'] ?? null,
        ]);

        ActivityLog::record($request->user(), $customer, 'notes_updated', [
            'description' => $customer->description,
        ], 'Customer notes updated');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Notes updated.',
                'customer' => [
                    'id' => $customer->id,
                    'description' => $customer->description,
                ],
            ]);
        }

        return redirect()->back()->with('success', 'Notes updated.');
    }

    public function updateTags(Request $request, Customer $customer)
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'tags' => 'nullable|string|max:500',
        ]);

        $raw = $validated['tags'] ?? '';
        $tags = array_filter(array_map('trim', explode(',', $raw)));
        $tags = array_values(array_unique($tags));
        $tags = array_map(fn ($tag) => mb_substr($tag, 0, 30), $tags);
        $tags = array_slice($tags, 0, 20);

        $customer->update([
            'tags' => $tags ?: null,
        ]);

        ActivityLog::record($request->user(), $customer, 'tags_updated', [
            'tags' => $tags,
        ], 'Customer tags updated');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Tags updated.',
                'customer' => [
                    'id' => $customer->id,
                    'tags' => $customer->tags,
                ],
            ]);
        }

        return redirect()->back()->with('success', 'Tags updated.');
    }

    public function updateAutoValidation(Request $request, Customer $customer)
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'auto_accept_quotes' => 'nullable|boolean',
            'auto_validate_jobs' => 'nullable|boolean',
            'auto_validate_tasks' => 'nullable|boolean',
            'auto_validate_invoices' => 'nullable|boolean',
        ]);

        $customer->update([
            'auto_accept_quotes' => (bool) ($validated['auto_accept_quotes'] ?? $customer->auto_accept_quotes ?? false),
            'auto_validate_jobs' => (bool) ($validated['auto_validate_jobs'] ?? $customer->auto_validate_jobs ?? false),
            'auto_validate_tasks' => (bool) ($validated['auto_validate_tasks'] ?? $customer->auto_validate_tasks ?? false),
            'auto_validate_invoices' => (bool) ($validated['auto_validate_invoices'] ?? $customer->auto_validate_invoices ?? false),
        ]);

        ActivityLog::record($request->user(), $customer, 'auto_validation_updated', [
            'auto_accept_quotes' => $customer->auto_accept_quotes,
            'auto_validate_jobs' => $customer->auto_validate_jobs,
            'auto_validate_tasks' => $customer->auto_validate_tasks,
            'auto_validate_invoices' => $customer->auto_validate_invoices,
        ], 'Customer auto validation updated');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Auto validation preferences updated.',
                'customer' => [
                    'id' => $customer->id,
                    'auto_accept_quotes' => $customer->auto_accept_quotes,
                    'auto_validate_jobs' => $customer->auto_validate_jobs,
                    'auto_validate_tasks' => $customer->auto_validate_tasks,
                    'auto_validate_invoices' => $customer->auto_validate_invoices,
                ],
            ]);
        }

        return redirect()->back()->with('success', 'Auto validation preferences updated.');
    }

    /**
     * Store a newly created customer in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(CustomerRequest $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }
        [$accountOwner, $accountId] = $this->resolveCustomerAccount($user);

        $validated = $this->normalizeCustomerPayload($request->validated());
        $defaultLogo = config('icon_presets.defaults.company', Customer::DEFAULT_LOGO_PATH);
        $logoPath = FileHandler::handleImageUpload('customers', $request, 'logo', $defaultLogo);
        if (! empty($validated['logo_icon']) && ! $request->hasFile('logo')) {
            $logoPath = $validated['logo_icon'];
        }
        $validated['logo'] = $logoPath;
        unset($validated['logo_icon']);
        $validated['header_image'] = FileHandler::handleImageUpload('customers', $request, 'header_image', 'customers/customer.png');
        $portalAccess = array_key_exists('portal_access', $validated)
            ? (bool) $validated['portal_access']
            : true;

        $customerData = Arr::except($validated, ['temporary_password']);
        $customerData['portal_access'] = $portalAccess;
        $customerData['user_id'] = $accountId;

        [$customer, $portalUser] = DB::transaction(function () use ($validated, $customerData, $portalAccess) {
            $portalUser = null;
            if ($portalAccess) {
                $roleId = $this->resolveClientRoleId();
                $portalUser = $this->createPortalUser($validated, $roleId);
                $customerData['portal_user_id'] = $portalUser->id;
            }

            $customer = Customer::create($customerData);

            return [$customer, $portalUser];
        });

        // Add properties if provided
        if (! empty($validated['properties'])) {
            $propertyPayload = $validated['properties'];
            $propertyPayload['type'] = $propertyPayload['type'] ?? 'physical';
            if (! empty($propertyPayload['city'])) {
                $propertyPayload['is_default'] = true;
                $customer->properties()->create($propertyPayload);
            }
        }

        ActivityLog::record($request->user(), $customer, 'created', [
            'company_name' => $customer->company_name,
            'email' => $customer->email,
        ], 'Customer created');

        $inviteQueued = true;
        if ($portalUser) {
            $token = Password::broker()->createToken($portalUser);
            $inviteQueued = NotificationDispatcher::send($portalUser, new InviteUserNotification(
                $token,
                $accountOwner?->company_name ?: config('app.name'),
                $accountOwner?->company_logo_url,
                'client'
            ), [
                'customer_id' => $customer->id,
            ]);
        }

        if ($this->shouldReturnJson($request)) {
            if (! $inviteQueued) {
                return response()->json([
                    'message' => 'Customer created, but the invite email could not be sent.',
                    'warning' => true,
                    'customer' => $customer->load('properties'),
                    'portal_user_id' => $portalUser?->id,
                ], 201);
            }

            return response()->json([
                'message' => 'Customer created successfully.',
                'customer' => $customer->load('properties'),
                'portal_user_id' => $portalUser?->id,
            ], 201);
        }

        if (! $inviteQueued) {
            return redirect()->route('customer.index')->with('warning', 'Customer created, but the invite email could not be sent.');
        }

        return redirect()->route('customer.index')->with('success', 'Customer created successfully.');
    }

    /**
     * Store a customer from quick-create dialogs.
     */
    public function storeQuick(CustomerRequest $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }
        [$accountOwner, $accountId] = $this->resolveCustomerAccount($user, true);

        $validated = $this->normalizeCustomerPayload($request->validated());
        $defaultLogo = config('icon_presets.defaults.company', Customer::DEFAULT_LOGO_PATH);
        $logoPath = FileHandler::handleImageUpload('customers', $request, 'logo', $defaultLogo);
        if (! empty($validated['logo_icon']) && ! $request->hasFile('logo')) {
            $logoPath = $validated['logo_icon'];
        }
        $validated['logo'] = $logoPath;
        unset($validated['logo_icon']);
        $validated['header_image'] = FileHandler::handleImageUpload('customers', $request, 'header_image', 'customers/customer.png');
        $portalAccess = array_key_exists('portal_access', $validated)
            ? (bool) $validated['portal_access']
            : true;

        $customerData = Arr::except($validated, ['temporary_password']);
        $customerData['portal_access'] = $portalAccess;
        $customerData['user_id'] = $accountId;

        [$customer, $portalUser] = DB::transaction(function () use ($validated, $customerData, $portalAccess) {
            $portalUser = null;
            if ($portalAccess) {
                $roleId = $this->resolveClientRoleId();
                $portalUser = $this->createPortalUser($validated, $roleId);
                $customerData['portal_user_id'] = $portalUser->id;
            }

            $customer = Customer::create($customerData);

            return [$customer, $portalUser];
        });

        $property = null;
        if (! empty($validated['properties'])) {
            $propertyPayload = $validated['properties'];
            $propertyPayload['type'] = $propertyPayload['type'] ?? 'physical';
            if (! empty($propertyPayload['city'])) {
                $propertyPayload['is_default'] = true;
                $property = $customer->properties()->create($propertyPayload);
            }
        }

        ActivityLog::record($request->user(), $customer, 'created', [
            'company_name' => $customer->company_name,
            'email' => $customer->email,
        ], 'Customer created');

        $inviteQueued = true;
        if ($portalUser) {
            $token = Password::broker()->createToken($portalUser);
            $inviteQueued = NotificationDispatcher::send($portalUser, new InviteUserNotification(
                $token,
                $accountOwner?->company_name ?: config('app.name'),
                $accountOwner?->company_logo_url,
                'client'
            ), [
                'customer_id' => $customer->id,
            ]);
        }

        $propertyData = [];
        if ($property) {
            $propertyData[] = [
                'id' => $property->id,
                'type' => $property->type,
                'is_default' => (bool) $property->is_default,
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
                'client_type' => $customer->client_type,
                'company_name' => $customer->company_name,
                'registration_number' => $customer->registration_number,
                'industry' => $customer->industry,
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'number' => $customer->number,
                'logo' => $customer->logo,
                'logo_url' => $customer->logo_url,
                'discount_rate' => $customer->discount_rate,
            ],
            'property_id' => $property?->id,
            'properties' => $propertyData,
            'invite_sent' => $inviteQueued,
            'warning' => $inviteQueued ? null : 'Invite email could not be sent.',
        ], 201);
    }

    /**
     * Update the specified customer in the database.
     */
    public function update(CustomerRequest $request, Customer $customer)
    {
        $this->authorize('update', $customer);

        $validated = $this->normalizeCustomerPayload($request->validated(), $customer);
        $defaultLogo = config('icon_presets.defaults.company', Customer::DEFAULT_LOGO_PATH);
        $validated['logo'] = FileHandler::handleImageUpload(
            'customers',
            $request,
            'logo',
            $defaultLogo,
            $customer->logo
        );
        if (! empty($validated['logo_icon']) && ! $request->hasFile('logo')) {
            if (
                $customer->logo
                && $customer->logo !== $validated['logo_icon']
                && ! str_starts_with($customer->logo, '/')
                && ! str_starts_with($customer->logo, 'http://')
                && ! str_starts_with($customer->logo, 'https://')
                && $customer->logo !== $defaultLogo
            ) {
                FileHandler::deleteFile($customer->logo, $defaultLogo);
            }
            $validated['logo'] = $validated['logo_icon'];
        }
        unset($validated['logo_icon']);
        $validated['header_image'] = FileHandler::handleImageUpload(
            'customers',
            $request,
            'header_image',
            'customers/customer.png',
            $customer->header_image
        );

        $customer->update($validated);

        if (! empty($validated['properties'])) {
            $propertyPayload = $validated['properties'];
            $propertyPayload['type'] = $propertyPayload['type'] ?? 'physical';

            if (! empty($propertyPayload['city'])) {
                DB::transaction(function () use ($customer, $propertyPayload) {
                    $defaultProperty = $customer->properties()->where('is_default', true)->first();
                    if ($defaultProperty) {
                        $defaultProperty->update($propertyPayload);

                        return;
                    }

                    $fallbackProperty = $customer->properties()->reorder('id')->first();
                    if ($fallbackProperty) {
                        $customer->properties()->update(['is_default' => false]);
                        $fallbackProperty->update(array_merge($propertyPayload, ['is_default' => true]));

                        return;
                    }

                    $propertyPayload['is_default'] = true;
                    $customer->properties()->create($propertyPayload);
                });
            }
        }

        ActivityLog::record($request->user(), $customer, 'updated', [
            'company_name' => $customer->company_name,
            'email' => $customer->email,
        ], 'Customer updated');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Customer updated successfully.',
                'customer' => $customer->load('properties'),
            ]);
        }

        return redirect()->route('customer.index')->with('success', 'Customer updated successfully.');
    }

    /**
     * Remove the specified customer from the database.
     */
    public function destroy(Request $request, Customer $customer)
    {
        $this->authorize('delete', $customer);

        FileHandler::deleteFile($customer->logo, Customer::DEFAULT_LOGO_PATH);
        FileHandler::deleteFile($customer->header_image, 'customers/customer.png');
        ActivityLog::record($request->user(), $customer, 'deleted', [
            'company_name' => $customer->company_name,
            'email' => $customer->email,
        ], 'Customer deleted');
        $customer->delete();

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Customer deleted successfully.',
            ]);
        }

        return redirect()->route('customer.index')->with('success', 'Customer deleted successfully.');
    }

    /**
     * Bulk actions on customers.
     */
    public function bulk(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }
        [, $accountId] = $this->resolveCustomerAccount($user);

        $data = $request->validate([
            'action' => 'required|in:portal_enable,portal_disable,archive,restore,delete',
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $customers = Customer::query()
            ->byUser($accountId)
            ->whereIn('id', $data['ids'])
            ->get();
        $processedIds = $customers->pluck('id')->all();

        if ($data['action'] === 'portal_enable') {
            foreach ($customers as $customer) {
                $this->authorize('update', $customer);
            }
            Customer::query()
                ->byUser($accountId)
                ->whereIn('id', $data['ids'])
                ->update(['portal_access' => true]);

            if ($this->shouldReturnJson($request)) {
                return response()->json($this->bulkActionResult(
                    'Portal access enabled.',
                    $data['ids'],
                    $processedIds
                ));
            }

            return redirect()->back()->with('success', 'Portal access enabled.');
        }

        if ($data['action'] === 'portal_disable') {
            foreach ($customers as $customer) {
                $this->authorize('update', $customer);
            }
            Customer::query()
                ->byUser($accountId)
                ->whereIn('id', $data['ids'])
                ->update(['portal_access' => false]);

            if ($this->shouldReturnJson($request)) {
                return response()->json($this->bulkActionResult(
                    'Portal access disabled.',
                    $data['ids'],
                    $processedIds
                ));
            }

            return redirect()->back()->with('success', 'Portal access disabled.');
        }

        if ($data['action'] === 'archive') {
            foreach ($customers as $customer) {
                $this->authorize('update', $customer);
            }
            Customer::query()
                ->byUser($accountId)
                ->whereIn('id', $data['ids'])
                ->update(['is_active' => false]);

            if ($this->shouldReturnJson($request)) {
                return response()->json($this->bulkActionResult(
                    'Customers archived.',
                    $data['ids'],
                    $processedIds
                ));
            }

            return redirect()->back()->with('success', 'Customers archived.');
        }

        if ($data['action'] === 'restore') {
            foreach ($customers as $customer) {
                $this->authorize('update', $customer);
            }
            Customer::query()
                ->byUser($accountId)
                ->whereIn('id', $data['ids'])
                ->update(['is_active' => true]);

            if ($this->shouldReturnJson($request)) {
                return response()->json($this->bulkActionResult(
                    'Customers restored.',
                    $data['ids'],
                    $processedIds
                ));
            }

            return redirect()->back()->with('success', 'Customers restored.');
        }

        foreach ($customers as $customer) {
            $this->authorize('delete', $customer);
            FileHandler::deleteFile($customer->logo, Customer::DEFAULT_LOGO_PATH);
            FileHandler::deleteFile($customer->header_image, 'customers/customer.png');
            $customer->delete();
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json($this->bulkActionResult(
                'Customers deleted.',
                $data['ids'],
                $processedIds
            ));
        }

        return redirect()->back()->with('success', 'Customers deleted.');
    }

    public function previewBulkContact(Request $request, CustomerBulkContactService $bulkContactService)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        [$accountOwner, $accountId] = $this->resolveCustomerAccount($user);
        $this->ensureBulkContactAuthorized($user, $accountId);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'channel' => ['required', Rule::in(CustomerBulkContactService::allowedChannels())],
            'objective' => ['required', Rule::in(CustomerBulkContactService::allowedObjectives())],
            'offer_id' => ['nullable', 'integer'],
        ]);

        $customers = $this->resolveBulkContactCustomers($accountId, $validated['ids']);
        if ($customers->isEmpty()) {
            return response()->json([
                'message' => 'No eligible customers were found in the current account.',
                'errors' => [
                    'ids' => ['No eligible customers were found in the current account.'],
                ],
            ], 422);
        }

        foreach ($customers as $customer) {
            $this->authorize('view', $customer);
        }

        $offer = $this->resolveBulkContactOffer($accountId, $validated['offer_id'] ?? null);

        return response()->json(
            $bulkContactService->preview(
                $accountOwner,
                $customers,
                (string) $validated['channel'],
                (string) $validated['objective'],
                $offer,
                $user->preferredLocale()
            )
        );
    }

    public function sendBulkContact(Request $request, CustomerBulkContactService $bulkContactService)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        [$accountOwner, $accountId] = $this->resolveCustomerAccount($user);
        $this->ensureBulkContactAuthorized($user, $accountId);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'channel' => ['required', Rule::in(CustomerBulkContactService::allowedChannels())],
            'objective' => ['required', Rule::in(CustomerBulkContactService::allowedObjectives())],
            'offer_id' => [
                Rule::requiredIf(
                    fn () => strtolower((string) ($request->input('objective') ?? '')) === CustomerBulkContactService::OBJECTIVE_PROMOTION
                ),
                'nullable',
                'integer',
            ],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:5000'],
        ]);

        if (
            strtolower((string) $validated['objective']) !== CustomerBulkContactService::OBJECTIVE_PAYMENT_FOLLOWUP
            && trim((string) ($validated['body'] ?? '')) === ''
        ) {
            return response()->json([
                'message' => 'Message body is required for this outreach objective.',
                'errors' => [
                    'body' => ['Message body is required for this outreach objective.'],
                ],
            ], 422);
        }

        $customers = $this->resolveBulkContactCustomers($accountId, $validated['ids']);
        if ($customers->isEmpty()) {
            return response()->json([
                'message' => 'No eligible customers were found in the current account.',
                'errors' => [
                    'ids' => ['No eligible customers were found in the current account.'],
                ],
            ], 422);
        }

        foreach ($customers as $customer) {
            $this->authorize('view', $customer);
        }

        $offer = $this->resolveBulkContactOffer($accountId, $validated['offer_id'] ?? null);
        if (
            strtolower((string) $validated['objective']) === CustomerBulkContactService::OBJECTIVE_PROMOTION
            && ! $offer
        ) {
            return response()->json([
                'message' => 'A valid product or service is required for this promotion.',
                'errors' => [
                    'offer_id' => ['A valid product or service is required for this promotion.'],
                ],
            ], 422);
        }

        return response()->json(
            $bulkContactService->send(
                $accountOwner,
                $user,
                $customers,
                [
                    'channel' => (string) $validated['channel'],
                    'objective' => (string) $validated['objective'],
                    'subject' => (string) ($validated['subject'] ?? ''),
                    'body' => (string) ($validated['body'] ?? ''),
                ],
                $offer,
                $user->preferredLocale()
            )
        );
    }

    public function saveBulkContactSelection(
        Request $request,
        CustomerBulkAudienceBridgeService $bulkAudienceBridgeService
    ) {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        [$accountOwner, $accountId] = $this->resolveCustomerAccount($user);
        $this->ensureBulkContactAuthorized($user, $accountId);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'objective' => ['nullable', Rule::in(CustomerBulkContactService::allowedObjectives())],
            'mailing_list_id' => ['nullable', 'integer'],
            'mailing_list_name' => ['nullable', 'string', 'max:255'],
        ]);

        $customers = $this->resolveBulkContactCustomers($accountId, $validated['ids']);
        if ($customers->isEmpty()) {
            return response()->json([
                'message' => 'No eligible customers were found in the current account.',
                'errors' => [
                    'ids' => ['No eligible customers were found in the current account.'],
                ],
            ], 422);
        }

        foreach ($customers as $customer) {
            $this->authorize('view', $customer);
        }

        return response()->json(
            $bulkAudienceBridgeService->saveSelection(
                $accountOwner,
                $user,
                $customers,
                $validated
            )
        );
    }

    public function openBulkContactCampaign(
        Request $request,
        CustomerBulkAudienceBridgeService $bulkAudienceBridgeService
    ) {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        [$accountOwner, $accountId] = $this->resolveCustomerAccount($user);
        $this->ensureBulkContactAuthorized($user, $accountId);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'objective' => ['nullable', Rule::in(CustomerBulkContactService::allowedObjectives())],
            'mailing_list_id' => ['nullable', 'integer'],
            'mailing_list_name' => ['nullable', 'string', 'max:255'],
        ]);

        $customers = $this->resolveBulkContactCustomers($accountId, $validated['ids']);
        if ($customers->isEmpty()) {
            return response()->json([
                'message' => 'No eligible customers were found in the current account.',
                'errors' => [
                    'ids' => ['No eligible customers were found in the current account.'],
                ],
            ], 422);
        }

        foreach ($customers as $customer) {
            $this->authorize('view', $customer);
        }

        return response()->json(
            $bulkAudienceBridgeService->prepareCampaignHandoff(
                $accountOwner,
                $user,
                $customers,
                $validated
            )
        );
    }

    private function resolveCustomerAccount(User $user, bool $allowPos = false): array
    {
        $ownerId = $user->accountOwnerId();
        $owner = $ownerId === $user->id
            ? $user
            : User::query()
                ->select(UserSelects::companySummary())
                ->find($ownerId);

        if (! $owner) {
            abort(403);
        }

        $accountId = $user->id;
        if ($owner->company_type === 'products') {
            if ($user->id !== $owner->id) {
                $membership = $user->relationLoaded('teamMembership')
                    ? $user->teamMembership
                    : $user->teamMembership()->first();
                $canManage = $membership?->hasPermission('sales.manage') ?? false;
                $canPos = $allowPos ? ($membership?->hasPermission('sales.pos') ?? false) : false;
                if (! $membership || (! $canManage && ! $canPos)) {
                    abort(403);
                }
            }
            $accountId = $owner->id;
        }

        return [$owner, $accountId];
    }

    private function ensureBulkContactAuthorized(User $user, int $accountId): void
    {
        if ($user->id !== $accountId) {
            abort(403);
        }
    }

    private function resolveBulkContactCustomers(int $accountId, array $ids)
    {
        $normalizedIds = collect($ids)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        return Customer::query()
            ->byUser($accountId)
            ->whereIn('id', $normalizedIds)
            ->get();
    }

    private function resolveBulkContactOffer(int $accountId, mixed $offerId): ?Product
    {
        $normalizedId = (int) $offerId;
        if ($normalizedId < 1) {
            return null;
        }

        return Product::query()
            ->where('user_id', $accountId)
            ->whereKey($normalizedId)
            ->with('category')
            ->first();
    }

    private function resolveClientRoleId(): int
    {
        return Role::firstOrCreate(
            ['name' => 'client'],
            ['description' => 'Access to client functionalities']
        )->id;
    }

    private function normalizeCustomerPayload(array $validated, ?Customer $customer = null): array
    {
        $companyName = array_key_exists('company_name', $validated)
            ? trim((string) ($validated['company_name'] ?? ''))
            : trim((string) ($customer?->company_name ?? ''));
        $registrationNumber = array_key_exists('registration_number', $validated)
            ? trim((string) ($validated['registration_number'] ?? ''))
            : trim((string) ($customer?->registration_number ?? ''));
        $industry = array_key_exists('industry', $validated)
            ? trim((string) ($validated['industry'] ?? ''))
            : trim((string) ($customer?->industry ?? ''));

        $validated['client_type'] = CustomerClientType::infer(
            $validated['client_type'] ?? $customer?->client_type,
            $companyName
        )->value;
        $validated['company_name'] = $companyName !== '' ? $companyName : null;
        $validated['registration_number'] = $registrationNumber !== '' ? $registrationNumber : null;
        $validated['industry'] = $industry !== '' ? $industry : null;

        if ($validated['client_type'] !== CustomerClientType::COMPANY->value) {
            $validated['company_name'] = null;
            $validated['registration_number'] = null;
            $validated['industry'] = null;
        }

        $validated['billing_same_as_physical'] = array_key_exists('billing_same_as_physical', $validated)
            ? (bool) $validated['billing_same_as_physical']
            : false;
        $validated['portal_access'] = array_key_exists('portal_access', $validated)
            ? (bool) $validated['portal_access']
            : ($customer ? false : true);
        $validated['billing_mode'] = $validated['billing_mode'] ?? $customer?->billing_mode ?? 'end_of_job';
        $validated['billing_grouping'] = $validated['billing_grouping'] ?? $customer?->billing_grouping ?? 'single';
        $validated['discount_rate'] = array_key_exists('discount_rate', $validated) && is_numeric($validated['discount_rate'])
            ? (float) $validated['discount_rate']
            : 0.0;
        $validated['auto_accept_quotes'] = array_key_exists('auto_accept_quotes', $validated)
            ? (bool) $validated['auto_accept_quotes']
            : false;
        $validated['auto_validate_jobs'] = array_key_exists('auto_validate_jobs', $validated)
            ? (bool) $validated['auto_validate_jobs']
            : false;
        $validated['auto_validate_tasks'] = array_key_exists('auto_validate_tasks', $validated)
            ? (bool) $validated['auto_validate_tasks']
            : false;
        $validated['auto_validate_invoices'] = array_key_exists('auto_validate_invoices', $validated)
            ? (bool) $validated['auto_validate_invoices']
            : false;

        return $validated;
    }

    private function normalizeCustomerOptionScope(string $scope): string
    {
        return in_array($scope, ['full', 'request', 'quote', 'audience'], true)
            ? $scope
            : 'full';
    }

    private function customerOptionScopeIncludesProperties(string $scope): bool
    {
        return in_array($scope, ['full', 'quote'], true);
    }

    private function customerOptionColumns(string $scope): array
    {
        return CustomerReadSelects::optionCustomerColumns($scope);
    }

    private function customerPropertyOptionColumns(string $scope): array
    {
        return CustomerReadSelects::optionPropertyColumns($scope);
    }

    private function mapCustomerOptionPayload(Customer $customer, string $scope): array
    {
        $payload = [
            'id' => $customer->id,
            'client_type' => $customer->client_type,
            'company_name' => $customer->company_name,
            'registration_number' => $customer->registration_number,
            'industry' => $customer->industry,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
        ];

        if (in_array($scope, ['request', 'quote', 'full'], true)) {
            $payload['number'] = $customer->number;
            $payload['logo'] = $customer->logo;
            $payload['logo_url'] = $customer->logo_url;
        }

        if (! $this->customerOptionScopeIncludesProperties($scope)) {
            return $payload;
        }

        $properties = $customer->properties ?? collect();
        $payload['properties'] = $properties
            ->map(function ($property) use ($scope) {
                if ($scope === 'quote') {
                    return [
                        'id' => $property->id,
                        'is_default' => (bool) $property->is_default,
                        'street1' => $property->street1,
                        'city' => $property->city,
                    ];
                }

                return [
                    'id' => $property->id,
                    'type' => $property->type,
                    'is_default' => (bool) $property->is_default,
                    'street1' => $property->street1,
                    'street2' => $property->street2,
                    'city' => $property->city,
                    'state' => $property->state,
                    'zip' => $property->zip,
                    'country' => $property->country,
                ];
            })
            ->values();

        return $payload;
    }

    private function createPortalUser(array $validated, int $roleId): User
    {
        $name = trim(($validated['first_name'] ?? '').' '.($validated['last_name'] ?? ''));
        if ($name === '') {
            $name = $validated['company_name'] ?? $validated['email'];
        }

        return User::create([
            'name' => $name ?: $validated['email'],
            'email' => $validated['email'],
            'password' => Hash::make(Str::random(32)),
            'role_id' => $roleId,
            'phone_number' => $validated['phone'] ?? null,
            'company_name' => ($validated['client_type'] ?? null) === CustomerClientType::COMPANY->value
                ? ($validated['company_name'] ?? null)
                : null,
            'must_change_password' => true,
        ]);
    }
}
