<?php

namespace App\Http\Controllers\Api\Integration;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\ProductStockMovement;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function products(Request $request)
    {
        $this->ensureAbility($request, 'inventory:read');

        $filters = $request->only([
            'name',
            'category_id',
            'stock_status',
            'updated_from',
            'updated_to',
        ]);

        $accountId = $request->user()?->accountOwnerId() ?? $request->user()?->id;

        $query = Product::query()
            ->products()
            ->byUser($accountId)
            ->when($filters['name'] ?? null, function ($query, $name) {
                $query->where(function ($query) use ($name) {
                    $query->where('name', 'like', '%' . $name . '%')
                        ->orWhere('description', 'like', '%' . $name . '%')
                        ->orWhere('sku', 'like', '%' . $name . '%')
                        ->orWhere('barcode', 'like', '%' . $name . '%');
                });
            })
            ->when($filters['category_id'] ?? null, fn ($query, $categoryId) => $query->where('category_id', $categoryId))
            ->when($filters['stock_status'] ?? null, function ($query, $status) {
                if ($status === 'in') {
                    $query->where('stock', '>', 0)
                        ->whereColumn('stock', '>', 'minimum_stock');
                } elseif ($status === 'low') {
                    $query->whereColumn('stock', '<=', 'minimum_stock')
                        ->where('stock', '>', 0);
                } elseif ($status === 'out') {
                    $query->where('stock', '<=', 0);
                }
            })
            ->when($filters['updated_from'] ?? null, fn ($query, $date) => $query->whereDate('updated_at', '>=', $date))
            ->when($filters['updated_to'] ?? null, fn ($query, $date) => $query->whereDate('updated_at', '<=', $date))
            ->withSum('inventories as on_hand_total', 'on_hand')
            ->withSum('inventories as reserved_total', 'reserved')
            ->withSum('inventories as damaged_total', 'damaged')
            ->withCount('inventories as warehouse_count')
            ->with('category:id,name');

        $products = $query->orderByDesc('updated_at')->paginate(25);

        return response()->json([
            'products' => $products,
        ]);
    }

    public function product(Request $request, Product $product)
    {
        $this->ensureAbility($request, 'inventory:read');

        $accountId = $request->user()?->accountOwnerId() ?? $request->user()?->id;
        if ((int) $product->user_id !== (int) $accountId) {
            abort(403);
        }

        return response()->json([
            'product' => $product->load([
                'category:id,name',
                'inventories.warehouse:id,name,code',
                'lots',
                'stockMovements' => function ($query) {
                    $query->latest()->limit(50)->with(['warehouse:id,name,code', 'lot:id,lot_number,serial_number']);
                },
            ]),
        ]);
    }

    public function warehouses(Request $request)
    {
        $this->ensureAbility($request, 'inventory:read');

        $accountId = $request->user()?->accountOwnerId() ?? $request->user()?->id;
        $warehouses = Warehouse::query()
            ->forAccount($accountId)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'is_default', 'is_active']);

        return response()->json([
            'warehouses' => $warehouses,
        ]);
    }

    public function movements(Request $request)
    {
        $this->ensureAbility($request, 'inventory:read');

        $filters = $request->only(['product_id', 'warehouse_id', 'type', 'from', 'to']);
        $accountId = $request->user()?->accountOwnerId() ?? $request->user()?->id;

        $query = ProductStockMovement::query()
            ->whereHas('product', fn ($query) => $query->byUser($accountId))
            ->when($filters['product_id'] ?? null, fn ($query, $productId) => $query->where('product_id', $productId))
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query->where('warehouse_id', $warehouseId))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->with([
                'product:id,name,sku,barcode',
                'warehouse:id,name,code',
                'lot:id,lot_number,serial_number',
            ])
            ->orderByDesc('created_at');

        return response()->json([
            'movements' => $query->paginate(50),
        ]);
    }

    public function adjust(Request $request, Product $product)
    {
        $this->ensureAbility($request, 'inventory:write');

        $accountId = $request->user()?->accountOwnerId() ?? $request->user()?->id;
        if ((int) $product->user_id !== (int) $accountId) {
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
            'actor_id' => $request->user()?->id,
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'account_id' => $accountId,
            'reason' => $data['reason'] ?? 'api',
            'note' => $data['note'] ?? null,
            'lot_number' => $data['lot_number'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'received_at' => $data['received_at'] ?? null,
            'unit_cost' => $data['unit_cost'] ?? null,
        ]);

        return response()->json([
            'message' => 'Stock updated successfully.',
            'movement' => $movement->load(['warehouse:id,name,code', 'lot:id,lot_number,serial_number']),
            'product' => $product->fresh(),
        ]);
    }

    public function alerts(Request $request)
    {
        $this->ensureAbility($request, 'inventory:read');

        $accountId = $request->user()?->accountOwnerId() ?? $request->user()?->id;
        $days = (int) ($request->input('days') ?? 30);
        $threshold = now()->addDays(max(1, $days));

        $baseQuery = Product::query()->products()->byUser($accountId);

        $lowStock = (clone $baseQuery)
            ->whereColumn('stock', '<=', 'minimum_stock')
            ->where('stock', '>', 0)
            ->orderBy('stock')
            ->limit(50)
            ->get(['id', 'name', 'stock', 'minimum_stock']);

        $outOfStock = (clone $baseQuery)
            ->where('stock', '<=', 0)
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'stock', 'minimum_stock']);

        $expiringLots = ProductLot::query()
            ->whereDate('expires_at', '<=', $threshold)
            ->whereHas('product', fn ($query) => $query->byUser($accountId))
            ->with(['product:id,name', 'warehouse:id,name'])
            ->orderBy('expires_at')
            ->limit(50)
            ->get();

        return response()->json([
            'low_stock' => $lowStock,
            'out_of_stock' => $outOfStock,
            'expiring_lots' => $expiringLots,
        ]);
    }

    private function ensureAbility(Request $request, string $ability): void
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $token = $user->currentAccessToken();
        if ($token && !$user->tokenCan($ability)) {
            abort(403);
        }
    }
}
