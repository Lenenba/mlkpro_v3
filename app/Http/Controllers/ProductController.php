<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Utils\FileHandler;
use Illuminate\Http\Request;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Warehouse;
use App\Http\Requests\ProductRequest;
use App\Services\InventoryService;
use App\Services\UsageLimitService;
use App\Notifications\SupplierStockRequestNotification;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of products with pagination and categories.
     */
    public function index(?Request $request)
    {
        $filters = $request->only([
            'name',
            'category_id',
            'category_ids',
            'stock_status',
            'price_min',
            'price_max',
            'stock_min',
            'stock_max',
            'has_image',
            'has_barcode',
            'created_from',
            'created_to',
            'status',
            'supplier_name',
            'tracking_type',
            'warehouse_id',
            'alert',
            'sort',
            'direction',
        ]);
        $user = $request?->user() ?? Auth::user();
        if (!$user) {
            abort(403);
        }
        [, $accountId, $canEdit] = $this->resolveProductAccount($user);

        $today = now()->toDateString();
        $expiringDate = now()->addDays(30)->toDateString();

        $baseQuery = Product::query()
            ->products()
            ->filter($filters)
            ->byUser($accountId)
            ->withSum('inventories as on_hand_total', 'on_hand')
            ->withSum('inventories as reserved_total', 'reserved')
            ->withSum('inventories as damaged_total', 'damaged')
            ->withCount('inventories as warehouse_count')
            ->withCount('lots')
            ->withCount([
                'lots as expired_lot_count' => function ($query) use ($today) {
                    $query->whereNotNull('expires_at')
                        ->whereDate('expires_at', '<', $today);
                },
                'lots as expiring_lot_count' => function ($query) use ($today, $expiringDate) {
                    $query->whereNotNull('expires_at')
                        ->whereDate('expires_at', '>=', $today)
                        ->whereDate('expires_at', '<=', $expiringDate);
                },
            ])
            ->withMin('lots as next_expiry_at', 'expires_at');

        $sort = in_array($filters['sort'] ?? null, ['name', 'price', 'stock', 'created_at'], true)
            ? $filters['sort']
            : 'created_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $warehouses = Warehouse::query()
            ->forAccount($accountId)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'is_default', 'is_active']);
        $defaultWarehouseId = $warehouses->firstWhere('is_default', true)?->id
            ?? $warehouses->first()?->id;
        $inventoryWarehouseId = $filters['warehouse_id'] ?? $defaultWarehouseId;
        $inventoryWarehouseId = $inventoryWarehouseId ? (int) $inventoryWarehouseId : null;

        $products = (clone $baseQuery)
            ->with([
                'category',
                'user',
                'images',
                'stockMovements' => function ($query) {
                    $query->limit(5)->with(['warehouse', 'lot']);
                },
                'lots' => function ($query) use ($expiringDate) {
                    $query->whereNotNull('expires_at')
                        ->whereDate('expires_at', '<=', $expiringDate)
                        ->orderBy('expires_at')
                        ->with(['warehouse:id,name,code']);
                },
            ])
            ->when($inventoryWarehouseId, function ($query, $warehouseId) {
                $query->with(['inventories' => function ($inventoryQuery) use ($warehouseId) {
                    $inventoryQuery->where('warehouse_id', $warehouseId)->with('warehouse');
                }]);
            })
            ->orderBy($sort, $direction)
            ->simplePaginate(7)
            ->withQueryString();

        $productCollection = $products->getCollection();
        $reservedOrders = $this->resolveReservedOrders($accountId, $productCollection);
        $productCollection->transform(function (Product $product) use ($reservedOrders) {
            $product->setAttribute('reserved_orders', $reservedOrders[$product->id] ?? []);
            return $product;
        });
        $products->setCollection($productCollection);

        $totalCount = (clone $baseQuery)->count();
        $totalStock = (clone $baseQuery)->sum('stock');
        $inventoryValue = (clone $baseQuery)
            ->select(DB::raw('COALESCE(SUM(stock * COALESCE(NULLIF(cost_price, 0), price)), 0) as value'))
            ->value('value');

        $stats = [
            'total' => $totalCount,
            'in_stock' => (clone $baseQuery)
                ->where('stock', '>', 0)
                ->whereColumn('stock', '>', 'minimum_stock')
                ->count(),
            'low_stock' => (clone $baseQuery)
                ->whereColumn('stock', '<=', 'minimum_stock')
                ->where('stock', '>', 0)
                ->count(),
            'out_of_stock' => (clone $baseQuery)
                ->where('stock', '<=', 0)
                ->count(),
            'inventory_value' => $inventoryValue,
        ];

        $topProducts = collect();
        $rotation = 0;
        $productIds = (clone $baseQuery)->pluck('id');
        if ($productIds->isNotEmpty()) {
            $quoteUsage = DB::table('quote_products')
                ->join('quotes', 'quote_products.quote_id', '=', 'quotes.id')
                ->where('quotes.user_id', $accountId)
                ->whereIn('quote_products.product_id', $productIds)
                ->select('quote_products.product_id', DB::raw('SUM(quote_products.quantity) as quantity'))
                ->groupBy('quote_products.product_id');

            $workUsage = DB::table('product_works')
                ->join('works', 'product_works.work_id', '=', 'works.id')
                ->where('works.user_id', $accountId)
                ->whereIn('product_works.product_id', $productIds)
                ->select('product_works.product_id', DB::raw('SUM(product_works.quantity) as quantity'))
                ->groupBy('product_works.product_id');

            $usageTotals = DB::query()
                ->fromSub($quoteUsage->unionAll($workUsage), 'usage')
                ->select('product_id', DB::raw('SUM(quantity) as total_quantity'))
                ->groupBy('product_id')
                ->orderByDesc('total_quantity')
                ->get();

            $usageTotal = $usageTotals->sum('total_quantity');
            $rotation = $totalStock > 0 ? round($usageTotal / $totalStock, 2) : 0;

            $usageTop = $usageTotals->take(5);
            if ($usageTop->isNotEmpty()) {
                $productMap = Product::whereIn('id', $usageTop->pluck('product_id'))
                    ->get(['id', 'name', 'image'])
                    ->keyBy('id');

                $topProducts = $usageTop->map(function ($row) use ($productMap) {
                    $product = $productMap->get($row->product_id);

                    return [
                        'id' => $row->product_id,
                        'name' => $product?->name ?? 'Unknown',
                        'image_url' => $product?->image_url,
                        'quantity' => (int) $row->total_quantity,
                    ];
                })->values();
            }
        }

        $stats['rotation'] = $rotation;

        return $this->inertiaOrJson('Product/Index', [
            'count' => $totalCount,
            'filters' => $filters,
            'categories' => ProductCategory::forAccount($accountId)
                ->orderBy('name')
                ->get(['id', 'name', 'archived_at']),
            'products' => $products,
            'stats' => $stats,
            'topProducts' => $topProducts,
            'warehouses' => $warehouses,
            'defaultWarehouseId' => $defaultWarehouseId,
            'canEdit' => $canEdit,
        ]);
    }

    /**
     * Return product categories for quick-create dialogs.
     */
    public function options()
    {
        $user = Auth::user();
        if (!$user) {
            abort(403);
        }
        $accountId = $this->ensureProductOwner($user);

        return response()->json([
            'categories' => ProductCategory::forAccount($accountId)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * Show the form for creating a new product.
     */
    public function create()
    {
        $user = Auth::user();
        if (!$user) {
            abort(403);
        }
        $accountId = $this->ensureProductOwner($user);

        return $this->inertiaOrJson('Product/Create', [
            'categories' => ProductCategory::forAccount($accountId)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name'])
        ]);
    }

    /**
     * Store a newly created product in the database.
     */
    public function store(ProductRequest $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(403);
        }
        $accountId = $this->ensureProductOwner($user);
        app(UsageLimitService::class)->enforceLimit($user, 'products');
        $validated = $request->validated();
        $validated['item_type'] = Product::ITEM_TYPE_PRODUCT;
        $validated['image'] = FileHandler::handleImageUpload('products', $request, 'image', 'products/product.jpg');
        $imageUrl = $request->input('image_url');
        if (!$request->hasFile('image') && is_string($imageUrl) && $imageUrl !== '') {
            $validated['image'] = $imageUrl;
        }
        unset($validated['image_url']);
        $extraImages = FileHandler::handleMultipleImageUpload('products', $request, 'images');

        $product = $request->user()->products()->create($validated);

        $product->images()->updateOrCreate(
            ['is_primary' => true],
            ['path' => $product->image, 'is_primary' => true, 'sort_order' => 0]
        );

        foreach ($extraImages as $index => $path) {
            $product->images()->create([
                'path' => $path,
                'is_primary' => false,
                'sort_order' => $index + 1,
            ]);
        }

        $inventoryService = app(InventoryService::class);
        $warehouse = $inventoryService->resolveDefaultWarehouse($accountId);
        $inventoryService->ensureInventory($product, $warehouse);
        if ($product->stock > 0) {
            $inventoryService->adjust($product, (int) $product->stock, 'in', [
                'actor_id' => $request->user()?->id,
                'warehouse' => $warehouse,
                'reason' => 'initial',
                'note' => 'Initial stock',
            ]);
            $product->refresh();
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Product created successfully.',
                'product' => $product->load(['category', 'images']),
            ], 201);
        }

        return redirect()->route('product.index')->with('success', 'Product created successfully.');
    }

    /**
     * Store a product from quick-create dialogs.
     */
    public function storeQuick(ProductRequest $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(403);
        }
        $accountId = $this->ensureProductOwner($user);
        app(UsageLimitService::class)->enforceLimit($user, 'products');
        $validated = $request->validated();
        $validated['item_type'] = Product::ITEM_TYPE_PRODUCT;
        $validated['image'] = FileHandler::handleImageUpload('products', $request, 'image', 'products/product.jpg');
        $imageUrl = $request->input('image_url');
        if (!$request->hasFile('image') && is_string($imageUrl) && $imageUrl !== '') {
            $validated['image'] = $imageUrl;
        }
        unset($validated['image_url']);
        $extraImages = FileHandler::handleMultipleImageUpload('products', $request, 'images');

        $product = $request->user()->products()->create($validated);

        $product->images()->updateOrCreate(
            ['is_primary' => true],
            ['path' => $product->image, 'is_primary' => true, 'sort_order' => 0]
        );

        foreach ($extraImages as $index => $path) {
            $product->images()->create([
                'path' => $path,
                'is_primary' => false,
                'sort_order' => $index + 1,
            ]);
        }

        $inventoryService = app(InventoryService::class);
        $warehouse = $inventoryService->resolveDefaultWarehouse($accountId);
        $inventoryService->ensureInventory($product, $warehouse);
        if ($product->stock > 0) {
            $inventoryService->adjust($product, (int) $product->stock, 'in', [
                'actor_id' => $request->user()?->id,
                'warehouse' => $warehouse,
                'reason' => 'initial',
                'note' => 'Initial stock',
            ]);
            $product->refresh();
        }

        return response()->json([
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'price' => $product->price,
                'stock' => $product->stock,
            ],
        ], 201);
    }

    /**
     * Store a draft product from a price lookup line.
     */
    public function storeDraft(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(403);
        }
        $accountId = $this->ensureProductOwner($user);
        $creatorId = $user->id;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'unit' => 'nullable|string|max:50',
            'item_type' => ['nullable', Rule::in([Product::ITEM_TYPE_PRODUCT, Product::ITEM_TYPE_SERVICE])],
            'source_details' => 'nullable',
        ]);

        $itemType = $validated['item_type']
            ?? ($user->company_type === 'products' ? Product::ITEM_TYPE_PRODUCT : Product::ITEM_TYPE_SERVICE);
        $limitKey = $itemType === Product::ITEM_TYPE_SERVICE ? 'services' : 'products';
        app(UsageLimitService::class)->enforceLimit($user, $limitKey);

        $name = trim($validated['name']);
        $existing = Product::query()
            ->byUser(Auth::id())
            ->where('item_type', $itemType)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->first();

        if ($existing) {
            return response()->json([
                'product' => [
                    'id' => $existing->id,
                    'name' => $existing->name,
                    'price' => $existing->price,
                    'image_url' => $existing->image_url,
                    'is_active' => $existing->is_active,
                ],
                'created' => false,
            ]);
        }

        $sourceDetails = $this->normalizeSourceDetails($validated['source_details'] ?? null);
        $selected = $sourceDetails['selected_source'] ?? null;
        $best = $sourceDetails['best_source'] ?? null;
        $source = is_array($selected) ? $selected : (is_array($best) ? $best : null);

        $price = (float) $validated['price'];
        $costPrice = is_array($source) && isset($source['price']) ? (float) $source['price'] : $price;
        $marginPercent = 0.0;
        if ($price > 0 && $costPrice > 0) {
            $marginPercent = round((($price - $costPrice) / $price) * 100, 2);
        }

        $description = $validated['description'] ?? null;
        if (!$description && is_array($source)) {
            $description = $source['title'] ?? null;
        }

        $category = $this->resolveCategory($accountId, $creatorId, $itemType);
        if (!$category) {
            return response()->json([
                'message' => 'Unable to resolve category.',
            ], 422);
        }

        $product = Product::create([
            'user_id' => Auth::id(),
            'name' => $name ?: 'Draft product',
            'description' => $description ?: 'Auto-generated from price lookup.',
            'category_id' => $category->id,
            'price' => $price,
            'cost_price' => $costPrice,
            'margin_percent' => $marginPercent,
            'unit' => $validated['unit'] ?? null,
            'supplier_name' => is_array($source) ? ($source['name'] ?? null) : null,
            'stock' => 0,
            'minimum_stock' => 0,
            'is_active' => false,
            'item_type' => $itemType,
            'image' => is_array($source) ? ($source['image_url'] ?? null) : null,
        ]);

        $inventoryService = app(InventoryService::class);
        $warehouse = $inventoryService->resolveDefaultWarehouse($accountId);
        $inventoryService->ensureInventory($product, $warehouse);

        return response()->json([
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'price' => $product->price,
                'image_url' => $product->image_url,
                'is_active' => $product->is_active,
            ],
            'created' => true,
        ], 201);
    }

    /**
     * Display the specified product.
     */
    public function show(Product $product)
    {
        try {
            $this->authorize('view', $product);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            if ($this->shouldReturnJson()) {
                return response()->json([
                    'message' => 'You are not authorized to view this product.',
                ], 403);
            }

            return redirect()->back()->with('error', 'You are not authorized to view this product.');
        }

        $this->ensureProductItem($product);

        $user = Auth::user();
        if (!$user) {
            abort(403);
        }
        [, $accountId, $canEdit] = $this->resolveProductAccount($user);
        $warehouses = Warehouse::query()
            ->forAccount($accountId)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'is_default', 'is_active']);
        $defaultWarehouseId = $warehouses->firstWhere('is_default', true)?->id
            ?? $warehouses->first()?->id;

        return $this->inertiaOrJson('Product/Show', [
            'product' => $product->load([
                'category',
                'user',
                'images',
                'inventories.warehouse',
                'lots.warehouse',
                'stockMovements' => function ($query) {
                    $query->limit(10)->with(['warehouse', 'lot']);
                },
            ]),
            'categories' => ProductCategory::forAccount($accountId)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name']),
            'warehouses' => $warehouses,
            'defaultWarehouseId' => $defaultWarehouseId,
            'canEdit' => $canEdit,
        ]);
    }

    /**
     * Show the form for editing the specified product.
     */
    public function edit(Product $product)
    {
        return $this->show($product);
    }

    /**
     * Quick update selected product fields.
     */
    public function quickUpdate(Request $request, Product $product)
    {
        $this->authorize('update', $product);
        $this->ensureProductItem($product);

        $data = $request->validate([
            'price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'margin_percent' => 'nullable|numeric|min:0|max:100',
            'stock' => 'nullable|integer|min:0',
            'minimum_stock' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $stockInput = array_key_exists('stock', $data) ? $data['stock'] : null;
        $minimumInput = array_key_exists('minimum_stock', $data) ? $data['minimum_stock'] : null;
        $previousStock = (int) $product->stock;
        $data = array_filter($data, static fn($value) => $value !== null);
        $product->update($data);

        if ($stockInput !== null) {
            $delta = (int) $stockInput - $previousStock;
            if ($delta !== 0) {
                $inventoryService = app(InventoryService::class);
                $accountId = $request->user()?->accountOwnerId() ?? $request->user()?->id;
                $warehouse = $inventoryService->resolveDefaultWarehouse($accountId);
                $inventoryService->adjust($product, $delta, 'adjust', [
                    'actor_id' => $request->user()?->id,
                    'warehouse' => $warehouse,
                    'reason' => 'quick_edit',
                    'note' => 'Quick edit',
                ]);
                $product->refresh();
            }
        }

        if ($minimumInput !== null) {
            $inventoryService = app(InventoryService::class);
            $accountId = $request->user()?->accountOwnerId() ?? $request->user()?->id;
            $warehouse = $inventoryService->resolveDefaultWarehouse($accountId);
            $inventory = $inventoryService->ensureInventory($product, $warehouse);
            $inventory->update([
                'minimum_stock' => (int) $product->minimum_stock,
                'reorder_point' => (int) $product->minimum_stock,
            ]);
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Product updated successfully.',
                'product' => $product->fresh(),
            ]);
        }

        return redirect()->back()->with('success', 'Product updated successfully.');
    }

    /**
     * Adjust stock levels and create a stock movement record.
     */
    public function adjustStock(Request $request, Product $product)
    {
        $this->authorize('update', $product);
        $this->ensureProductItem($product);
        $user = $request->user();
        if ($user && $user->currentAccessToken() && !$user->tokenCan('inventory:write')) {
            abort(403);
        }

        $data = $request->validate([
            'type' => 'required|in:in,out,adjust,damage,spoilage',
            'quantity' => 'required|integer',
            'note' => 'nullable|string|max:255',
            'reason' => 'nullable|string|max:50',
            'warehouse_id' => 'nullable|integer',
            'lot_number' => 'nullable|string|max:100',
            'serial_number' => 'nullable|string|max:100',
            'expires_at' => 'nullable|date',
            'received_at' => 'nullable|date',
            'unit_cost' => 'nullable|numeric|min:0',
        ]);

        $accountId = $request->user()?->accountOwnerId() ?? $request->user()?->id;
        if (!empty($data['warehouse_id'])) {
            $warehouseExists = Warehouse::query()
                ->forAccount($accountId)
                ->whereKey($data['warehouse_id'])
                ->exists();

            if (!$warehouseExists) {
                throw ValidationException::withMessages([
                    'warehouse_id' => ['Invalid warehouse selection.'],
                ]);
            }
        }

        if ($product->tracking_type === 'lot' && empty($data['lot_number'])) {
            throw ValidationException::withMessages([
                'lot_number' => ['Lot number is required for lot-tracked products.'],
            ]);
        }

        if ($product->tracking_type === 'serial') {
            if (empty($data['serial_number'])) {
                throw ValidationException::withMessages([
                    'serial_number' => ['Serial number is required for serial-tracked products.'],
                ]);
            }

            if (abs((int) $data['quantity']) !== 1) {
                throw ValidationException::withMessages([
                    'quantity' => ['Serial-tracked items must be adjusted one at a time.'],
                ]);
            }
        }

        $inventoryService = app(InventoryService::class);
        $movement = $inventoryService->adjust($product, (int) $data['quantity'], $data['type'], [
            'actor_id' => Auth::id(),
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'account_id' => $accountId,
            'reason' => $data['reason'] ?? 'manual',
            'note' => $data['note'] ?? null,
            'lot_number' => $data['lot_number'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'received_at' => $data['received_at'] ?? null,
            'unit_cost' => $data['unit_cost'] ?? null,
        ]);

        ActivityLog::record(Auth::user(), $product, 'stock_movement', [
            'type' => $movement->type,
            'quantity' => $movement->quantity,
            'note' => $movement->note,
            'reason' => $movement->reason,
            'warehouse_id' => $movement->warehouse_id,
            'lot_id' => $movement->lot_id,
        ], 'Stock movement recorded');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Stock updated successfully.',
                'product' => $product->fresh(),
            ]);
        }

        return redirect()->back()->with('success', 'Stock updated successfully.');
    }

    public function requestSupplierStock(Request $request, Product $product)
    {
        $this->authorize('update', $product);
        $this->ensureProductItem($product);
        $user = $request->user();
        if (!$user) {
            abort(403);
        }
        $accountId = $this->ensureProductOwner($user);
        if ($product->user_id !== $accountId) {
            abort(404);
        }

        $validated = $request->validate([
            'supplier_email' => 'nullable|email|max:255',
            'message' => 'nullable|string|max:2000',
        ]);

        $supplierEmail = $validated['supplier_email'] ?? $product->supplier_email;
        if (!$supplierEmail) {
            throw ValidationException::withMessages([
                'supplier_email' => 'Email fournisseur requis.',
            ]);
        }

        Notification::route('mail', $supplierEmail)
            ->notify(new SupplierStockRequestNotification($product, $user, $validated['message'] ?? null));

        ActivityLog::record($user, $product, 'supplier_stock_request', [
            'supplier_email' => $supplierEmail,
        ], 'Supplier stock request sent');

        return redirect()->back()->with('success', 'Email fournisseur envoye.');
    }

    /**
     * Bulk actions on products.
     */
    public function bulk(Request $request)
    {
        $data = $request->validate([
            'action' => 'required|in:archive,restore,delete',
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $products = Product::query()
            ->products()
            ->byUser(Auth::id())
            ->whereIn('id', $data['ids'])
            ->get();

        if ($data['action'] === 'archive') {
            foreach ($products as $product) {
                $this->authorize('update', $product);
            }
            Product::query()->products()->byUser(Auth::id())->whereIn('id', $data['ids'])->update(['is_active' => false]);
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Products archived.',
                    'ids' => $data['ids'],
                ]);
            }
            return redirect()->back()->with('success', 'Products archived.');
        }

        if ($data['action'] === 'restore') {
            foreach ($products as $product) {
                $this->authorize('update', $product);
            }
            Product::query()->products()->byUser(Auth::id())->whereIn('id', $data['ids'])->update(['is_active' => true]);
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Products restored.',
                    'ids' => $data['ids'],
                ]);
            }
            return redirect()->back()->with('success', 'Products restored.');
        }

        foreach ($products as $product) {
            $this->authorize('delete', $product);
            foreach ($product->images as $image) {
                FileHandler::deleteFile($image->path, 'products/product.jpg');
            }
            FileHandler::deleteFile($product->image, 'products/product.jpg');
            $product->delete();
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Products deleted.',
                'ids' => $data['ids'],
            ]);
        }

        return redirect()->back()->with('success', 'Products deleted.');
    }

    /**
     * Duplicate a product with its images.
     */
    public function duplicate(Product $product)
    {
        $this->authorize('update', $product);
        $this->ensureProductItem($product);

        $copy = $product->replicate(['created_at', 'updated_at']);
        $copy->name = $product->name . ' (Copy)';
        $copy->number = null;
        $copy->is_active = false;
        $copy->save();

        if ($product->image) {
            $source = $product->image;
            $target = 'products/' . basename($copy->id . '_' . basename($source));
            if (Storage::disk('public')->exists($source)) {
                Storage::disk('public')->copy($source, $target);
                $copy->image = $target;
                $copy->save();
            }
        }

        foreach ($product->images as $image) {
            $source = $image->path;
            $target = 'products/' . basename($copy->id . '_' . basename($source));
            if (Storage::disk('public')->exists($source)) {
                Storage::disk('public')->copy($source, $target);
                $copy->images()->create([
                    'path' => $target,
                    'is_primary' => $image->is_primary,
                    'sort_order' => $image->sort_order,
                ]);
            }
        }

        $inventoryService = app(InventoryService::class);
        $accountId = Auth::user()?->accountOwnerId() ?? Auth::id();
        $warehouse = $inventoryService->resolveDefaultWarehouse($accountId);
        $inventoryService->ensureInventory($copy, $warehouse);
        if ($copy->stock > 0) {
            $inventoryService->adjust($copy, (int) $copy->stock, 'in', [
                'actor_id' => Auth::id(),
                'warehouse' => $warehouse,
                'reason' => 'duplicate',
                'note' => 'Duplicated product',
            ]);
            $copy->refresh();
        }

        if ($this->shouldReturnJson()) {
            return response()->json([
                'message' => 'Product duplicated.',
                'product' => $copy->fresh(['category', 'images']),
            ]);
        }

        return redirect()->back()->with('success', 'Product duplicated.');
    }

    /**
     * Export products as CSV.
     */
    public function export(Request $request)
    {
        $user = $request->user();
        if ($user && $user->currentAccessToken() && !$user->tokenCan('exports:read')) {
            abort(403);
        }

        $filters = $request->only([
            'name',
            'category_id',
            'category_ids',
            'stock_status',
            'price_min',
            'price_max',
            'stock_min',
            'stock_max',
            'has_image',
            'has_barcode',
            'created_from',
            'created_to',
            'status',
            'supplier_name',
            'tracking_type',
            'warehouse_id',
            'alert',
            'sort',
            'direction',
        ]);

        $query = Product::query()
            ->products()
            ->filter($filters)
            ->byUser(Auth::id())
            ->with('category')
            ->withSum('inventories as on_hand_total', 'on_hand')
            ->withSum('inventories as reserved_total', 'reserved')
            ->withSum('inventories as damaged_total', 'damaged')
            ->withCount('inventories as warehouse_count');

        $filename = 'products-' . now()->format('Ymd-His') . '.csv';

        ActivityLog::record($request->user(), $request->user(), 'product_export', [
            'filters' => $filters,
        ], 'Products exported');

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'name',
                'sku',
                'barcode',
                'unit',
                'supplier_name',
                'supplier_email',
                'price',
                'cost_price',
                'margin_percent',
                'stock_available',
                'stock_reserved',
                'stock_damaged',
                'minimum_stock',
                'tracking_type',
                'warehouse_count',
                'tax_rate',
                'category',
                'is_active',
                'created_at',
            ]);

            $query->orderBy('name')
                ->chunk(200, function ($products) use ($handle) {
                    foreach ($products as $product) {
                        fputcsv($handle, [
                            $product->name,
                            $product->sku,
                            $product->barcode,
                            $product->unit,
                            $product->supplier_name,
                            $product->supplier_email,
                            $product->price,
                            $product->cost_price,
                            $product->margin_percent,
                            $product->stock_available,
                            $product->stock_reserved,
                            $product->stock_damaged,
                            $product->minimum_stock,
                            $product->tracking_type ?? 'none',
                            $product->warehouse_count,
                            $product->tax_rate,
                            $product->category?->name,
                            $product->is_active ? '1' : '0',
                            optional($product->created_at)->toDateString(),
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Import products from CSV.
     */
    public function import(Request $request)
    {
        $tokenUser = $request->user();
        if ($tokenUser && $tokenUser->currentAccessToken() && !$tokenUser->tokenCan('inventory:write')) {
            abort(403);
        }

        $user = $request->user();
        if (!$user) {
            abort(403);
        }
        $accountId = $this->ensureProductOwner($user);
        $creatorId = $user->id;
        $inventoryService = app(InventoryService::class);
        $warehouse = $inventoryService->resolveDefaultWarehouse($accountId);

        $data = $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10000',
        ]);

        $file = $data['file'];
        $handle = fopen($file->getRealPath(), 'r');
        if (!$handle) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Unable to read import file.',
                ], 422);
            }

            return redirect()->back()->with('error', 'Unable to read import file.');
        }

        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Import file is empty.',
                ], 422);
            }

            return redirect()->back()->with('error', 'Import file is empty.');
        }

        $headers = array_map('trim', $headers);
        $imported = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $row = array_pad($row, count($headers), null);
            $dataRow = array_combine($headers, $row);
            if (!$dataRow || empty($dataRow['name'])) {
                continue;
            }

            $categoryName = $dataRow['category'] ?? null;
            $categoryId = null;
            if ($categoryName) {
                $category = ProductCategory::resolveForAccount($accountId, $creatorId, $categoryName);
                $categoryId = $category?->id;
            }

            $trackingType = in_array($dataRow['tracking_type'] ?? 'none', ['none', 'lot', 'serial'], true)
                ? ($dataRow['tracking_type'] ?? 'none')
                : 'none';

            $payload = [
                'name' => $dataRow['name'],
                'sku' => $dataRow['sku'] ?? null,
                'barcode' => $dataRow['barcode'] ?? null,
                'unit' => $dataRow['unit'] ?? null,
                'supplier_name' => $dataRow['supplier_name'] ?? null,
                'supplier_email' => $dataRow['supplier_email'] ?? null,
                'price' => $dataRow['price'] ?? 0,
                'cost_price' => $dataRow['cost_price'] ?? 0,
                'margin_percent' => $dataRow['margin_percent'] ?? 0,
                'stock' => $dataRow['stock_available'] ?? $dataRow['stock'] ?? 0,
                'minimum_stock' => $dataRow['minimum_stock'] ?? 0,
                'tax_rate' => $dataRow['tax_rate'] ?? null,
                'category_id' => $categoryId,
                'is_active' => ($dataRow['is_active'] ?? '1') === '1',
                'tracking_type' => $trackingType,
            ];

            $query = Product::query()->products()->byUser(Auth::id());
            if (!empty($payload['sku'])) {
                $query->where('sku', $payload['sku']);
            } else {
                $query->where('name', $payload['name']);
            }

            $existing = $query->first();
            if ($existing) {
                $previousStock = (int) $existing->stock;
                $existing->update(array_filter($payload, static fn($value) => $value !== null));
                $delta = (int) $payload['stock'] - $previousStock;
                if ($delta !== 0) {
                    $inventoryService->adjust($existing, $delta, 'adjust', [
                        'actor_id' => $creatorId,
                        'warehouse' => $warehouse,
                        'reason' => 'import',
                        'note' => 'CSV import',
                    ]);
                } else {
                    $inventoryService->ensureInventory($existing, $warehouse);
                }
            } else {
                $payload['user_id'] = Auth::id();
                $payload['item_type'] = Product::ITEM_TYPE_PRODUCT;
                if (!$payload['category_id']) {
                    $fallback = $this->resolveCategory($accountId, $creatorId, Product::ITEM_TYPE_PRODUCT);
                    $payload['category_id'] = $fallback?->id;
                }
                if ($payload['category_id']) {
                    $product = Product::create($payload);
                    $inventoryService->ensureInventory($product, $warehouse);
                    if ((int) $payload['stock'] > 0) {
                        $inventoryService->adjust($product, (int) $payload['stock'], 'in', [
                            'actor_id' => $creatorId,
                            'warehouse' => $warehouse,
                            'reason' => 'import',
                            'note' => 'CSV import',
                        ]);
                    }
                }
            }

            $imported += 1;
        }

        fclose($handle);

        ActivityLog::record($request->user(), $request->user(), 'product_import', [
            'imported' => $imported,
        ], 'Products imported');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => "Imported {$imported} products.",
                'imported' => $imported,
            ]);
        }

        return redirect()->back()->with('success', "Imported {$imported} products.");
    }
    /**
     * Update the specified product in the database.
     */
    public function update(ProductRequest $request, Product $product)
    {
        try {
            $this->authorize('update', $product);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'You are not authorized to edit this product.',
                ], 403);
            }

            return redirect()->back()->with('error', 'You are not authorized to edit this product.');
        }

        $this->ensureProductItem($product);

        $validated = $request->validated();
        $previousStock = (int) $product->stock;
        $previousMinimum = (int) $product->minimum_stock;
        $validated['item_type'] = Product::ITEM_TYPE_PRODUCT;
        $validated['image'] = FileHandler::handleImageUpload('products', $request, 'image', 'products/product.jpg', $product->image);
        $imageUrl = $request->input('image_url');
        if (!$request->hasFile('image') && is_string($imageUrl) && $imageUrl !== '') {
            $validated['image'] = $imageUrl;
        }
        unset($validated['image_url']);
        $extraImages = FileHandler::handleMultipleImageUpload('products', $request, 'images');
        $removeImageIds = $request->input('remove_image_ids', []);

        $product->update($validated);

        if (!empty($removeImageIds)) {
            $imagesToRemove = $product->images()->whereIn('id', $removeImageIds)->get();
            foreach ($imagesToRemove as $image) {
                FileHandler::deleteFile($image->path, 'products/product.jpg');
                $image->delete();
            }
        }

        if ($request->hasFile('image') || $request->filled('image_url') || $product->images()->where('is_primary', true)->doesntExist()) {
            $product->images()->updateOrCreate(
                ['is_primary' => true],
                ['path' => $product->image, 'is_primary' => true, 'sort_order' => 0]
            );
        }

        foreach ($extraImages as $index => $path) {
            $product->images()->create([
                'path' => $path,
                'is_primary' => false,
                'sort_order' => $index + 1,
            ]);
        }

        if (array_key_exists('stock', $validated)) {
            $delta = (int) $product->stock - $previousStock;
            if ($delta !== 0) {
                $inventoryService = app(InventoryService::class);
                $accountId = $request->user()?->accountOwnerId() ?? $request->user()?->id;
                $warehouse = $inventoryService->resolveDefaultWarehouse($accountId);
                $inventoryService->adjust($product, $delta, 'adjust', [
                    'actor_id' => $request->user()?->id,
                    'warehouse' => $warehouse,
                    'reason' => 'product_update',
                    'note' => 'Product update',
                ]);
                $product->refresh();
            }
        }

        if (array_key_exists('minimum_stock', $validated) && $previousMinimum !== (int) $product->minimum_stock) {
            $inventoryService = app(InventoryService::class);
            $accountId = $request->user()?->accountOwnerId() ?? $request->user()?->id;
            $warehouse = $inventoryService->resolveDefaultWarehouse($accountId);
            $inventory = $inventoryService->ensureInventory($product, $warehouse);
            $inventory->update([
                'minimum_stock' => (int) $product->minimum_stock,
                'reorder_point' => (int) $product->minimum_stock,
            ]);
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Product updated successfully.',
                'product' => $product->fresh(['category', 'images']),
            ]);
        }

        return redirect()->route('product.index')->with('success', 'Product updated successfully.');
    }

    /**
     * Remove the specified product from the database.
     */
    public function destroy(Product $product)
    {
        $this->authorize('delete', $product);
        $this->ensureProductItem($product);

        foreach ($product->images as $image) {
            FileHandler::deleteFile($image->path, 'products/product.jpg');
        }
        FileHandler::deleteFile($product->image, 'products/product.jpg');
        $product->delete();

        if ($this->shouldReturnJson()) {
            return response()->json([
                'message' => 'Product deleted successfully.',
            ]);
        }

        return redirect()->route('product.index')->with('success', 'Product deleted successfully.');
    }

    private function resolveProductAccount(User $user): array
    {
        $ownerId = $user->accountOwnerId();
        $owner = $ownerId === $user->id
            ? $user
            : User::query()
                ->select(['id', 'company_type'])
                ->find($ownerId);

        if (!$owner) {
            abort(403);
        }

        $canEdit = $user->id === $owner->id;
        $accountId = $user->id;
        if ($owner->company_type === 'products') {
            if (!$canEdit) {
                $membership = $user->relationLoaded('teamMembership')
                    ? $user->teamMembership
                    : $user->teamMembership()->first();
                if (!$membership || !$membership->hasPermission('sales.manage')) {
                    abort(403);
                }
            }
            $accountId = $owner->id;
        }

        return [$owner, $accountId, $canEdit];
    }

    private function ensureProductOwner(User $user): int
    {
        if (!$user->isAccountOwner()) {
            abort(403);
        }

        return $user->accountOwnerId();
    }

    private function ensureProductItem(Product $product): void
    {
        if ($product->item_type !== Product::ITEM_TYPE_PRODUCT) {
            abort(404);
        }
    }

    private function resolveCategory(int $accountId, ?int $creatorId, string $itemType): ?ProductCategory
    {
        $name = $itemType === Product::ITEM_TYPE_PRODUCT ? 'Products' : 'Services';

        return ProductCategory::resolveForAccount($accountId, $creatorId, $name);
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

    private function resolveReservedOrders(int $accountId, Collection $products): array
    {
        $productIds = $products->pluck('id')->filter()->unique()->values();
        if ($productIds->isEmpty()) {
            return [];
        }

        $rows = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->leftJoin('customers', 'sales.customer_id', '=', 'customers.id')
            ->where('sales.user_id', $accountId)
            ->whereIn('sale_items.product_id', $productIds)
            ->where('sales.status', Sale::STATUS_PENDING)
            ->where(function ($query) {
                $query->whereNull('sales.fulfillment_status')
                    ->orWhereNotIn('sales.fulfillment_status', [
                        Sale::FULFILLMENT_COMPLETED,
                        Sale::FULFILLMENT_CONFIRMED,
                    ]);
            })
            ->groupBy([
                'sale_items.product_id',
                'sale_items.sale_id',
                'sales.number',
                'sales.status',
                'sales.fulfillment_status',
                'sales.fulfillment_method',
                'sales.scheduled_for',
                'sales.created_at',
                'sales.delivery_notes',
                'sales.pickup_notes',
                'sales.notes',
                'customers.company_name',
                'customers.first_name',
                'customers.last_name',
            ])
            ->orderByDesc('sales.created_at')
            ->select([
                'sale_items.product_id',
                'sale_items.sale_id',
                'sales.number',
                'sales.status',
                'sales.fulfillment_status',
                'sales.fulfillment_method',
                'sales.scheduled_for',
                'sales.created_at',
                'sales.delivery_notes',
                'sales.pickup_notes',
                'sales.notes',
                'customers.company_name',
                'customers.first_name',
                'customers.last_name',
                DB::raw('SUM(sale_items.quantity) as quantity'),
            ])
            ->get();

        $reservedOrders = [];
        foreach ($rows as $row) {
            $customerName = trim((string) ($row->company_name ?? ''));
            if ($customerName === '') {
                $customerName = trim(trim((string) ($row->first_name ?? '')) . ' ' . trim((string) ($row->last_name ?? '')));
            }
            if ($customerName === '') {
                $customerName = 'Client';
            }

            $reservedOrders[$row->product_id][] = [
                'id' => (int) $row->sale_id,
                'number' => $row->number,
                'status' => $row->status,
                'fulfillment_status' => $row->fulfillment_status,
                'fulfillment_method' => $row->fulfillment_method,
                'scheduled_for' => $row->scheduled_for,
                'created_at' => $row->created_at,
                'quantity' => (int) $row->quantity,
                'customer_name' => $customerName,
                'notes' => $row->notes,
                'delivery_notes' => $row->delivery_notes,
                'pickup_notes' => $row->pickup_notes,
            ];
        }

        return $reservedOrders;
    }

}
