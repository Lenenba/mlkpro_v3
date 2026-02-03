<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceRequest;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\TeamMember;
use App\Services\UsageLimitService;
use App\Services\StripeCatalogService;
use App\Utils\FileHandler;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ServiceController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $this->ensureServiceAccess();

        $filters = $request->only([
            'name',
            'category_id',
            'category_ids',
            'price_min',
            'price_max',
            'created_from',
            'created_to',
            'status',
            'sort',
            'direction',
        ]);

        $user = Auth::user();
        $userId = $user?->id ?? Auth::id();
        $accountId = $user?->accountOwnerId() ?? $userId;

        $baseQuery = Product::query()
            ->services()
            ->filter($filters)
            ->byUser($userId);

        $sort = in_array($filters['sort'] ?? null, ['name', 'price', 'created_at'], true)
            ? $filters['sort']
            : 'created_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $services = (clone $baseQuery)
            ->with(['category', 'serviceMaterials.product'])
            ->orderBy($sort, $direction)
            ->simplePaginate(10)
            ->withQueryString();

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'active' => (clone $baseQuery)->where('is_active', true)->count(),
            'archived' => (clone $baseQuery)->where('is_active', false)->count(),
            'average_price' => round((float) ((clone $baseQuery)->avg('price') ?? 0), 2),
        ];

        return $this->inertiaOrJson('Service/Index', [
            'filters' => $filters,
            'services' => $services,
            'categories' => ProductCategory::forAccount($accountId)
                ->orderBy('name')
                ->get(['id', 'name', 'archived_at']),
            'materialProducts' => Product::query()
                ->products()
                ->byUser($userId)
                ->orderBy('name')
                ->get(['id', 'name', 'unit', 'price']),
            'stats' => $stats,
            'count' => $stats['total'],
        ]);
    }

    public function options()
    {
        $this->ensureServiceAccess();
        $accountId = Auth::user()?->accountOwnerId() ?? Auth::id();

        return response()->json([
            'categories' => ProductCategory::forAccount($accountId)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function categories(Request $request)
    {
        $this->ensureCategoryAccess();

        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        $filters = $request->only([
            'search',
            'status',
            'created_from',
            'created_to',
            'created_by',
            'sort',
            'direction',
        ]);

        $baseQuery = ProductCategory::forAccount($accountId);

        $filteredQuery = (clone $baseQuery)
            ->when(
                $filters['search'] ?? null,
                fn($query, $search) => $query->where('name', 'like', '%' . $search . '%')
            )
            ->when(
                $filters['status'] ?? null,
                function ($query, $status) {
                    if ($status === 'active') {
                        $query->whereNull('archived_at');
                    } elseif ($status === 'archived') {
                        $query->whereNotNull('archived_at');
                    }
                }
            )
            ->when(
                $filters['created_from'] ?? null,
                fn($query, $createdFrom) => $query->whereDate('created_at', '>=', $createdFrom)
            )
            ->when(
                $filters['created_to'] ?? null,
                fn($query, $createdTo) => $query->whereDate('created_at', '<=', $createdTo)
            )
            ->when(
                $filters['created_by'] ?? null,
                fn($query, $createdBy) => $query->where('created_by_user_id', $createdBy)
            );

        $sort = in_array($filters['sort'] ?? null, ['name', 'created_at', 'items_count'], true)
            ? $filters['sort']
            : 'created_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $filteredCount = (clone $filteredQuery)->count();

        $categories = (clone $filteredQuery)
            ->select(['id', 'name', 'user_id', 'created_by_user_id', 'created_at', 'archived_at'])
            ->with(['createdBy:id,name'])
            ->withCount([
                'products as items_count' => fn($query) => $query->byUser($accountId),
                'products as products_count' => fn($query) => $query
                    ->byUser($accountId)
                    ->where('item_type', Product::ITEM_TYPE_PRODUCT),
                'products as services_count' => fn($query) => $query
                    ->byUser($accountId)
                    ->where('item_type', Product::ITEM_TYPE_SERVICE),
            ])
            ->orderBy($sort, $direction)
            ->simplePaginate(10)
            ->withQueryString();

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'active' => (clone $baseQuery)->whereNull('archived_at')->count(),
            'archived' => (clone $baseQuery)->whereNotNull('archived_at')->count(),
            'used' => (clone $baseQuery)
                ->whereHas('products', fn($query) => $query->byUser($accountId))
                ->count(),
        ];

        $ownerOption = $user ? [
            'id' => $user->id,
            'name' => $user->name,
            'type' => 'owner',
        ] : null;

        $teamOptions = TeamMember::query()
            ->forAccount($accountId)
            ->active()
            ->with('user:id,name')
            ->get()
            ->map(function (TeamMember $member) {
                if (!$member->user) {
                    return null;
                }

                return [
                    'id' => $member->user->id,
                    'name' => $member->user->name,
                    'type' => 'team',
                ];
            })
            ->filter()
            ->values();

        $creatorOptions = collect([$ownerOption])
            ->filter()
            ->merge($teamOptions)
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->all();

        return $this->inertiaOrJson('Service/Categories', [
            'filters' => $filters,
            'categories' => $categories,
            'stats' => $stats,
            'count' => $filteredCount,
            'creators' => $creatorOptions,
        ]);
    }

    public function store(ServiceRequest $request)
    {
        $this->ensureServiceAccess();
        app(UsageLimitService::class)->enforceLimit($request->user(), 'services');

        $validated = $request->validated();
        $validated['item_type'] = Product::ITEM_TYPE_SERVICE;
        $validated['stock'] = 0;
        $validated['minimum_stock'] = 0;
        $validated['image'] = FileHandler::handleImageUpload('services', $request, 'image', 'products/product.jpg');

        $service = $request->user()->products()->create($validated);

        try {
            app(StripeCatalogService::class)->syncProductPrice($service);
        } catch (\Throwable $exception) {
            report($exception);
        }

        if ($request->has('materials')) {
            $this->syncServiceMaterials($service, $validated['materials'] ?? []);
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Service created successfully.',
                'service' => $service->fresh(['category', 'serviceMaterials.product']),
            ], 201);
        }

        return redirect()->route('service.index')->with('success', 'Service created successfully.');
    }

    public function storeQuick(ServiceRequest $request)
    {
        $this->ensureServiceAccess();
        app(UsageLimitService::class)->enforceLimit($request->user(), 'services');

        $validated = $request->validated();
        $validated['item_type'] = Product::ITEM_TYPE_SERVICE;
        $validated['stock'] = 0;
        $validated['minimum_stock'] = 0;

        $service = $request->user()->products()->create($validated);

        try {
            app(StripeCatalogService::class)->syncProductPrice($service);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return response()->json([
            'service' => [
                'id' => $service->id,
                'name' => $service->name,
                'price' => $service->price,
            ],
        ], 201);
    }

    public function update(ServiceRequest $request, Product $service)
    {
        $this->ensureServiceAccess();
        $this->authorize('update', $service);
        $this->ensureServiceItem($service);

        $validated = $request->validated();
        $previousPrice = (float) $service->price;
        $previousName = (string) $service->name;
        $previousDescription = $service->description;
        $previousActive = (bool) $service->is_active;
        $validated['item_type'] = Product::ITEM_TYPE_SERVICE;
        $validated['stock'] = 0;
        $validated['minimum_stock'] = 0;
        $validated['image'] = FileHandler::handleImageUpload('services', $request, 'image', 'products/product.jpg', $service->image);

        $service->update($validated);

        if ($request->has('materials')) {
            $this->syncServiceMaterials($service, $validated['materials'] ?? []);
        }

        $priceChanged = array_key_exists('price', $validated) && (float) $service->price !== $previousPrice;
        $infoChanged = ($validated['name'] ?? $previousName) !== $previousName
            || ($validated['description'] ?? null) !== $previousDescription
            || ((bool) ($validated['is_active'] ?? $previousActive)) !== $previousActive;
        $needsStripeSync = $priceChanged || $infoChanged || empty($service->stripe_product_id);
        if ($needsStripeSync) {
            try {
                app(StripeCatalogService::class)->syncProductPrice(
                    $service,
                    $priceChanged || empty($service->stripe_product_id)
                );
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Service updated successfully.',
                'service' => $service->fresh(['category', 'serviceMaterials.product']),
            ]);
        }

        return redirect()->route('service.index')->with('success', 'Service updated successfully.');
    }

    public function destroy(Product $service)
    {
        $this->ensureServiceAccess();
        $this->authorize('delete', $service);
        $this->ensureServiceItem($service);

        FileHandler::deleteFile($service->image, 'products/product.jpg');
        $service->delete();

        if ($this->shouldReturnJson()) {
            return response()->json([
                'message' => 'Service deleted successfully.',
            ]);
        }

        return redirect()->route('service.index')->with('success', 'Service deleted successfully.');
    }

    private function ensureServiceAccess(): void
    {
        $user = Auth::user();

        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        if ($user->company_type === 'products') {
            abort(404);
        }
    }

    private function ensureCategoryAccess(): void
    {
        $user = Auth::user();

        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }
    }

    private function ensureServiceItem(Product $service): void
    {
        if ($service->item_type !== Product::ITEM_TYPE_SERVICE) {
            abort(404);
        }
    }

    private function syncServiceMaterials(Product $service, array $materials): void
    {
        $service->serviceMaterials()->delete();

        if (!$materials) {
            return;
        }

        $userId = $service->user_id;
        $productIds = collect($materials)
            ->pluck('product_id')
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        $productMap = $productIds->isNotEmpty()
            ? Product::query()
                ->products()
                ->byUser($userId)
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id')
            : collect();

        $payload = collect($materials)
            ->map(function ($material, $index) use ($productMap) {
                $productId = isset($material['product_id']) ? (int) $material['product_id'] : null;
                $product = $productId ? $productMap->get($productId) : null;
                $label = trim((string) ($material['label'] ?? ''));
                if (!$label && $product) {
                    $label = $product->name;
                }

                if (!$label) {
                    return null;
                }

                $quantity = isset($material['quantity']) ? (float) $material['quantity'] : 1;
                $unitPrice = isset($material['unit_price'])
                    ? (float) $material['unit_price']
                    : (float) ($product?->price ?? 0);

                return [
                    'product_id' => $product?->id,
                    'label' => $label,
                    'description' => $material['description'] ?? null,
                    'unit' => $material['unit'] ?? $product?->unit ?? null,
                    'quantity' => max(0, $quantity),
                    'unit_price' => max(0, $unitPrice),
                    'billable' => isset($material['billable']) ? (bool) $material['billable'] : true,
                    'sort_order' => isset($material['sort_order']) ? (int) $material['sort_order'] : $index,
                ];
            })
            ->filter()
            ->values();

        if ($payload->isEmpty()) {
            return;
        }

        $service->serviceMaterials()->createMany($payload->all());
    }
}
