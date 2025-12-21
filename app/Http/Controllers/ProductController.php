<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Utils\FileHandler;
use Illuminate\Http\Request;
use App\Models\ProductCategory;
use App\Http\Requests\ProductRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
            'created_from',
            'created_to',
            'status',
            'sort',
            'direction',
        ]);
        $userId = Auth::user()->id;

        $baseQuery = Product::query()
            ->filter($filters)
            ->byUser($userId);

        $sort = in_array($filters['sort'] ?? null, ['name', 'price', 'stock', 'created_at'], true)
            ? $filters['sort']
            : 'created_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $products = (clone $baseQuery)
            ->with(['category', 'user', 'images', 'stockMovements' => function ($query) {
                $query->limit(5);
            }])
            ->orderBy($sort, $direction)
            ->simplePaginate(7)
            ->withQueryString();

        $totalCount = (clone $baseQuery)->count();
        $totalStock = (clone $baseQuery)->sum('stock');
        $inventoryValue = (clone $baseQuery)
            ->selectRaw('COALESCE(SUM(stock * COALESCE(NULLIF(cost_price, 0), price)), 0) as value')
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
                ->where('quotes.user_id', $userId)
                ->whereIn('quote_products.product_id', $productIds)
                ->select('quote_products.product_id', DB::raw('SUM(quote_products.quantity) as quantity'))
                ->groupBy('quote_products.product_id');

            $workUsage = DB::table('product_works')
                ->join('works', 'product_works.work_id', '=', 'works.id')
                ->where('works.user_id', $userId)
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

        return inertia('Product/Index', [
            'count' => $totalCount,
            'filters' => $filters,
            'categories' => ProductCategory::all(),
            'products' => $products,
            'stats' => $stats,
            'topProducts' => $topProducts,
        ]);
    }

    /**
     * Show the form for creating a new product.
     */
    public function create()
    {
        return inertia('Product/Create', [
            'categories' => ProductCategory::all()
        ]);
    }

    /**
     * Store a newly created product in the database.
     */
    public function store(ProductRequest $request)
    {
        $validated = $request->validated();
        $validated['image'] = FileHandler::handleImageUpload('products', $request, 'image', 'products/product.jpg');
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

        return redirect()->route('product.index')->with('success', 'Product created successfully.');
    }

    /**
     * Display the specified product.
     */
    public function show(Product $product)
    {
        try {
            $this->authorize('update', $product);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return redirect()->back()->with('error', 'You are not authorized to edit this product.');
        }

        return inertia('Product/Show', [
            'product' => $product->load(['category', 'user', 'images', 'stockMovements' => function ($query) {
                $query->limit(10);
            }]),
            'categories' => ProductCategory::all()
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
    public function quickUpdate(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $data = $request->validate([
            'price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'margin_percent' => 'nullable|numeric|min:0|max:100',
            'stock' => 'nullable|integer|min:0',
            'minimum_stock' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $data = array_filter($data, static fn($value) => $value !== null);
        $product->update($data);

        return redirect()->back()->with('success', 'Product updated successfully.');
    }

    /**
     * Adjust stock levels and create a stock movement record.
     */
    public function adjustStock(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $data = $request->validate([
            'type' => 'required|in:in,out,adjust',
            'quantity' => 'required|integer',
            'note' => 'nullable|string|max:255',
        ]);

        $quantity = (int) $data['quantity'];
        $delta = match ($data['type']) {
            'in' => abs($quantity),
            'out' => -abs($quantity),
            'adjust' => $quantity,
        };

        $product->stock = max(0, $product->stock + $delta);
        $product->save();

        $product->stockMovements()->create([
            'user_id' => Auth::id(),
            'type' => $data['type'],
            'quantity' => $delta,
            'note' => $data['note'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Stock updated successfully.');
    }

    /**
     * Bulk actions on products.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => 'required|in:archive,restore,delete',
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $products = Product::byUser(Auth::id())
            ->whereIn('id', $data['ids'])
            ->get();

        if ($data['action'] === 'archive') {
            foreach ($products as $product) {
                $this->authorize('update', $product);
            }
            Product::byUser(Auth::id())->whereIn('id', $data['ids'])->update(['is_active' => false]);
            return redirect()->back()->with('success', 'Products archived.');
        }

        if ($data['action'] === 'restore') {
            foreach ($products as $product) {
                $this->authorize('update', $product);
            }
            Product::byUser(Auth::id())->whereIn('id', $data['ids'])->update(['is_active' => true]);
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

        return redirect()->back()->with('success', 'Products deleted.');
    }

    /**
     * Duplicate a product with its images.
     */
    public function duplicate(Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

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

        return redirect()->back()->with('success', 'Product duplicated.');
    }

    /**
     * Export products as CSV.
     */
    public function export(Request $request)
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
            'created_from',
            'created_to',
            'status',
            'sort',
            'direction',
        ]);

        $query = Product::query()
            ->filter($filters)
            ->byUser(Auth::id())
            ->with('category');

        $filename = 'products-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'name',
                'sku',
                'barcode',
                'unit',
                'supplier_name',
                'price',
                'cost_price',
                'margin_percent',
                'stock',
                'minimum_stock',
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
                            $product->price,
                            $product->cost_price,
                            $product->margin_percent,
                            $product->stock,
                            $product->minimum_stock,
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
    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10000',
        ]);

        $file = $data['file'];
        $handle = fopen($file->getRealPath(), 'r');
        if (!$handle) {
            return redirect()->back()->with('error', 'Unable to read import file.');
        }

        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
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
                $category = ProductCategory::firstOrCreate(['name' => $categoryName]);
                $categoryId = $category->id;
            }

            $payload = [
                'name' => $dataRow['name'],
                'sku' => $dataRow['sku'] ?? null,
                'barcode' => $dataRow['barcode'] ?? null,
                'unit' => $dataRow['unit'] ?? null,
                'supplier_name' => $dataRow['supplier_name'] ?? null,
                'price' => $dataRow['price'] ?? 0,
                'cost_price' => $dataRow['cost_price'] ?? 0,
                'margin_percent' => $dataRow['margin_percent'] ?? 0,
                'stock' => $dataRow['stock'] ?? 0,
                'minimum_stock' => $dataRow['minimum_stock'] ?? 0,
                'tax_rate' => $dataRow['tax_rate'] ?? null,
                'category_id' => $categoryId,
                'is_active' => ($dataRow['is_active'] ?? '1') === '1',
            ];

            $query = Product::byUser(Auth::id());
            if (!empty($payload['sku'])) {
                $query->where('sku', $payload['sku']);
            } else {
                $query->where('name', $payload['name']);
            }

            $existing = $query->first();
            if ($existing) {
                $existing->update(array_filter($payload, static fn($value) => $value !== null));
            } else {
                $payload['user_id'] = Auth::id();
                if (!$payload['category_id']) {
                    $payload['category_id'] = ProductCategory::first()->id ?? null;
                }
                if ($payload['category_id']) {
                    Product::create($payload);
                }
            }

            $imported += 1;
        }

        fclose($handle);

        return redirect()->back()->with('success', "Imported {$imported} products.");
    }
    /**
     * Update the specified product in the database.
     */
    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        try {
            $this->authorize('update', $product);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return redirect()->back()->with('error', 'You are not authorized to edit this product.');
        }

        $validated = $request->validated();
        $validated['image'] = FileHandler::handleImageUpload('products', $request, 'image', 'products/product.jpg', $product->image);
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

        if ($request->hasFile('image') || $product->images()->where('is_primary', true)->doesntExist()) {
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

        return redirect()->route('product.index')->with('success', 'Product updated successfully.');
    }

    /**
     * Remove the specified product from the database.
     */
    public function destroy(Product $product)
    {
        $this->authorize('delete', $product);

        foreach ($product->images as $image) {
            FileHandler::deleteFile($image->path, 'products/product.jpg');
        }
        FileHandler::deleteFile($product->image, 'products/product.jpg');
        $product->delete();

        return redirect()->route('product.index')->with('success', 'Product deleted successfully.');
    }

}
