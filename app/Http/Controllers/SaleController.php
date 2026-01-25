<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\SaleNotificationService;
use App\Services\SaleTimelineService;
use App\Services\StripeSaleService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        [$accountOwner, $canManage, $canPos] = $this->resolveSalesAccess($user);
        $accountId = $accountOwner->id;
        $canAccessAll = $canManage || $canPos;

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
        $filters['status'] = Sale::STATUS_PAID;

        $allowedStatuses = [
            Sale::STATUS_PAID,
        ];

        $baseQuery = Sale::query()
            ->where('user_id', $accountId)
            ->where('status', Sale::STATUS_PAID)
            ->when(!$canAccessAll, fn($query) => $query->where('created_by_user_id', $user->id))
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
        $pendingCount = 0;
        $draftCount = 0;
        $canceledCount = 0;

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

    public function ordersIndex(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        [$accountOwner, $canManage, $canPos] = $this->resolveSalesAccess($user);
        $accountId = $accountOwner->id;
        $canAccessAll = $canManage || $canPos;

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
            Sale::STATUS_CANCELED,
        ];

        $baseQuery = Sale::query()
            ->where('user_id', $accountId)
            ->whereIn('status', $allowedStatuses)
            ->when(!$canAccessAll, fn($query) => $query->where('created_by_user_id', $user->id))
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

        $orders = (clone $baseQuery)
            ->with('customer:id,first_name,last_name,company_name')
            ->withCount('items')
            ->withSum(['payments as payments_sum_amount' => fn($query) => $query->where('status', 'completed')], 'amount')
            ->orderBy($sort, $direction)
            ->simplePaginate(12)
            ->withQueryString();

        $totalCount = (clone $baseQuery)->count();
        $totalValue = (float) (clone $baseQuery)->sum('total');
        $pendingCount = (clone $baseQuery)->where('status', Sale::STATUS_PENDING)->count();
        $draftCount = (clone $baseQuery)->where('status', Sale::STATUS_DRAFT)->count();
        $canceledCount = (clone $baseQuery)->where('status', Sale::STATUS_CANCELED)->count();

        $customers = Customer::query()
            ->where('user_id', $accountId)
            ->orderBy('company_name')
            ->get(['id', 'first_name', 'last_name', 'company_name']);

        return $this->inertiaOrJson('Orders/Index', [
            'orders' => $orders,
            'filters' => $filters,
            'customers' => $customers,
            'stats' => [
                'total' => $totalCount,
                'total_value' => $totalValue,
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
        [$accountOwner] = $this->resolveSalesAccess($user);
        $accountId = $accountOwner->id;

        $customers = Customer::query()
            ->where('user_id', $accountId)
            ->orderBy('company_name')
            ->get(['id', 'first_name', 'last_name', 'company_name', 'email', 'phone', 'discount_rate']);

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
            'stripe' => [
                'enabled' => app(StripeSaleService::class)->isConfigured(),
            ],
        ]);
    }

    public function edit(Request $request, Sale $sale)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        [$accountOwner, $canManage, $canPos] = $this->resolveSalesAccess($user);
        $accountId = $accountOwner->id;
        $canAccessAll = $canManage || $canPos;

        if ($sale->user_id !== $accountId) {
            abort(404);
        }
        if (!$canAccessAll && $sale->created_by_user_id !== $user->id) {
            abort(404);
        }

        $customers = Customer::query()
            ->where('user_id', $accountId)
            ->orderBy('company_name')
            ->get(['id', 'first_name', 'last_name', 'company_name', 'email', 'phone', 'discount_rate']);

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
        [$accountOwner] = $this->resolveSalesAccess($user);
        $accountId = $accountOwner->id;

        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'status' => ['required', Rule::in([
                Sale::STATUS_DRAFT,
                Sale::STATUS_PENDING,
                Sale::STATUS_PAID,
                Sale::STATUS_CANCELED,
            ])],
            'payment_method' => ['nullable', Rule::in(['cash', 'card'])],
            'pay_with_stripe' => 'nullable|boolean',
            'fulfillment_status' => ['nullable', Rule::in($this->allowedFulfillmentStatuses())],
            'notes' => 'nullable|string|max:2000',
            'scheduled_for' => 'nullable|date',
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

        $reservedMap = [];

        foreach ($reservedMap as $productId => $quantity) {
            $product = $products->get($productId);
            if ($product) {
                $product->stock = (int) $product->stock + $quantity;
            }
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

        $paymentMethod = $validated['payment_method'] ?? 'cash';
        $payWithStripe = $paymentMethod === 'card' && $validated['status'] === Sale::STATUS_PAID;
        $stripeService = $payWithStripe ? app(StripeSaleService::class) : null;
        if ($payWithStripe && !$stripeService?->isConfigured()) {
            throw ValidationException::withMessages([
                'status' => 'Stripe n est pas configure.',
            ]);
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

        $discountRate = 0;
        if ($customerId) {
            $discountRate = (float) Customer::query()
                ->where('user_id', $accountId)
                ->whereKey($customerId)
                ->value('discount_rate');
        }
        $discountRate = min(100, max(0, $discountRate));
        $discountTotal = round($subtotal * ($discountRate / 100), 2);
        $discountedSubtotal = max(0, $subtotal - $discountTotal);
        $discountedTaxTotal = round($taxTotal * (1 - ($discountRate / 100)), 2);
        $total = round($discountedSubtotal + $discountedTaxTotal, 2);

        if ($payWithStripe && $total <= 0) {
            throw ValidationException::withMessages([
                'status' => 'Montant invalide pour Stripe.',
            ]);
        }

        $status = $payWithStripe ? Sale::STATUS_PENDING : $validated['status'];
        $fulfillmentStatus = $validated['fulfillment_status'] ?? null;
        if (!$payWithStripe && $status === Sale::STATUS_PAID && !$this->isFulfillmentComplete($fulfillmentStatus)) {
            $fulfillmentStatus = Sale::FULFILLMENT_COMPLETED;
        }

        $paymentProvider = null;
        if ($payWithStripe) {
            $paymentProvider = 'stripe';
        } elseif ($status === Sale::STATUS_PAID) {
            $paymentProvider = $paymentMethod;
        }

        $sale = Sale::create([
            'user_id' => $accountId,
            'created_by_user_id' => $user->id,
            'customer_id' => $customerId,
            'status' => $status,
            'payment_provider' => $paymentProvider,
            'subtotal' => $subtotal,
            'tax_total' => $discountedTaxTotal,
            'discount_rate' => $discountRate,
            'discount_total' => $discountTotal,
            'total' => $total,
            'fulfillment_status' => $fulfillmentStatus,
            'notes' => $validated['notes'] ?? null,
            'scheduled_for' => $validated['scheduled_for'] ?? null,
            'paid_at' => $status === Sale::STATUS_PAID ? now() : null,
            'source' => 'pos',
        ]);

        foreach ($itemsPayload as $payload) {
            $sale->items()->create($payload);
        }

        if (
            $sale->status === Sale::STATUS_PENDING
            && !$this->isFulfillmentComplete($sale->fulfillment_status)
        ) {
            $this->applyReservations($sale, $itemsPayload, $accountId, []);
        }

        if (
            $sale->fulfillment_method === 'pickup'
            && $sale->fulfillment_status === Sale::FULFILLMENT_READY_FOR_PICKUP
            && !$sale->pickup_code
        ) {
            $sale->forceFill([
                'pickup_code' => $this->generatePickupCode(),
            ])->save();
        }

        if (
            $sale->status === Sale::STATUS_PAID
            || $this->isFulfillmentComplete($sale->fulfillment_status)
        ) {
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

        app(SaleTimelineService::class)->record($user, $sale, 'sale_created', [
            'source' => 'pos',
        ]);

        if ($payWithStripe && $stripeService) {
            $successUrl = URL::route('sales.show', ['sale' => $sale->id, 'stripe' => 'success']);
            $successUrl .= (str_contains($successUrl, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}';
            $cancelUrl = URL::route('sales.show', ['sale' => $sale->id, 'stripe' => 'cancel']);

            $session = $stripeService->createCheckoutSession($sale, $successUrl, $cancelUrl);
            if (empty($session['url'])) {
                throw ValidationException::withMessages([
                    'status' => 'Impossible de demarrer le paiement Stripe.',
                ]);
            }

            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Paiement Stripe initialise.',
                    'sale' => $this->loadSaleForResponse($sale),
                    'checkout_url' => $session['url'],
                    'checkout_session_id' => $session['id'] ?? null,
                ]);
            }

            if ($request->header('X-Inertia')) {
                return Inertia::location($session['url']);
            }

            return redirect()->away($session['url']);
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Vente creee.',
                'sale' => $this->loadSaleForResponse($sale),
            ]);
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
        [$accountOwner, $canManage, $canPos] = $this->resolveSalesAccess($user);
        $accountId = $accountOwner->id;
        $canAccessAll = $canManage || $canPos;

        if ($sale->user_id !== $accountId) {
            abort(404);
        }
        if (!$canAccessAll && $sale->created_by_user_id !== $user->id) {
            abort(404);
        }

        if (in_array($sale->status, [Sale::STATUS_PAID, Sale::STATUS_CANCELED], true)) {
            throw ValidationException::withMessages([
                'status' => 'Vente deja finalisee.',
            ]);
        }

        if ($this->isFulfillmentComplete($sale->fulfillment_status)) {
            throw ValidationException::withMessages([
                'status' => 'Vente deja livree.',
            ]);
        }

        $previousStatus = $sale->status;
        $previousFulfillment = $sale->fulfillment_status;
        $previousScheduled = $sale->scheduled_for;
        $previousItems = $sale->items()->get(['product_id', 'quantity']);

        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'status' => ['required', Rule::in([
                Sale::STATUS_DRAFT,
                Sale::STATUS_PENDING,
                Sale::STATUS_PAID,
                Sale::STATUS_CANCELED,
            ])],
            'fulfillment_status' => ['nullable', Rule::in($this->allowedFulfillmentStatuses())],
            'notes' => 'nullable|string|max:2000',
            'scheduled_for' => 'nullable|date',
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

        $discountRate = 0;
        if ($customerId) {
            $discountRate = (float) Customer::query()
                ->where('user_id', $accountId)
                ->whereKey($customerId)
                ->value('discount_rate');
        }
        $discountRate = min(100, max(0, $discountRate));
        $discountTotal = round($subtotal * ($discountRate / 100), 2);
        $discountedSubtotal = max(0, $subtotal - $discountTotal);
        $discountedTaxTotal = round($taxTotal * (1 - ($discountRate / 100)), 2);
        $total = round($discountedSubtotal + $discountedTaxTotal, 2);

        $updateData = [
            'customer_id' => $customerId,
            'status' => $validated['status'],
            'subtotal' => $subtotal,
            'tax_total' => $discountedTaxTotal,
            'discount_rate' => $discountRate,
            'discount_total' => $discountTotal,
            'total' => $total,
            'fulfillment_status' => array_key_exists('fulfillment_status', $validated)
                ? $validated['fulfillment_status']
                : $sale->fulfillment_status,
            'notes' => $validated['notes'] ?? null,
            'paid_at' => $validated['status'] === Sale::STATUS_PAID ? now() : null,
        ];

        if (array_key_exists('scheduled_for', $validated)) {
            $updateData['scheduled_for'] = $validated['scheduled_for'];
        }

        if (
            $updateData['status'] === Sale::STATUS_PAID
            && !$this->isFulfillmentComplete($updateData['fulfillment_status'])
        ) {
            if (!$sale->fulfillment_method) {
                $updateData['fulfillment_status'] = Sale::FULFILLMENT_COMPLETED;
            } else {
                throw ValidationException::withMessages([
                    'status' => 'Le paiement est autorise apres livraison ou retrait.',
                ]);
            }
        }
        if ($updateData['fulfillment_status'] === Sale::FULFILLMENT_CONFIRMED
            && $sale->fulfillment_status !== Sale::FULFILLMENT_COMPLETED) {
            throw ValidationException::withMessages([
                'fulfillment_status' => 'La confirmation est possible apres la livraison.',
            ]);
        }
        if ($updateData['fulfillment_status'] === Sale::FULFILLMENT_CONFIRMED && !$sale->delivery_confirmed_at) {
            $updateData['delivery_confirmed_at'] = now();
            $updateData['delivery_confirmed_by_user_id'] = $user->id;
        }

        $sale->update($updateData);

        $sale->items()->delete();
        foreach ($itemsPayload as $payload) {
            $sale->items()->create($payload);
        }

        $wasPending = $previousStatus === Sale::STATUS_PENDING
            && !$this->isFulfillmentComplete($previousFulfillment);
        $isPending = $sale->status === Sale::STATUS_PENDING
            && !$this->isFulfillmentComplete($sale->fulfillment_status);

        if ($isPending) {
            $currentItems = $wasPending ? $previousItems : [];
            $this->applyReservations($sale, $itemsPayload, $accountId, $currentItems);
        } elseif ($wasPending) {
            $this->applyReservations($sale, [], $accountId, $previousItems);
        }

        if (
            $sale->fulfillment_method === 'pickup'
            && $sale->fulfillment_status === Sale::FULFILLMENT_READY_FOR_PICKUP
            && !$sale->pickup_code
        ) {
            $sale->forceFill([
                'pickup_code' => $this->generatePickupCode(),
            ])->save();
        }

        $inventoryAlreadyApplied = $previousStatus === Sale::STATUS_PAID
            || $this->isFulfillmentComplete($previousFulfillment);

        if (
            !$inventoryAlreadyApplied
            && ($sale->status === Sale::STATUS_PAID || $this->isFulfillmentComplete($sale->fulfillment_status))
        ) {
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

        $timeline = app(SaleTimelineService::class);
        $timeline->record($user, $sale, 'sale_updated');

        $changes = [];
        if ($previousStatus !== $sale->status) {
            $timeline->record($user, $sale, 'sale_status_changed', [
                'status_from' => $previousStatus,
                'status_to' => $sale->status,
            ]);
            $changes['status'] = true;
        }

        if ($previousFulfillment !== $sale->fulfillment_status) {
            $timeline->record($user, $sale, 'sale_fulfillment_changed', [
                'fulfillment_from' => $previousFulfillment,
                'fulfillment_to' => $sale->fulfillment_status,
            ]);
            $changes['fulfillment_status'] = true;
        }

        if ($previousScheduled?->toDateTimeString() !== $sale->scheduled_for?->toDateTimeString()) {
            $timeline->record($user, $sale, 'sale_eta_updated', [
                'scheduled_for' => $sale->scheduled_for?->format('Y-m-d H:i'),
            ]);
            $changes['scheduled_for'] = true;
        }

        if ($changes) {
            $sale->loadMissing('customer');
            app(SaleNotificationService::class)->notifyStatusChange($sale, $changes);
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Vente mise a jour.',
                'sale' => $this->loadSaleForResponse($sale),
            ]);
        }

        return redirect()
            ->route('sales.show', $sale)
            ->with('success', 'Vente mise a jour.');
    }

    public function updateStatus(Request $request, Sale $sale)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        [$accountOwner, $canManage, $canPos] = $this->resolveSalesAccess($user);
        $accountId = $accountOwner->id;
        $canAccessAll = $canManage || $canPos;

        if ($sale->user_id !== $accountId) {
            abort(404);
        }
        if (!$canAccessAll && $sale->created_by_user_id !== $user->id) {
            abort(404);
        }

        if (in_array($sale->status, [Sale::STATUS_PAID, Sale::STATUS_CANCELED], true)) {
            throw ValidationException::withMessages([
                'status' => 'Commande deja finalisee.',
            ]);
        }

        $validated = $request->validate([
            'status' => ['nullable', Rule::in([
                Sale::STATUS_DRAFT,
                Sale::STATUS_PENDING,
                Sale::STATUS_PAID,
                Sale::STATUS_CANCELED,
            ])],
            'fulfillment_status' => ['nullable', Rule::in($this->allowedFulfillmentStatuses())],
            'scheduled_for' => 'nullable|date',
        ]);

        if (!array_key_exists('status', $validated) && !array_key_exists('fulfillment_status', $validated)
            && !array_key_exists('scheduled_for', $validated)) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Aucune modification.',
                    'sale' => $this->loadSaleForResponse($sale),
                ]);
            }
            return redirect()->back();
        }

        $nextStatus = array_key_exists('status', $validated) && $validated['status'] !== null
            ? $validated['status']
            : $sale->status;
        $nextFulfillment = array_key_exists('fulfillment_status', $validated) && $validated['fulfillment_status'] !== null
            ? $validated['fulfillment_status']
            : $sale->fulfillment_status;
        $fulfillmentChanging = array_key_exists('fulfillment_status', $validated)
            && $nextFulfillment !== $sale->fulfillment_status;
        $scheduledChanging = array_key_exists('scheduled_for', $validated);

        if ($this->isFulfillmentComplete($sale->fulfillment_status)) {
            if ($fulfillmentChanging || $scheduledChanging) {
                throw ValidationException::withMessages([
                    'fulfillment_status' => 'Commande deja livree.',
                ]);
            }
            if ($nextStatus === Sale::STATUS_CANCELED) {
                throw ValidationException::withMessages([
                    'status' => 'Commande deja livree.',
                ]);
            }
        }

        if ($sale->fulfillment_method === 'pickup' && $nextFulfillment === Sale::FULFILLMENT_OUT_FOR_DELIVERY) {
            throw ValidationException::withMessages([
                'fulfillment_status' => 'Statut livraison invalide pour un retrait.',
            ]);
        }
        if ($sale->fulfillment_method === 'delivery' && $nextFulfillment === Sale::FULFILLMENT_READY_FOR_PICKUP) {
            throw ValidationException::withMessages([
                'fulfillment_status' => 'Statut retrait invalide pour une livraison.',
            ]);
        }
        if ($fulfillmentChanging
            && $nextFulfillment === Sale::FULFILLMENT_CONFIRMED
            && $sale->fulfillment_status !== Sale::FULFILLMENT_COMPLETED) {
            throw ValidationException::withMessages([
                'fulfillment_status' => 'La confirmation est possible apres la livraison.',
            ]);
        }
        if ($nextStatus === Sale::STATUS_PAID && !$this->isFulfillmentComplete($nextFulfillment)) {
            if (!$sale->fulfillment_method) {
                $nextFulfillment = Sale::FULFILLMENT_COMPLETED;
            } else {
                throw ValidationException::withMessages([
                    'status' => 'Le paiement est autorise apres livraison ou retrait.',
                ]);
            }
        }

        if ($nextStatus === Sale::STATUS_CANCELED) {
            $nextFulfillment = null;
        }

        $amountPaid = (float) $sale->payments()
            ->where('status', 'completed')
            ->sum('amount');
        $paymentComplete = (float) $sale->total > 0 && $amountPaid >= (float) $sale->total;
        if ($nextStatus !== Sale::STATUS_CANCELED && $paymentComplete && $this->isFulfillmentComplete($nextFulfillment)) {
            $nextStatus = Sale::STATUS_PAID;
        }

        if ($sale->status === Sale::STATUS_DRAFT && $nextStatus === Sale::STATUS_DRAFT && $nextFulfillment) {
            $nextStatus = Sale::STATUS_PENDING;
        }

        $previousStatus = $sale->status;
        $previousFulfillment = $sale->fulfillment_status;
        $previousScheduled = $sale->scheduled_for;
        $previousItems = $sale->items()->get(['product_id', 'quantity']);

        $updateData = [
            'status' => $nextStatus,
            'fulfillment_status' => $nextFulfillment,
            'paid_at' => $nextStatus === Sale::STATUS_PAID ? ($sale->paid_at ?? now()) : null,
        ];

        if (array_key_exists('scheduled_for', $validated)) {
            $updateData['scheduled_for'] = $validated['scheduled_for'];
        }
        if ($nextFulfillment === Sale::FULFILLMENT_CONFIRMED && !$sale->delivery_confirmed_at) {
            $updateData['delivery_confirmed_at'] = now();
            $updateData['delivery_confirmed_by_user_id'] = $user->id;
        }

        $depositRequested = false;
        $depositAmount = 0.0;
        if (
            $fulfillmentChanging
            && $sale->source === 'portal'
            && $nextFulfillment === Sale::FULFILLMENT_PREPARING
            && (float) ($sale->deposit_amount ?? 0) <= 0
        ) {
            $depositAmount = round(((float) $sale->total) * 0.2, 2);
            if ($depositAmount > 0) {
                $updateData['deposit_amount'] = $depositAmount;
                $depositRequested = true;
            }
        }

        $sale->update($updateData);

        $itemsPayload = $previousItems->map(fn($item) => [
            'product_id' => $item->product_id,
            'quantity' => (int) $item->quantity,
        ])->values()->all();

        $wasPending = $previousStatus === Sale::STATUS_PENDING
            && !$this->isFulfillmentComplete($previousFulfillment);
        $isPending = $sale->status === Sale::STATUS_PENDING
            && !$this->isFulfillmentComplete($sale->fulfillment_status);

        if ($isPending) {
            $currentItems = $wasPending ? $previousItems : [];
            $this->applyReservations($sale, $itemsPayload, $accountId, $currentItems);
        } elseif ($wasPending) {
            $this->applyReservations($sale, [], $accountId, $previousItems);
        }

        if (
            $sale->fulfillment_method === 'pickup'
            && $sale->fulfillment_status === Sale::FULFILLMENT_READY_FOR_PICKUP
            && !$sale->pickup_code
        ) {
            $sale->forceFill([
                'pickup_code' => $this->generatePickupCode(),
            ])->save();
        }

        $inventoryAlreadyApplied = $previousStatus === Sale::STATUS_PAID
            || $this->isFulfillmentComplete($previousFulfillment);

        if (
            !$inventoryAlreadyApplied
            && ($sale->status === Sale::STATUS_PAID || $this->isFulfillmentComplete($sale->fulfillment_status))
        ) {
            $inventoryService = app(InventoryService::class);
            $warehouse = $inventoryService->resolveDefaultWarehouse($accountId);
            $productIds = $previousItems->pluck('product_id')->unique()->values();
            $products = Product::query()
                ->where('user_id', $accountId)
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id');

            foreach ($previousItems as $item) {
                $product = $products->get($item->product_id);
                if (!$product) {
                    continue;
                }
                $this->applyInventoryForProduct(
                    $product,
                    (int) $item->quantity,
                    $inventoryService,
                    $sale,
                    $warehouse
                );
            }
        }

        $timeline = app(SaleTimelineService::class);
        $changes = [];
        if ($previousStatus !== $sale->status) {
            $timeline->record($user, $sale, 'sale_status_changed', [
                'status_from' => $previousStatus,
                'status_to' => $sale->status,
            ]);
            $changes['status'] = true;
        }

        if ($previousFulfillment !== $sale->fulfillment_status) {
            $timeline->record($user, $sale, 'sale_fulfillment_changed', [
                'fulfillment_from' => $previousFulfillment,
                'fulfillment_to' => $sale->fulfillment_status,
            ]);
            $changes['fulfillment_status'] = true;
        }

        if ($previousScheduled?->toDateTimeString() !== $sale->scheduled_for?->toDateTimeString()) {
            $timeline->record($user, $sale, 'sale_eta_updated', [
                'scheduled_for' => $sale->scheduled_for?->format('Y-m-d H:i'),
            ]);
            $changes['scheduled_for'] = true;
        }

        if ($depositRequested) {
            $timeline->record($user, $sale, 'sale_deposit_requested', [
                'deposit_amount' => $depositAmount,
            ]);
            app(SaleNotificationService::class)->notifyDepositRequested($sale, $depositAmount);
        }

        if ($changes) {
            $sale->loadMissing('customer');
            app(SaleNotificationService::class)->notifyStatusChange($sale, $changes);
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Statut mis a jour.',
                'sale' => $this->loadSaleForResponse($sale),
            ]);
        }

        return redirect()
            ->back()
            ->with('success', 'Statut mis a jour.');
    }

    public function createStripeCheckout(Request $request, Sale $sale)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        [$accountOwner, $canManage, $canPos] = $this->resolveSalesAccess($user);
        $accountId = $accountOwner->id;
        $canAccessAll = $canManage || $canPos;

        if ($sale->user_id !== $accountId) {
            abort(404);
        }
        if (!$canAccessAll && $sale->created_by_user_id !== $user->id) {
            abort(404);
        }

        $respondError = function (string $message) use ($request) {
            if ($this->shouldReturnJson($request)) {
                return response()->json(['message' => $message], 422);
            }
            return redirect()->back()->withErrors(['status' => $message]);
        };

        if (in_array($sale->status, [Sale::STATUS_PAID, Sale::STATUS_CANCELED], true)) {
            return $respondError('Vente deja finalisee.');
        }

        if ((float) $sale->total <= 0) {
            return $respondError('Montant invalide pour Stripe.');
        }

        $sale->loadSum(['payments as payments_sum_amount' => fn($query) => $query->where('status', 'completed')], 'amount');
        if ($sale->balance_due <= 0) {
            return $respondError('Aucun solde a payer.');
        }

        $stripeService = app(StripeSaleService::class);
        if (!$stripeService->isConfigured()) {
            return $respondError('Stripe n est pas configure.');
        }

        $successUrl = URL::route('sales.show', ['sale' => $sale->id, 'stripe' => 'success']);
        $successUrl .= (str_contains($successUrl, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = URL::route('sales.show', ['sale' => $sale->id, 'stripe' => 'cancel']);

        $session = $stripeService->createCheckoutSession($sale, $successUrl, $cancelUrl);
        if (empty($session['url'])) {
            return $respondError('Impossible de demarrer le paiement Stripe.');
        }

        if ($request->header('X-Inertia')) {
            return Inertia::location($session['url']);
        }

        return redirect()->away($session['url']);
    }

    public function confirmPickup(Request $request, Sale $sale)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        [$accountOwner, $canManage, $canPos] = $this->resolveSalesAccess($user);
        $accountId = $accountOwner->id;
        $canAccessAll = $canManage || $canPos;

        if ($sale->user_id !== $accountId) {
            abort(404);
        }
        if (!$canAccessAll && $sale->created_by_user_id !== $user->id) {
            abort(404);
        }

        if ($sale->fulfillment_method !== 'pickup') {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Cette vente n est pas en mode retrait.',
                ], 422);
            }
            return redirect()
                ->back()
                ->with('error', 'Cette vente n est pas en mode retrait.');
        }

        if ($sale->fulfillment_status !== Sale::FULFILLMENT_READY_FOR_PICKUP) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'La commande n est pas prete.',
                ], 422);
            }
            return redirect()
                ->back()
                ->with('error', 'La commande n est pas prete.');
        }

        $previousStatus = $sale->status;
        $previousFulfillment = $sale->fulfillment_status;
        $previousItems = $sale->items()->get(['product_id', 'quantity']);

        $sale->forceFill([
            'fulfillment_status' => Sale::FULFILLMENT_COMPLETED,
            'pickup_confirmed_at' => now(),
            'pickup_confirmed_by_user_id' => $user->id,
        ])->save();

        if ($previousStatus === Sale::STATUS_PENDING && !$this->isFulfillmentComplete($previousFulfillment)) {
            $this->applyReservations($sale, [], $accountId, $previousItems);
        }

        $inventoryAlreadyApplied = $previousStatus === Sale::STATUS_PAID
            || $this->isFulfillmentComplete($previousFulfillment);

        if (!$inventoryAlreadyApplied) {
            $inventoryService = app(InventoryService::class);
            $warehouse = $inventoryService->resolveDefaultWarehouse($accountId);
            $productIds = $previousItems->pluck('product_id')->unique()->values();
            $products = Product::query()
                ->where('user_id', $accountId)
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id');

            foreach ($previousItems as $item) {
                $product = $products->get($item->product_id);
                if (!$product) {
                    continue;
                }
                $this->applyInventoryForProduct(
                    $product,
                    (int) $item->quantity,
                    $inventoryService,
                    $sale,
                    $warehouse
                );
            }
        }

        $timeline = app(SaleTimelineService::class);
        $timeline->record($user, $sale, 'sale_pickup_confirmed');
        $timeline->record($user, $sale, 'sale_fulfillment_changed', [
            'fulfillment_from' => $previousFulfillment,
            'fulfillment_to' => $sale->fulfillment_status,
        ]);

        $sale->loadMissing('customer');
        app(SaleNotificationService::class)->notifyStatusChange($sale, [
            'fulfillment_status' => true,
        ]);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Retrait confirme.',
                'sale' => $this->loadSaleForResponse($sale),
            ]);
        }

        return redirect()
            ->back()
            ->with('success', 'Retrait confirme.');
    }

    public function show(Request $request, Sale $sale)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        [$accountOwner, $canManage, $canPos] = $this->resolveSalesAccess($user);
        $accountId = $accountOwner->id;
        $canAccessAll = $canManage || $canPos;

        if (!$accountId || $sale->user_id !== $accountId) {
            abort(404);
        }
        if (!$canAccessAll && $sale->created_by_user_id !== $user->id) {
            abort(404);
        }

        $stripeStatus = $request->query('stripe');
        if ($stripeStatus === 'success') {
            $sessionId = $request->query('session_id');
            if ($sessionId && app(StripeSaleService::class)->isConfigured()) {
                try {
                    app(StripeSaleService::class)->syncFromCheckoutSessionId($sessionId, $sale);
                    $sale->refresh();
                } catch (\Throwable $exception) {
                    Log::warning('Unable to sync Stripe sale checkout session.', [
                        'sale_id' => $sale->id,
                        'session_id' => $sessionId,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }
        }

        $sale->load([
            'customer:id,first_name,last_name,company_name,email,phone',
            'items.product:id,name,sku,unit,image',
            'createdBy:id,name,email,phone_number',
            'pickupConfirmedBy:id,name,email,phone_number',
            'payments' => fn($query) => $query
                ->select(['id', 'sale_id', 'amount', 'method', 'status', 'paid_at'])
                ->orderByDesc('paid_at'),
        ]);
        $sale->loadSum(['payments as payments_sum_amount' => fn($query) => $query->where('status', 'completed')], 'amount');

        return $this->inertiaOrJson('Sales/Show', [
            'sale' => $sale,
            'stripe' => [
                'enabled' => app(StripeSaleService::class)->isConfigured(),
            ],
        ]);
    }

    public function receipt(Request $request, Sale $sale)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        [$accountOwner, $canManage, $canPos] = $this->resolveSalesAccess($user);
        $accountId = $accountOwner->id;
        $canAccessAll = $canManage || $canPos;

        if (!$accountId || $sale->user_id !== $accountId) {
            abort(404);
        }
        if (!$canAccessAll && $sale->created_by_user_id !== $user->id) {
            abort(404);
        }

        $sale->load([
            'customer:id,first_name,last_name,company_name,email,phone',
            'items.product:id,name,sku,unit,image',
            'payments' => fn($query) => $query
                ->select(['id', 'sale_id', 'amount', 'method', 'status', 'paid_at'])
                ->orderByDesc('paid_at'),
        ]);
        $sale->loadSum(['payments as payments_sum_amount' => fn($query) => $query->where('status', 'completed')], 'amount');

        $items = $sale->items->map(function ($item) {
            return [
                'title' => $item->description ?: $item->product?->name ?: 'Item',
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) ($item->price ?? 0),
                'total' => (float) ($item->total ?? 0),
                'unit' => $item->product?->unit,
                'sku' => $item->product?->sku,
            ];
        });

        $totalPaid = (float) ($sale->payments_sum_amount ?? 0);
        $depositAmount = (float) ($sale->deposit_amount ?? 0);

        $pdf = Pdf::loadView('pdf.sale-receipt', [
            'sale' => $sale,
            'customer' => $sale->customer,
            'company' => $accountOwner,
            'items' => $items,
            'payments' => $sale->payments ?? collect(),
            'totalPaid' => $totalPaid,
            'depositAmount' => $depositAmount,
        ])->setOption('isRemoteEnabled', true);

        $label = $sale->number ?: $sale->id;
        $filename = 'receipt-' . $label . '.pdf';

        return $pdf->download($filename);
    }

    private function resolveSalesAccess(User $user): array
    {
        $ownerId = $user->accountOwnerId();
        $owner = $ownerId === $user->id
            ? $user
            : User::query()->find($ownerId);

        if (!$owner || $owner->company_type !== 'products') {
            abort(403);
        }

        $canManage = $user->id === $owner->id;
        $canPos = $canManage;

        if (!$canManage) {
            $membership = $user->relationLoaded('teamMembership')
                ? $user->teamMembership
                : $user->teamMembership()->first();
            $canManage = $membership?->hasPermission('sales.manage') ?? false;
            $canPos = $membership?->hasPermission('sales.pos') ?? false;
            if (!$canManage && !$canPos) {
                abort(403);
            }
        }

        return [$owner, $canManage, $canPos];
    }

    private function loadSaleForResponse(Sale $sale): Sale
    {
        $sale->loadMissing([
            'customer:id,first_name,last_name,company_name,email,phone',
            'items.product:id,name,sku,unit,image',
            'createdBy:id,name,email,phone_number',
            'pickupConfirmedBy:id,name,email,phone_number',
            'deliveryConfirmedBy:id,name,email,phone_number',
        ]);
        $sale->loadSum(['payments as payments_sum_amount' => fn($query) => $query->where('status', 'completed')], 'amount');

        return $sale;
    }

    private function allowedFulfillmentStatuses(): array
    {
        return [
            Sale::FULFILLMENT_PENDING,
            Sale::FULFILLMENT_PREPARING,
            Sale::FULFILLMENT_OUT_FOR_DELIVERY,
            Sale::FULFILLMENT_READY_FOR_PICKUP,
            Sale::FULFILLMENT_COMPLETED,
            Sale::FULFILLMENT_CONFIRMED,
        ];
    }

    private function isFulfillmentComplete(?string $status): bool
    {
        return in_array($status, [Sale::FULFILLMENT_COMPLETED, Sale::FULFILLMENT_CONFIRMED], true);
    }

    private function applyReservations(Sale $sale, array $itemsPayload, int $accountId, $currentItems = null): void
    {
        $inventoryService = app(InventoryService::class);
        $warehouse = $inventoryService->resolveDefaultWarehouse($accountId);

        if ($currentItems !== null) {
            $current = collect($currentItems);
        } else {
            $current = $sale->relationLoaded('items')
                ? $sale->items
                : $sale->items()->get(['product_id', 'quantity']);
        }

        $currentMap = $current->groupBy('product_id')
            ->map(fn($rows) => (int) $rows->sum('quantity'))
            ->toArray();

        $nextMap = collect($itemsPayload)
            ->groupBy('product_id')
            ->map(fn($rows) => (int) collect($rows)->sum('quantity'))
            ->toArray();

        $productIds = array_values(array_unique(array_merge(array_keys($currentMap), array_keys($nextMap))));
        if (!$productIds) {
            return;
        }

        $products = Product::query()
            ->where('user_id', $accountId)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        foreach ($productIds as $productId) {
            $product = $products->get($productId);
            if (!$product) {
                continue;
            }

            $oldQty = (int) ($currentMap[$productId] ?? 0);
            $newQty = (int) ($nextMap[$productId] ?? 0);
            $delta = $newQty - $oldQty;

            if ($delta !== 0) {
                $inventoryService->adjustReserved($product, $delta, [
                    'warehouse' => $warehouse,
                    'reference' => $sale,
                    'reason' => 'sale_reservation',
                ]);
            }
        }
    }

    private function generatePickupCode(): string
    {
        return 'PK-' . Str::upper(Str::random(6));
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
