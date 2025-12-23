<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceRequest;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Utils\FileHandler;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
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

        $userId = Auth::id();

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

        return inertia('Service/Index', [
            'filters' => $filters,
            'services' => $services,
            'categories' => ProductCategory::orderBy('name')->get(['id', 'name']),
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

        return response()->json([
            'categories' => ProductCategory::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(ServiceRequest $request): RedirectResponse
    {
        $this->ensureServiceAccess();

        $validated = $request->validated();
        $validated['item_type'] = Product::ITEM_TYPE_SERVICE;
        $validated['stock'] = 0;
        $validated['minimum_stock'] = 0;
        $validated['image'] = FileHandler::handleImageUpload('services', $request, 'image', 'products/product.jpg');

        $service = $request->user()->products()->create($validated);

        if ($request->has('materials')) {
            $this->syncServiceMaterials($service, $validated['materials'] ?? []);
        }

        return redirect()->route('service.index')->with('success', 'Service created successfully.');
    }

    public function storeQuick(ServiceRequest $request)
    {
        $this->ensureServiceAccess();

        $validated = $request->validated();
        $validated['item_type'] = Product::ITEM_TYPE_SERVICE;
        $validated['stock'] = 0;
        $validated['minimum_stock'] = 0;

        $service = $request->user()->products()->create($validated);

        return response()->json([
            'service' => [
                'id' => $service->id,
                'name' => $service->name,
                'price' => $service->price,
            ],
        ], 201);
    }

    public function update(ServiceRequest $request, Product $service): RedirectResponse
    {
        $this->ensureServiceAccess();
        $this->authorize('update', $service);
        $this->ensureServiceItem($service);

        $validated = $request->validated();
        $validated['item_type'] = Product::ITEM_TYPE_SERVICE;
        $validated['stock'] = 0;
        $validated['minimum_stock'] = 0;
        $validated['image'] = FileHandler::handleImageUpload('services', $request, 'image', 'products/product.jpg', $service->image);

        $service->update($validated);

        if ($request->has('materials')) {
            $this->syncServiceMaterials($service, $validated['materials'] ?? []);
        }

        return redirect()->route('service.index')->with('success', 'Service updated successfully.');
    }

    public function destroy(Product $service): RedirectResponse
    {
        $this->ensureServiceAccess();
        $this->authorize('delete', $service);
        $this->ensureServiceItem($service);

        FileHandler::deleteFile($service->image, 'products/product.jpg');
        $service->delete();

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
