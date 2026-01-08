<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        $accountOwner = $this->ensureSalesAccess($user);
        $accountId = $accountOwner->id;

        $filters = $request->only([
            'search',
            'status',
            'customer_id',
            'total_min',
            'total_max',
            'created_from',
            'created_to',
            'sort',
            'direction',
        ]);

        $allowedStatuses = [
            Sale::STATUS_DRAFT,
            Sale::STATUS_PENDING,
            Sale::STATUS_PAID,
            Sale::STATUS_CANCELED,
        ];

        $baseQuery = Sale::query()
            ->where('user_id', $accountId)
            ->when($filters['search'] ?? null, function ($query, $search) {
                $search = trim((string) $search);
                if ($search === '') {
                    return;
                }

                $query->where(function ($query) use ($search) {
                    $query->where('number', 'like', '%' . $search . '%');

                    if (is_numeric($search)) {
                        $query->orWhere('id', (int) $search);
                    }

                    $query->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('company_name', 'like', '%' . $search . '%')
                            ->orWhere('first_name', 'like', '%' . $search . '%')
                            ->orWhere('last_name', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%');
                    });
                });
            })
            ->when($filters['status'] ?? null, function ($query, $status) use ($allowedStatuses) {
                if (in_array($status, $allowedStatuses, true)) {
                    $query->where('status', $status);
                }
            })
            ->when($filters['customer_id'] ?? null, function ($query, $customerId) {
                if (is_numeric($customerId)) {
                    $query->where('customer_id', $customerId);
                }
            })
            ->when($filters['total_min'] ?? null, function ($query, $totalMin) {
                if (is_numeric($totalMin)) {
                    $query->where('total', '>=', $totalMin);
                }
            })
            ->when($filters['total_max'] ?? null, function ($query, $totalMax) {
                if (is_numeric($totalMax)) {
                    $query->where('total', '<=', $totalMax);
                }
            })
            ->when(
                $filters['created_from'] ?? null,
                fn($query, $createdFrom) => $query->whereDate('created_at', '>=', $createdFrom)
            )
            ->when(
                $filters['created_to'] ?? null,
                fn($query, $createdTo) => $query->whereDate('created_at', '<=', $createdTo)
            );

        $sort = in_array($filters['sort'] ?? null, ['number', 'status', 'total', 'created_at'], true)
            ? $filters['sort']
            : 'created_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $sales = (clone $baseQuery)
            ->with('customer:id,first_name,last_name,company_name')
            ->withCount('items')
            ->orderBy($sort, $direction)
            ->simplePaginate(12)
            ->withQueryString();

        $totalCount = (clone $baseQuery)->count();
        $totalValue = (float) (clone $baseQuery)->sum('total');
        $paidValue = (float) (clone $baseQuery)->where('status', Sale::STATUS_PAID)->sum('total');
        $pendingCount = (clone $baseQuery)->where('status', Sale::STATUS_PENDING)->count();
        $draftCount = (clone $baseQuery)->where('status', Sale::STATUS_DRAFT)->count();
        $canceledCount = (clone $baseQuery)->where('status', Sale::STATUS_CANCELED)->count();

        $customers = Customer::query()
            ->where('user_id', $accountId)
            ->orderBy('company_name')
            ->get(['id', 'first_name', 'last_name', 'company_name']);

        return $this->inertiaOrJson('Sales/Index', [
            'sales' => $sales,
            'filters' => $filters,
            'customers' => $customers,
            'stats' => [
                'total' => $totalCount,
                'total_value' => $totalValue,
                'paid_value' => $paidValue,
                'pending' => $pendingCount,
                'draft' => $draftCount,
                'canceled' => $canceledCount,
            ],
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        $accountOwner = $this->ensureSalesAccess($user);
        $accountId = $accountOwner->id;

        $customers = Customer::query()
            ->where('user_id', $accountId)
            ->orderBy('company_name')
            ->get(['id', 'first_name', 'last_name', 'company_name', 'email']);

        $products = Product::query()
            ->where('user_id', $accountId)
            ->where('item_type', Product::ITEM_TYPE_PRODUCT)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'image',
                'price',
                'sku',
                'barcode',
                'stock',
                'minimum_stock',
                'tax_rate',
                'unit',
                'tracking_type',
            ]);

        return $this->inertiaOrJson('Sales/Create', [
            'customers' => $customers,
            'products' => $products,
        ]);
    }

    public function edit(Request $request, Sale $sale)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        $accountOwner = $this->ensureSalesAccess($user);
        $accountId = $accountOwner->id;

        if ($sale->user_id !== $accountId) {
            abort(404);
        }

        $customers = Customer::query()
            ->where('user_id', $accountId)
            ->orderBy('company_name')
            ->get(['id', 'first_name', 'last_name', 'company_name', 'email']);

        $products = Product::query()
            ->where('user_id', $accountId)
            ->where('item_type', Product::ITEM_TYPE_PRODUCT)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'image',
                'price',
                'sku',
                'barcode',
                'stock',
                'minimum_stock',
                'tax_rate',
                'unit',
                'tracking_type',
            ]);

        $sale->load([
            'items.product:id,name,sku,unit,image',
        ]);

        return $this->inertiaOrJson('Sales/Edit', [
            'sale' => $sale,
            'customers' => $customers,
            'products' => $products,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        $accountOwner = $this->ensureSalesAccess($user);
        $accountId = $accountOwner->id;

        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'status' => ['required', Rule::in([
                Sale::STATUS_DRAFT,
                Sale::STATUS_PENDING,
                Sale::STATUS_PAID,
                Sale::STATUS_CANCELED,
            ])],
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.description' => 'nullable|string|max:255',
        ]);

        $customerId = $validated['customer_id'] ?? null;
        if ($customerId) {
            $customerExists = Customer::query()
                ->where('user_id', $accountId)
                ->whereKey($customerId)
                ->exists();
            if (!$customerExists) {
                throw ValidationException::withMessages([
                    'customer_id' => 'Client invalide pour ce compte.',
                ]);
            }
        }

        $productIds = collect($validated['items'])->pluck('product_id')->unique()->values();
        $products = Product::query()
            ->where('user_id', $accountId)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        if ($products->count() !== $productIds->count()) {
            throw ValidationException::withMessages([
                'items' => 'Certains produits sont invalides pour ce compte.',
            ]);
        }

        $errors = [];
        foreach ($validated['items'] as $index => $item) {
            $product = $products->get($item['product_id']);
            if (!$product) {
                $errors["items.{$index}.product_id"] = 'Produit introuvable.';
                continue;
            }

            $quantity = (int) $item['quantity'];
            $available = (int) $product->stock;
            if ($quantity > $available) {
                $errors["items.{$index}.quantity"] = 'Stock insuffisant pour ce produit.';
            }

            if ($product->tracking_type === 'serial') {
                $serialAvailable = ProductLot::query()
                    ->where('product_id', $product->id)
                    ->whereNotNull('serial_number')
                    ->where('quantity', '>', 0)
                    ->count();
                if ($quantity > $serialAvailable) {
                    $errors["items.{$index}.quantity"] = 'Pas assez de numeros de serie disponibles.';
                }
            } elseif ($product->tracking_type === 'lot') {
                $lotAvailable = (int) ProductLot::query()
                    ->where('product_id', $product->id)
                    ->sum('quantity');
                if ($quantity > $lotAvailable) {
                    $errors["items.{$index}.quantity"] = 'Stock de lot insuffisant pour ce produit.';
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $subtotal = 0;
        $taxTotal = 0;
        $itemsPayload = [];

        foreach ($validated['items'] as $item) {
            $product = $products->get($item['product_id']);
            $quantity = (int) $item['quantity'];
            $price = (float) $item['price'];
            $lineTotal = round($price * $quantity, 2);
            $subtotal += $lineTotal;

            $taxRate = (float) ($product?->tax_rate ?? 0);
            $lineTax = $taxRate > 0 ? round($lineTotal * ($taxRate / 100), 2) : 0;
            $taxTotal += $lineTax;

            $itemsPayload[] = [
                'product_id' => $product?->id,
                'description' => $item['description'] ?? $product?->name ?? null,
                'quantity' => $quantity,
                'price' => $price,
                'total' => $lineTotal,
            ];
        }

        $total = round($subtotal + $taxTotal, 2);

        $sale = Sale::create([
            'user_id' => $accountId,
            'customer_id' => $customerId,
            'status' => $validated['status'],
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'total' => $total,
            'notes' => $validated['notes'] ?? null,
            'paid_at' => $validated['status'] === Sale::STATUS_PAID ? now() : null,
        ]);

        foreach ($itemsPayload as $payload) {
            $sale->items()->create($payload);
        }

        if ($validated['status'] === Sale::STATUS_PAID) {
            $inventoryService = app(InventoryService::class);
            $warehouse = $inventoryService->resolveDefaultWarehouse($accountId);

            foreach ($itemsPayload as $payload) {
                $product = $products->get($payload['product_id']);
                if (!$product) {
                    continue;
                }
                $this->applyInventoryForProduct(
                    $product,
                    (int) $payload['quantity'],
                    $inventoryService,
                    $sale,
                    $warehouse
                );
            }
        }

        return redirect()
            ->route('sales.create')
            ->with('success', 'Vente creee.')
            ->with('last_sale_id', $sale->id);
    }

    public function update(Request $request, Sale $sale)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        $accountOwner = $this->ensureSalesAccess($user);
        $accountId = $accountOwner->id;

        if ($sale->user_id !== $accountId) {
            abort(404);
        }

        if (in_array($sale->status, [Sale::STATUS_PAID, Sale::STATUS_CANCELED], true)) {
            throw ValidationException::withMessages([
                'status' => 'Vente deja finalisee.',
            ]);
        }

        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'status' => ['required', Rule::in([
                Sale::STATUS_DRAFT,
                Sale::STATUS_PENDING,
                Sale::STATUS_PAID,
                Sale::STATUS_CANCELED,
            ])],
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.description' => 'nullable|string|max:255',
        ]);

        $customerId = $validated['customer_id'] ?? null;
        if ($customerId) {
            $customerExists = Customer::query()
                ->where('user_id', $accountId)
                ->whereKey($customerId)
                ->exists();
            if (!$customerExists) {
                throw ValidationException::withMessages([
                    'customer_id' => 'Client invalide pour ce compte.',
                ]);
            }
        }

        $productIds = collect($validated['items'])->pluck('product_id')->unique()->values();
        $products = Product::query()
            ->where('user_id', $accountId)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        if ($products->count() !== $productIds->count()) {
            throw ValidationException::withMessages([
                'items' => 'Certains produits sont invalides pour ce compte.',
            ]);
        }

        $errors = [];
        foreach ($validated['items'] as $index => $item) {
            $product = $products->get($item['product_id']);
            if (!$product) {
                $errors["items.{$index}.product_id"] = 'Produit introuvable.';
                continue;
            }

            $quantity = (int) $item['quantity'];
            $available = (int) $product->stock;
            if ($quantity > $available) {
                $errors["items.{$index}.quantity"] = 'Stock insuffisant pour ce produit.';
            }

            if ($product->tracking_type === 'serial') {
                $serialAvailable = ProductLot::query()
                    ->where('product_id', $product->id)
                    ->whereNotNull('serial_number')
                    ->where('quantity', '>', 0)
                    ->count();
                if ($quantity > $serialAvailable) {
                    $errors["items.{$index}.quantity"] = 'Pas assez de numeros de serie disponibles.';
                }
            } elseif ($product->tracking_type === 'lot') {
                $lotAvailable = (int) ProductLot::query()
                    ->where('product_id', $product->id)
                    ->sum('quantity');
                if ($quantity > $lotAvailable) {
                    $errors["items.{$index}.quantity"] = 'Stock de lot insuffisant pour ce produit.';
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $subtotal = 0;
        $taxTotal = 0;
        $itemsPayload = [];

        foreach ($validated['items'] as $item) {
            $product = $products->get($item['product_id']);
            $quantity = (int) $item['quantity'];
            $price = (float) $item['price'];
            $lineTotal = round($price * $quantity, 2);
            $subtotal += $lineTotal;

            $taxRate = (float) ($product?->tax_rate ?? 0);
            $lineTax = $taxRate > 0 ? round($lineTotal * ($taxRate / 100), 2) : 0;
            $taxTotal += $lineTax;

            $itemsPayload[] = [
                'product_id' => $product?->id,
                'description' => $item['description'] ?? $product?->name ?? null,
                'quantity' => $quantity,
                'price' => $price,
                'total' => $lineTotal,
            ];
        }

        $total = round($subtotal + $taxTotal, 2);

        $sale->update([
            'customer_id' => $customerId,
            'status' => $validated['status'],
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'total' => $total,
            'notes' => $validated['notes'] ?? null,
            'paid_at' => $validated['status'] === Sale::STATUS_PAID ? now() : null,
        ]);

        $sale->items()->delete();
        foreach ($itemsPayload as $payload) {
            $sale->items()->create($payload);
        }

        if ($validated['status'] === Sale::STATUS_PAID) {
            $inventoryService = app(InventoryService::class);
            $warehouse = $inventoryService->resolveDefaultWarehouse($accountId);

            foreach ($itemsPayload as $payload) {
                $product = $products->get($payload['product_id']);
                if (!$product) {
                    continue;
                }
                $this->applyInventoryForProduct(
                    $product,
                    (int) $payload['quantity'],
                    $inventoryService,
                    $sale,
                    $warehouse
                );
            }
        }

        return redirect()
            ->route('sales.show', $sale)
            ->with('success', 'Vente mise a jour.');
    }

    public function show(Request $request, Sale $sale)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        $accountOwner = $this->ensureSalesAccess($user);
        $accountId = $accountOwner->id;

        if (!$accountId || $sale->user_id !== $accountId) {
            abort(404);
        }

        $sale->load([
            'customer:id,first_name,last_name,company_name,email,phone',
            'items.product:id,name,sku,unit,image',
        ]);

        return $this->inertiaOrJson('Sales/Show', [
            'sale' => $sale,
        ]);
    }

    private function ensureSalesAccess(User $user): User
    {
        $ownerId = $user->accountOwnerId();
        $owner = $ownerId === $user->id
            ? $user
            : User::query()->find($ownerId);

        if (!$owner || $owner->company_type !== 'products') {
            abort(403);
        }

        if ($user->id !== $owner->id) {
            $membership = $user->relationLoaded('teamMembership')
                ? $user->teamMembership
                : $user->teamMembership()->first();
            if (!$membership || !$membership->hasPermission('sales.manage')) {
                abort(403);
            }
        }

        return $owner;
    }

    private function applyInventoryForProduct(
        Product $product,
        int $quantity,
        InventoryService $inventoryService,
        Sale $sale,
        ?Warehouse $fallbackWarehouse
    ): void {
        if ($quantity <= 0) {
            return;
        }

        $trackingType = $product->tracking_type ?? 'none';

        if ($trackingType === 'serial') {
            $serialLots = ProductLot::query()
                ->where('product_id', $product->id)
                ->whereNotNull('serial_number')
                ->where('quantity', '>', 0)
                ->with('warehouse')
                ->limit($quantity)
                ->get();

            foreach ($serialLots as $lot) {
                $inventoryService->adjust($product, 1, 'out', [
                    'warehouse' => $lot->warehouse ?? $fallbackWarehouse,
                    'reason' => 'sale',
                    'note' => 'Sale ' . $sale->number,
                    'serial_number' => $lot->serial_number,
                    'reference' => $sale,
                ]);
            }

            return;
        }

        if ($trackingType === 'lot') {
            $remaining = $quantity;
            $lots = ProductLot::query()
                ->where('product_id', $product->id)
                ->where('quantity', '>', 0)
                ->with('warehouse')
                ->orderByRaw('expires_at is null, expires_at asc')
                ->get();

            foreach ($lots as $lot) {
                if ($remaining <= 0) {
                    break;
                }
                $useQuantity = min($remaining, (int) $lot->quantity);
                if ($useQuantity <= 0) {
                    continue;
                }

                $inventoryService->adjust($product, $useQuantity, 'out', [
                    'warehouse' => $lot->warehouse ?? $fallbackWarehouse,
                    'reason' => 'sale',
                    'note' => 'Sale ' . $sale->number,
                    'lot_number' => $lot->lot_number,
                    'reference' => $sale,
                ]);

                $remaining -= $useQuantity;
            }

            if ($remaining > 0) {
                $inventoryService->adjust($product, $remaining, 'out', [
                    'warehouse' => $fallbackWarehouse,
                    'reason' => 'sale',
                    'note' => 'Sale ' . $sale->number,
                    'reference' => $sale,
                ]);
            }

            return;
        }

        $inventoryService->adjust($product, $quantity, 'out', [
            'warehouse' => $fallbackWarehouse,
            'reason' => 'sale',
            'note' => 'Sale ' . $sale->number,
            'reference' => $sale,
        ]);
    }
}
