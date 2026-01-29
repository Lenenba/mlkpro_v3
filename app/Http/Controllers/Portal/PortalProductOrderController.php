<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\OrderReview;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductReview;
use App\Models\Sale;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\OrderStatusNotification;
use App\Services\InventoryService;
use App\Services\NotificationPreferenceService;
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

class PortalProductOrderController extends Controller
{
    private function resolvePortalCustomer(Request $request): array
    {
        $customer = $request->user()?->customerProfile;
        if (!$customer) {
            abort(403);
        }

        $owner = User::query()
            ->select(['id', 'company_type', 'company_name', 'company_logo', 'company_fulfillment'])
            ->find($customer->user_id);

        if (!$owner || $owner->company_type !== 'products') {
            abort(403);
        }

        return [$customer, $owner];
    }

    private function normalizeFulfillment(?array $settings, User $owner): array
    {
        $settings = is_array($settings) ? $settings : [];

        $defaults = [
            'delivery_enabled' => true,
            'pickup_enabled' => true,
            'delivery_fee' => 0,
            'delivery_zone' => $owner->company_city ?: null,
            'pickup_address' => $owner->company_city ? "Retrait {$owner->company_city}" : null,
            'prep_time_minutes' => 30,
            'delivery_notes' => null,
            'pickup_notes' => null,
        ];

        $merged = array_merge($defaults, $settings);

        if (!$merged['delivery_enabled'] && !$merged['pickup_enabled']) {
            $merged['pickup_enabled'] = true;
        }

        return $merged;
    }

    private function resolvePortalSale(Request $request, Sale $sale): array
    {
        [$customer, $owner] = $this->resolvePortalCustomer($request);

        if ($sale->user_id !== $owner->id || $sale->customer_id !== $customer->id) {
            abort(404);
        }

        return [$customer, $owner, $sale];
    }

    private function syncStripeReturn(Request $request, ?Sale $sale = null): void
    {
        $stripeStatus = $request->query('stripe');
        if ($stripeStatus !== 'success') {
            return;
        }

        $sessionId = $request->query('session_id');
        if (!$sessionId || !app(StripeSaleService::class)->isConfigured()) {
            return;
        }

        try {
            app(StripeSaleService::class)->syncFromCheckoutSessionId($sessionId, $sale);
        } catch (\Throwable $exception) {
            Log::warning('Unable to sync Stripe order payment.', [
                'sale_id' => $sale?->id,
                'session_id' => $sessionId,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function canEditSale(Sale $sale): bool
    {
        if ($sale->status === Sale::STATUS_CANCELED) {
            return false;
        }
        if ($sale->status === Sale::STATUS_PAID) {
            return false;
        }

        $blocked = [
            Sale::FULFILLMENT_OUT_FOR_DELIVERY,
            Sale::FULFILLMENT_READY_FOR_PICKUP,
            Sale::FULFILLMENT_COMPLETED,
            Sale::FULFILLMENT_CONFIRMED,
        ];

        if ($sale->fulfillment_status && in_array($sale->fulfillment_status, $blocked, true)) {
            return false;
        }

        return true;
    }

    private function buildOrderPayload(array $lines, $products): array
    {
        $itemsPayload = [];
        $subtotal = 0;
        $taxTotal = 0;
        $errors = [];
        $now = now();

        foreach ($lines as $index => $line) {
            $product = $products->get($line['product_id'] ?? null);
            if (!$product) {
                $errors["items.{$index}.product_id"] = 'Produit invalide.';
                continue;
            }

            $quantity = (int) ($line['quantity'] ?? 0);
            if ($quantity < 1) {
                $errors["items.{$index}.quantity"] = 'Quantite invalide.';
                continue;
            }

            if ($quantity > (int) $product->stock) {
                $errors["items.{$index}.quantity"] = 'Stock insuffisant pour ' . $product->name . '.';
                continue;
            }

            [, $price] = $this->resolvePromoPricing($product, $now);
            $lineTotal = round($price * $quantity, 2);
            $subtotal += $lineTotal;

            $taxRate = (float) ($product->tax_rate ?? 0);
            $lineTax = $taxRate > 0 ? round($lineTotal * ($taxRate / 100), 2) : 0;
            $taxTotal += $lineTax;

            $itemsPayload[] = [
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => $quantity,
                'price' => $price,
                'total' => $lineTotal,
            ];
        }

        return [$itemsPayload, $subtotal, $taxTotal, $errors];
    }

    private function resolvePromoPricing(Product $product, $now = null): array
    {
        $now = $now ?: now();
        $discount = (float) ($product->promo_discount_percent ?? 0);
        $promoStart = $product->promo_start_at;
        $promoEnd = $product->promo_end_at;
        $promoActive = $discount > 0
            && (!$promoStart || $promoStart->lessThanOrEqualTo($now))
            && (!$promoEnd || $promoEnd->greaterThanOrEqualTo($now));

        $basePrice = (float) $product->price;
        $promoPrice = $promoActive
            ? round($basePrice * (1 - ($discount / 100)), 2)
            : $basePrice;

        return [$basePrice, $promoPrice, $promoActive, $discount];
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

    private function notifyInternalOrder(User $owner, Sale $sale, string $title, string $message): void
    {
        $teamMembers = TeamMember::query()
            ->forAccount($owner->id)
            ->active()
            ->get(['user_id', 'permissions']);

        $userIds = $teamMembers
            ->filter(fn(TeamMember $member) => $member->hasPermission('sales.manage') || $member->hasPermission('sales.pos'))
            ->pluck('user_id')
            ->push($owner->id)
            ->unique()
            ->filter()
            ->values();

        if ($userIds->isEmpty()) {
            return;
        }

        $users = User::query()
            ->whereIn('id', $userIds)
            ->with('teamMembership')
            ->get(['id', 'role_id', 'notification_settings']);

        $actionUrl = route('sales.edit', $sale);
        $preferences = app(NotificationPreferenceService::class);
        foreach ($users as $user) {
            if (!$preferences->shouldNotify(
                $user,
                NotificationPreferenceService::CATEGORY_ORDERS,
                NotificationPreferenceService::CHANNEL_IN_APP
            )) {
                continue;
            }

            $user->notify(new OrderStatusNotification($sale, $title, $message, $actionUrl));
        }
    }

    private function applyCustomerDiscount(Customer $customer, float $subtotal, float $taxTotal): array
    {
        $discountRate = (float) ($customer->discount_rate ?? 0);
        $discountRate = min(100, max(0, $discountRate));
        $discountTotal = round($subtotal * ($discountRate / 100), 2);
        $discountedSubtotal = max(0, $subtotal - $discountTotal);
        $discountedTaxTotal = round($taxTotal * (1 - ($discountRate / 100)), 2);

        return [$discountRate, $discountTotal, $discountedSubtotal, $discountedTaxTotal];
    }

    public function index(Request $request)
    {
        [$customer, $owner] = $this->resolvePortalCustomer($request);
        $fulfillment = $this->normalizeFulfillment($owner->company_fulfillment, $owner);

        $products = Product::query()
            ->products()
            ->where('user_id', $owner->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'description',
                'image',
                'price',
                'promo_discount_percent',
                'promo_start_at',
                'promo_end_at',
                'sku',
                'barcode',
                'unit',
                'stock',
                'minimum_stock',
                'supplier_name',
                'category_id',
                'tracking_type',
                'tax_rate',
            ]);

        $now = now();
        $products->each(function (Product $product) use ($now) {
            [, $promoPrice, $promoActive, $discount] = $this->resolvePromoPricing($product, $now);
            $product->setAttribute('promo_active', $promoActive);
            $product->setAttribute('promo_price', $promoActive ? $promoPrice : null);
            $product->setAttribute('promo_discount_percent', $promoActive ? $discount : $product->promo_discount_percent);
        });

        $defaultAddress = $customer->defaultProperty?->street1
            ? collect([
                $customer->defaultProperty->street1,
                $customer->defaultProperty->city,
                $customer->defaultProperty->state,
                $customer->defaultProperty->zip,
            ])->filter()->implode(', ')
            : null;

        $categories = ProductCategory::forAccount($owner->id)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name']);

        return $this->inertiaOrJson('Portal/Products/Shop', [
            'company' => [
                'id' => $owner->id,
                'name' => $owner->company_name,
                'logo_url' => $owner->company_logo_url,
            ],
            'customer' => [
                'id' => $customer->id,
                'name' => trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')),
                'email' => $customer->email,
                'phone' => $customer->phone,
                'default_address' => $defaultAddress,
            ],
            'products' => $products,
            'categories' => $categories,
            'fulfillment' => $fulfillment,
            'stripe' => [
                'enabled' => app(StripeSaleService::class)->isConfigured(),
            ],
        ]);
    }

    public function history(Request $request)
    {
        [$customer, $owner] = $this->resolvePortalCustomer($request);

        $filters = $request->only(['status']);
        $allowedStatuses = [
            Sale::STATUS_PENDING,
            Sale::STATUS_PAID,
            Sale::STATUS_CANCELED,
        ];

        $baseQuery = Sale::query()
            ->where('user_id', $owner->id)
            ->where('customer_id', $customer->id)
            ->when($filters['status'] ?? null, function ($query, $status) use ($allowedStatuses) {
                if (in_array($status, $allowedStatuses, true)) {
                    $query->where('status', $status);
                }
            });

        $orders = (clone $baseQuery)
            ->latest()
            ->select([
                'id',
                'number',
                'status',
                'total',
                'created_at',
                'fulfillment_method',
                'fulfillment_status',
                'scheduled_for',
                'delivery_confirmed_at',
            ])
            ->withCount('items')
            ->withSum(['payments as payments_sum_amount' => fn($query) => $query->where('status', 'completed')], 'amount')
            ->simplePaginate(12);

        $orders->through(fn(Sale $sale) => $this->formatOrderSummary($sale));

        return response()->json([
            'orders' => $orders,
            'filters' => $filters,
            'stats' => [
                'total' => (clone $baseQuery)->count(),
                'pending' => (clone $baseQuery)->where('status', Sale::STATUS_PENDING)->count(),
                'paid' => (clone $baseQuery)->where('status', Sale::STATUS_PAID)->count(),
                'canceled' => (clone $baseQuery)->where('status', Sale::STATUS_CANCELED)->count(),
            ],
        ]);
    }

    public function show(Request $request, Sale $sale)
    {
        [$customer, $owner, $sale] = $this->resolvePortalSale($request, $sale);
        $this->syncStripeReturn($request, $sale);
        $sale->refresh();
        $fulfillment = $this->normalizeFulfillment($owner->company_fulfillment, $owner);
        $timeline = app(SaleTimelineService::class)->buildTimeline($sale);

        $sale->load(['items.product:id,name,sku,barcode,unit,image,price']);
        $sale->loadSum(['payments as payments_sum_amount' => fn($query) => $query->where('status', 'completed')], 'amount');

        $defaultAddress = $customer->defaultProperty?->street1
            ? collect([
                $customer->defaultProperty->street1,
                $customer->defaultProperty->city,
                $customer->defaultProperty->state,
                $customer->defaultProperty->zip,
            ])->filter()->implode(', ')
            : null;

        $reviewsPayload = $this->buildReviewPayload($customer, $sale);

        return response()->json([
            'company' => [
                'id' => $owner->id,
                'name' => $owner->company_name,
                'logo_url' => $owner->company_logo_url,
            ],
            'customer' => [
                'id' => $customer->id,
                'name' => trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')),
                'email' => $customer->email,
                'phone' => $customer->phone,
                'default_address' => $defaultAddress,
            ],
            'fulfillment' => $fulfillment,
            'order' => $this->formatOrderDetail($sale),
            'order_review' => $reviewsPayload['order_review'],
            'product_reviews' => $reviewsPayload['product_reviews'],
            'timeline' => $timeline,
            'stripe' => [
                'enabled' => app(StripeSaleService::class)->isConfigured(),
            ],
        ]);
    }

    public function showPage(Request $request, Sale $sale)
    {
        [$customer, $owner, $sale] = $this->resolvePortalSale($request, $sale);
        $this->syncStripeReturn($request, $sale);
        $sale->refresh();

        $fulfillment = $this->normalizeFulfillment($owner->company_fulfillment, $owner);
        $timeline = app(SaleTimelineService::class)->buildTimeline($sale);

        $sale->load([
            'items.product:id,name,sku,barcode,unit,image,price',
            'payments' => fn($query) => $query->latest()->limit(10),
        ]);
        $sale->loadSum(['payments as payments_sum_amount' => fn($query) => $query->where('status', 'completed')], 'amount');

        $reviewsPayload = $this->buildReviewPayload($customer, $sale);

        return $this->inertiaOrJson('Portal/Products/OrderShow', [
            'company' => [
                'id' => $owner->id,
                'name' => $owner->company_name,
                'logo_url' => $owner->company_logo_url,
            ],
            'customer' => [
                'id' => $customer->id,
                'name' => trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')),
                'email' => $customer->email,
                'phone' => $customer->phone,
            ],
            'fulfillment' => $fulfillment,
            'order' => $this->formatOrderDetail($sale),
            'orderReview' => $reviewsPayload['order_review'],
            'productReviews' => $reviewsPayload['product_reviews'],
            'payments' => $sale->payments?->map(fn($payment) => [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'method' => $payment->method,
                'status' => $payment->status,
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ])->values(),
            'timeline' => $timeline,
            'stripe' => [
                'enabled' => app(StripeSaleService::class)->isConfigured(),
            ],
        ]);
    }

    public function pdf(Request $request, Sale $sale)
    {
        [$customer, $owner, $sale] = $this->resolvePortalSale($request, $sale);

        $sale->load([
            'items.product:id,name,sku,barcode,unit,image,price',
            'payments',
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

        $pdf = Pdf::loadView('pdf.order', [
            'sale' => $sale,
            'customer' => $customer,
            'company' => $owner,
            'items' => $items,
            'totalPaid' => $totalPaid,
            'depositAmount' => $depositAmount,
        ])->setOption('isRemoteEnabled', true);

        $label = $sale->number ?: $sale->id;
        $filename = 'order-' . $label . '.pdf';

        return $pdf->download($filename);
    }

    public function store(Request $request)
    {
        [$customer, $owner] = $this->resolvePortalCustomer($request);
        $fulfillment = $this->normalizeFulfillment($owner->company_fulfillment, $owner);

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'fulfillment_method' => ['required', Rule::in(['delivery', 'pickup'])],
            'delivery_address' => 'nullable|string|max:500',
            'delivery_notes' => 'nullable|string|max:500',
            'pickup_notes' => 'nullable|string|max:500',
            'scheduled_for' => 'nullable|date',
            'customer_notes' => 'nullable|string|max:1000',
            'substitution_allowed' => 'nullable|boolean',
            'substitution_notes' => 'nullable|string|max:500',
        ]);

        if ($validated['fulfillment_method'] === 'delivery' && !$fulfillment['delivery_enabled']) {
            throw ValidationException::withMessages([
                'fulfillment_method' => 'La livraison n est pas disponible.',
            ]);
        }
        if ($validated['fulfillment_method'] === 'pickup' && !$fulfillment['pickup_enabled']) {
            throw ValidationException::withMessages([
                'fulfillment_method' => 'Le retrait n est pas disponible.',
            ]);
        }
        if ($validated['fulfillment_method'] === 'delivery' && empty($validated['delivery_address'])) {
            throw ValidationException::withMessages([
                'delivery_address' => 'L adresse de livraison est requise.',
            ]);
        }

        $productIds = collect($validated['items'])->pluck('product_id')->unique()->values();
        $products = Product::query()
            ->products()
            ->where('user_id', $owner->id)
            ->where('is_active', true)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        if ($products->count() !== $productIds->count()) {
            throw ValidationException::withMessages([
                'items' => 'Certains produits ne sont plus disponibles.',
            ]);
        }

        [$itemsPayload, $subtotal, $taxTotal, $errors] = $this->buildOrderPayload($validated['items'], $products);

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $deliveryFee = $validated['fulfillment_method'] === 'delivery'
            ? (float) ($fulfillment['delivery_fee'] ?? 0)
            : 0;

        [$discountRate, $discountTotal, $discountedSubtotal, $discountedTaxTotal] =
            $this->applyCustomerDiscount($customer, $subtotal, $taxTotal);

        $total = round($discountedSubtotal + $discountedTaxTotal + $deliveryFee, 2);

        $sale = Sale::create([
            'user_id' => $owner->id,
            'customer_id' => $customer->id,
            'status' => Sale::STATUS_PENDING,
            'subtotal' => $subtotal,
            'tax_total' => $discountedTaxTotal,
            'discount_rate' => $discountRate,
            'discount_total' => $discountTotal,
            'delivery_fee' => $deliveryFee,
            'total' => $total,
            'fulfillment_method' => $validated['fulfillment_method'],
            'fulfillment_status' => Sale::FULFILLMENT_PENDING,
            'delivery_address' => $validated['delivery_address'] ?? null,
            'delivery_notes' => $validated['delivery_notes'] ?? null,
            'pickup_notes' => $validated['pickup_notes'] ?? null,
            'scheduled_for' => $validated['scheduled_for'] ?? null,
            'customer_notes' => $validated['customer_notes'] ?? null,
            'substitution_allowed' => (bool) ($validated['substitution_allowed'] ?? true),
            'substitution_notes' => $validated['substitution_notes'] ?? null,
            'source' => 'portal',
        ]);

        foreach ($itemsPayload as $payload) {
            $sale->items()->create($payload);
        }

        $this->applyReservations($sale, $itemsPayload, $owner->id);

        app(SaleTimelineService::class)->record($request->user(), $sale, 'sale_created', [
            'fulfillment_method' => $sale->fulfillment_method,
        ]);

        $this->notifyInternalOrder($owner, $sale, 'Nouvelle commande', 'Une nouvelle commande client est arrivee.');

        if ($this->shouldReturnJson($request)) {
            $sale->load(['items.product:id,name,sku,barcode,unit,image,price']);

            return response()->json([
                'message' => 'Commande envoyee. Nous preparons votre commande.',
                'order' => $this->formatOrderDetail($sale),
            ], 201);
        }

        return redirect()
            ->route('portal.orders.index')
            ->with('success', 'Commande envoyee. Nous preparons votre commande.');
    }

    public function edit(Request $request, Sale $sale)
    {
        [$customer, $owner, $sale] = $this->resolvePortalSale($request, $sale);
        $this->syncStripeReturn($request, $sale);
        $sale->refresh();
        $fulfillment = $this->normalizeFulfillment($owner->company_fulfillment, $owner);
        $timeline = app(SaleTimelineService::class)->buildTimeline($sale);

        $products = Product::query()
            ->products()
            ->where('user_id', $owner->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'description',
                'image',
                'price',
                'promo_discount_percent',
                'promo_start_at',
                'promo_end_at',
                'sku',
                'barcode',
                'unit',
                'stock',
                'minimum_stock',
                'supplier_name',
                'category_id',
                'tracking_type',
                'tax_rate',
            ]);

        $now = now();
        $products->each(function (Product $product) use ($now) {
            [, $promoPrice, $promoActive, $discount] = $this->resolvePromoPricing($product, $now);
            $product->setAttribute('promo_active', $promoActive);
            $product->setAttribute('promo_price', $promoActive ? $promoPrice : null);
            $product->setAttribute('promo_discount_percent', $promoActive ? $discount : $product->promo_discount_percent);
        });

        $defaultAddress = $customer->defaultProperty?->street1
            ? collect([
                $customer->defaultProperty->street1,
                $customer->defaultProperty->city,
                $customer->defaultProperty->state,
                $customer->defaultProperty->zip,
            ])->filter()->implode(', ')
            : null;

        $sale->loadSum(['payments as payments_sum_amount' => fn($query) => $query->where('status', 'completed')], 'amount');

        $categories = ProductCategory::forAccount($owner->id)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name']);

        $sale->load(['items:id,sale_id,product_id,quantity']);

        return $this->inertiaOrJson('Portal/Products/Shop', [
            'company' => [
                'id' => $owner->id,
                'name' => $owner->company_name,
                'logo_url' => $owner->company_logo_url,
            ],
            'customer' => [
                'id' => $customer->id,
                'name' => trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')),
                'email' => $customer->email,
                'phone' => $customer->phone,
                'default_address' => $defaultAddress,
            ],
            'products' => $products,
            'categories' => $categories,
            'fulfillment' => $fulfillment,
            'order' => [
                'id' => $sale->id,
                'number' => $sale->number,
                'status' => $sale->status,
                'fulfillment_method' => $sale->fulfillment_method,
                'fulfillment_status' => $sale->fulfillment_status,
                'delivery_address' => $sale->delivery_address,
                'delivery_notes' => $sale->delivery_notes,
                'pickup_notes' => $sale->pickup_notes,
                'scheduled_for' => $sale->scheduled_for?->toIso8601String(),
                'can_edit' => $this->canEditSale($sale),
                'pickup_code' => $sale->pickup_code,
                'pickup_confirmed_at' => $sale->pickup_confirmed_at?->toIso8601String(),
                'delivery_confirmed_at' => $sale->delivery_confirmed_at?->toIso8601String(),
                'delivery_proof_url' => $sale->delivery_proof_url,
                'customer_notes' => $sale->customer_notes,
                'substitution_allowed' => $sale->substitution_allowed,
                'substitution_notes' => $sale->substitution_notes,
                'discount_rate' => $sale->discount_rate,
                'discount_total' => $sale->discount_total,
                'items' => $sale->items->map(fn($item) => [
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                ])->values(),
            ],
            'timeline' => $timeline,
            'stripe' => [
                'enabled' => app(StripeSaleService::class)->isConfigured(),
            ],
        ]);
    }

    public function update(Request $request, Sale $sale)
    {
        [$customer, $owner, $sale] = $this->resolvePortalSale($request, $sale);

        if (!$this->canEditSale($sale)) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Commande deja en livraison.',
                ], 422);
            }
            return redirect()
                ->route('portal.orders.index')
                ->with('error', 'Commande deja en livraison.');
        }

        $fulfillment = $this->normalizeFulfillment($owner->company_fulfillment, $owner);

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'fulfillment_method' => ['required', Rule::in(['delivery', 'pickup'])],
            'delivery_address' => 'nullable|string|max:500',
            'delivery_notes' => 'nullable|string|max:500',
            'pickup_notes' => 'nullable|string|max:500',
            'scheduled_for' => 'nullable|date',
            'customer_notes' => 'nullable|string|max:1000',
            'substitution_allowed' => 'nullable|boolean',
            'substitution_notes' => 'nullable|string|max:500',
        ]);

        if ($validated['fulfillment_method'] === 'delivery' && !$fulfillment['delivery_enabled']) {
            throw ValidationException::withMessages([
                'fulfillment_method' => 'La livraison n est pas disponible.',
            ]);
        }
        if ($validated['fulfillment_method'] === 'pickup' && !$fulfillment['pickup_enabled']) {
            throw ValidationException::withMessages([
                'fulfillment_method' => 'Le retrait n est pas disponible.',
            ]);
        }
        if ($validated['fulfillment_method'] === 'delivery' && empty($validated['delivery_address'])) {
            throw ValidationException::withMessages([
                'delivery_address' => 'L adresse de livraison est requise.',
            ]);
        }

        $currentItems = $sale->items()->get(['product_id', 'quantity']);
        $currentMap = $sale->status === Sale::STATUS_PENDING
            ? $currentItems->groupBy('product_id')->map(fn($rows) => (int) $rows->sum('quantity'))->toArray()
            : [];

        $productIds = collect($validated['items'])->pluck('product_id')->unique()->values();
        $products = Product::query()
            ->products()
            ->where('user_id', $owner->id)
            ->where('is_active', true)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        if ($products->count() !== $productIds->count()) {
            throw ValidationException::withMessages([
                'items' => 'Certains produits ne sont plus disponibles.',
            ]);
        }

        foreach ($currentMap as $productId => $quantity) {
            $product = $products->get($productId);
            if ($product) {
                $product->stock = (int) $product->stock + $quantity;
            }
        }

        [$itemsPayload, $subtotal, $taxTotal, $errors] = $this->buildOrderPayload($validated['items'], $products);

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $deliveryFee = $validated['fulfillment_method'] === 'delivery'
            ? (float) ($fulfillment['delivery_fee'] ?? 0)
            : 0;

        [$discountRate, $discountTotal, $discountedSubtotal, $discountedTaxTotal] =
            $this->applyCustomerDiscount($customer, $subtotal, $taxTotal);

        $total = round($discountedSubtotal + $discountedTaxTotal + $deliveryFee, 2);

        $previousScheduled = $sale->scheduled_for;

        $sale->update([
            'subtotal' => $subtotal,
            'tax_total' => $discountedTaxTotal,
            'discount_rate' => $discountRate,
            'discount_total' => $discountTotal,
            'delivery_fee' => $deliveryFee,
            'total' => $total,
            'fulfillment_method' => $validated['fulfillment_method'],
            'fulfillment_status' => $sale->fulfillment_status ?: Sale::FULFILLMENT_PENDING,
            'delivery_address' => $validated['fulfillment_method'] === 'delivery'
                ? ($validated['delivery_address'] ?? null)
                : null,
            'delivery_notes' => $validated['fulfillment_method'] === 'delivery'
                ? ($validated['delivery_notes'] ?? null)
                : null,
            'pickup_notes' => $validated['fulfillment_method'] === 'pickup'
                ? ($validated['pickup_notes'] ?? null)
                : null,
            'scheduled_for' => $validated['scheduled_for'] ?? null,
            'customer_notes' => $validated['customer_notes'] ?? null,
            'substitution_allowed' => (bool) ($validated['substitution_allowed'] ?? true),
            'substitution_notes' => $validated['substitution_notes'] ?? null,
        ]);

        $sale->items()->delete();
        foreach ($itemsPayload as $payload) {
            $sale->items()->create($payload);
        }

        if (
            $sale->status === Sale::STATUS_PENDING
            && !in_array($sale->fulfillment_status, [Sale::FULFILLMENT_COMPLETED, Sale::FULFILLMENT_CONFIRMED], true)
        ) {
            $this->applyReservations($sale, $itemsPayload, $owner->id, $currentItems);
        }

        $timeline = app(SaleTimelineService::class);
        $timeline->record($request->user(), $sale, 'sale_updated');
        if ($previousScheduled?->toDateTimeString() !== $sale->scheduled_for?->toDateTimeString()) {
            $timeline->record($request->user(), $sale, 'sale_eta_updated', [
                'scheduled_for' => $sale->scheduled_for?->format('Y-m-d H:i'),
            ]);
        }

        $this->notifyInternalOrder($owner, $sale, 'Commande modifiee', 'Le client a mis a jour sa commande.');

        if ($this->shouldReturnJson($request)) {
            $sale->load(['items.product:id,name,sku,barcode,unit,image,price']);

            return response()->json([
                'message' => 'Commande mise a jour.',
                'order' => $this->formatOrderDetail($sale),
            ]);
        }

        return redirect()
            ->route('portal.orders.edit', $sale)
            ->with('success', 'Commande mise a jour.');
    }

    public function pay(Request $request, Sale $sale)
    {
        [$customer, $owner, $sale] = $this->resolvePortalSale($request, $sale);

        if (in_array($sale->status, [Sale::STATUS_PAID, Sale::STATUS_CANCELED], true)) {
            throw ValidationException::withMessages([
                'payment' => 'Commande deja finalisee.',
            ]);
        }

        $validated = $request->validate([
            'type' => ['nullable', Rule::in(['deposit', 'balance'])],
        ]);

        $paymentType = $validated['type'] ?? 'deposit';
        $stripeService = app(StripeSaleService::class);
        if (!$stripeService->isConfigured()) {
            throw ValidationException::withMessages([
                'payment' => 'Stripe n est pas configure.',
            ]);
        }

        $sale->loadSum(['payments as payments_sum_amount' => fn($query) => $query->where('status', 'completed')], 'amount');

        $depositAmount = (float) ($sale->deposit_amount ?? 0);
        $amountPaid = $sale->amount_paid;
        $balanceDue = $sale->balance_due;
        $amount = 0.0;

        if ($paymentType === 'deposit') {
            if ($depositAmount <= 0 && $sale->fulfillment_status === Sale::FULFILLMENT_PREPARING) {
                $depositAmount = round(((float) $sale->total) * 0.2, 2);
                if ($depositAmount > 0) {
                    $sale->forceFill(['deposit_amount' => $depositAmount])->save();
                }
            }
            if ($depositAmount <= 0) {
                throw ValidationException::withMessages([
                    'payment' => 'Acompte non requis.',
                ]);
            }
            $amount = round(max(0, $depositAmount - $amountPaid), 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'payment' => 'Acompte deja regle.',
                ]);
            }
        } else {
            if ($balanceDue <= 0) {
                throw ValidationException::withMessages([
                    'payment' => 'Aucun solde a payer.',
                ]);
            }
            $amount = $balanceDue;
        }

        $successUrl = URL::route('portal.orders.show', ['sale' => $sale->id, 'stripe' => 'success']);
        $successUrl .= (str_contains($successUrl, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = URL::route('portal.orders.show', ['sale' => $sale->id, 'stripe' => 'cancel']);

        $session = $stripeService->createCheckoutSession($sale, $successUrl, $cancelUrl, $amount, $paymentType);
        if (empty($session['url'])) {
            throw ValidationException::withMessages([
                'payment' => 'Impossible de demarrer le paiement.',
            ]);
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'checkout_url' => $session['url'],
                'checkout_session_id' => $session['id'],
            ]);
        }

        if ($request->header('X-Inertia')) {
            return Inertia::location($session['url']);
        }

        return redirect()->away($session['url']);
    }

    public function confirmReceipt(Request $request, Sale $sale)
    {
        [$customer, $owner, $sale] = $this->resolvePortalSale($request, $sale);

        if ($sale->status === Sale::STATUS_CANCELED) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Commande annulee.',
                ], 422);
            }
            return redirect()
                ->route('portal.orders.show', $sale)
                ->with('error', 'Commande annulee.');
        }

        if ($sale->fulfillment_status !== Sale::FULFILLMENT_COMPLETED) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'La commande n est pas encore livree.',
                ], 422);
            }
            return redirect()
                ->route('portal.orders.show', $sale)
                ->with('error', 'La commande n est pas encore livree.');
        }

        if ($sale->delivery_confirmed_at) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Commande deja confirmee.',
                ], 422);
            }
            return redirect()
                ->route('portal.orders.show', $sale)
                ->with('success', 'Commande deja confirmee.');
        }

        $validated = $request->validate([
            'proof' => 'nullable|image|max:4096',
        ]);

        $proofPath = null;
        if (!empty($validated['proof'])) {
            $proofPath = $validated['proof']->store('sales/deliveries', 'public');
        }

        $sale->forceFill([
            'fulfillment_status' => Sale::FULFILLMENT_CONFIRMED,
            'delivery_confirmed_at' => now(),
            'delivery_confirmed_by_user_id' => $request->user()?->id,
            'delivery_proof' => $proofPath ?: $sale->delivery_proof,
        ])->save();

        app(SaleTimelineService::class)->record($request->user(), $sale, 'sale_delivery_confirmed');
        $this->notifyInternalOrder($owner, $sale, 'Commande confirmee', 'Le client a confirme la reception.');

        if ($this->shouldReturnJson($request)) {
            $sale->load(['items.product:id,name,sku,barcode,unit,image,price']);

            return response()->json([
                'message' => 'Merci. Votre commande est confirmee.',
                'order' => $this->formatOrderDetail($sale),
            ]);
        }

        return redirect()
            ->route('portal.orders.show', $sale)
            ->with('success', 'Merci. Votre commande est confirmee.');
    }

    public function destroy(Request $request, Sale $sale)
    {
        [, $owner, $sale] = $this->resolvePortalSale($request, $sale);

        if (!$this->canEditSale($sale)) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Commande deja en livraison.',
                ], 422);
            }
            return redirect()
                ->route('portal.orders.index')
                ->with('error', 'Commande deja en livraison.');
        }

        $this->applyReservations($sale, [], $sale->user_id);
        $sale->update([
            'status' => Sale::STATUS_CANCELED,
            'fulfillment_status' => null,
        ]);

        app(SaleTimelineService::class)->record($request->user(), $sale, 'sale_canceled');

        $this->notifyInternalOrder($owner, $sale, 'Commande annulee', 'Le client a annule sa commande.');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Commande annulee.',
                'order' => $this->formatOrderDetail($sale),
            ]);
        }

        return redirect()
            ->route('portal.orders.index')
            ->with('success', 'Commande annulee.');
    }

    public function reorder(Request $request, Sale $sale)
    {
        [$customer, $owner, $sale] = $this->resolvePortalSale($request, $sale);

        $items = $sale->items()->get(['product_id', 'quantity']);
        if ($items->isEmpty()) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Aucun article a recommander.',
                ], 422);
            }
            return redirect()
                ->route('portal.orders.index')
                ->with('error', 'Aucun article a recommander.');
        }

        $productIds = $items->pluck('product_id')->unique()->values();
        $products = Product::query()
            ->products()
            ->where('user_id', $owner->id)
            ->where('is_active', true)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        if ($products->count() !== $productIds->count()) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Certains produits ne sont plus disponibles.',
                ], 422);
            }
            return redirect()
                ->route('portal.orders.index')
                ->with('error', 'Certains produits ne sont plus disponibles.');
        }

        $lines = $items->map(fn($item) => [
            'product_id' => $item->product_id,
            'quantity' => $item->quantity,
        ])->values()->all();

        [$itemsPayload, $subtotal, $taxTotal, $errors] = $this->buildOrderPayload($lines, $products);
        if ($errors) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Stock insuffisant pour recommander.',
                ], 422);
            }
            return redirect()
                ->route('portal.orders.index')
                ->with('error', 'Stock insuffisant pour recommander.');
        }

        $deliveryFee = $sale->fulfillment_method === 'delivery'
            ? (float) ($owner->company_fulfillment['delivery_fee'] ?? 0)
            : 0;

        [$discountRate, $discountTotal, $discountedSubtotal, $discountedTaxTotal] =
            $this->applyCustomerDiscount($customer, $subtotal, $taxTotal);

        $total = round($discountedSubtotal + $discountedTaxTotal + $deliveryFee, 2);

        $newSale = Sale::create([
            'user_id' => $owner->id,
            'customer_id' => $customer->id,
            'status' => Sale::STATUS_PENDING,
            'subtotal' => $subtotal,
            'tax_total' => $discountedTaxTotal,
            'discount_rate' => $discountRate,
            'discount_total' => $discountTotal,
            'delivery_fee' => $deliveryFee,
            'total' => $total,
            'fulfillment_method' => $sale->fulfillment_method,
            'fulfillment_status' => Sale::FULFILLMENT_PENDING,
            'delivery_address' => $sale->delivery_address,
            'delivery_notes' => $sale->delivery_notes,
            'pickup_notes' => $sale->pickup_notes,
            'scheduled_for' => null,
            'customer_notes' => $sale->customer_notes,
            'substitution_allowed' => (bool) $sale->substitution_allowed,
            'substitution_notes' => $sale->substitution_notes,
            'source' => 'portal',
        ]);

        foreach ($itemsPayload as $payload) {
            $newSale->items()->create($payload);
        }

        $this->applyReservations($newSale, $itemsPayload, $owner->id);

        app(SaleTimelineService::class)->record($request->user(), $newSale, 'sale_reordered');

        $this->notifyInternalOrder($owner, $newSale, 'Nouvelle commande', 'Un client vient de recommander.');

        if ($this->shouldReturnJson($request)) {
            $newSale->load(['items.product:id,name,sku,barcode,unit,image,price']);

            return response()->json([
                'message' => 'Commande recommendee.',
                'order' => $this->formatOrderDetail($newSale),
            ], 201);
        }

        return redirect()
            ->route('portal.orders.edit', $newSale)
            ->with('success', 'Commande recommendee.');
    }

    private function formatOrderSummary(Sale $sale): array
    {
        return [
            'id' => $sale->id,
            'number' => $sale->number,
            'status' => $sale->status,
            'payment_status' => $sale->payment_status,
            'amount_paid' => $sale->amount_paid,
            'balance_due' => $sale->balance_due,
            'deposit_amount' => $sale->deposit_amount,
            'total' => $sale->total,
            'created_at' => $sale->created_at?->toIso8601String(),
            'fulfillment_method' => $sale->fulfillment_method,
            'fulfillment_status' => $sale->fulfillment_status,
            'scheduled_for' => $sale->scheduled_for?->toIso8601String(),
            'delivery_confirmed_at' => $sale->delivery_confirmed_at?->toIso8601String(),
            'items_count' => $sale->items_count ?? ($sale->relationLoaded('items') ? $sale->items->count() : null),
            'can_edit' => $this->canEditSale($sale),
        ];
    }

    private function formatOrderDetail(Sale $sale): array
    {
        $items = $sale->relationLoaded('items')
            ? $sale->items
            : $sale->items()->get();

        return [
            'id' => $sale->id,
            'number' => $sale->number,
            'status' => $sale->status,
            'payment_status' => $sale->payment_status,
            'amount_paid' => $sale->amount_paid,
            'balance_due' => $sale->balance_due,
            'deposit_amount' => $sale->deposit_amount,
            'created_at' => $sale->created_at?->toIso8601String(),
            'paid_at' => $sale->paid_at?->toIso8601String(),
            'fulfillment_method' => $sale->fulfillment_method,
            'fulfillment_status' => $sale->fulfillment_status,
            'delivery_address' => $sale->delivery_address,
            'delivery_notes' => $sale->delivery_notes,
            'pickup_notes' => $sale->pickup_notes,
            'scheduled_for' => $sale->scheduled_for?->toIso8601String(),
            'can_edit' => $this->canEditSale($sale),
            'pickup_code' => $sale->pickup_code,
            'pickup_confirmed_at' => $sale->pickup_confirmed_at?->toIso8601String(),
            'delivery_confirmed_at' => $sale->delivery_confirmed_at?->toIso8601String(),
            'delivery_proof_url' => $sale->delivery_proof_url,
            'customer_notes' => $sale->customer_notes,
            'substitution_allowed' => $sale->substitution_allowed,
            'substitution_notes' => $sale->substitution_notes,
            'discount_rate' => $sale->discount_rate,
            'discount_total' => $sale->discount_total,
            'delivery_fee' => $sale->delivery_fee,
            'subtotal' => $sale->subtotal,
            'tax_total' => $sale->tax_total,
            'total' => $sale->total,
            'items' => $items->map(function ($item) {
                $product = $item->relationLoaded('product') ? $item->product : null;
                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'total' => $item->total,
                    'product' => $product ? [
                        'id' => $product->id,
                        'name' => $product->name,
                        'sku' => $product->sku,
                        'barcode' => $product->barcode,
                        'unit' => $product->unit,
                        'image' => $product->image,
                        'image_url' => $product->image_url,
                        'price' => $product->price,
                    ] : null,
                ];
            })->values(),
        ];
    }

    private function buildReviewPayload(Customer $customer, Sale $sale): array
    {
        $productIds = $sale->relationLoaded('items')
            ? $sale->items->pluck('product_id')->filter()->unique()->values()
            : $sale->items()->pluck('product_id')->filter()->unique();

        $orderReview = OrderReview::query()
            ->where('sale_id', $sale->id)
            ->where('customer_id', $customer->id)
            ->first();

        $productReviews = $productIds->isNotEmpty()
            ? ProductReview::query()
                ->where('customer_id', $customer->id)
                ->whereIn('product_id', $productIds)
                ->get()
                ->mapWithKeys(fn(ProductReview $review) => [
                    $review->product_id => $this->formatProductReview($review),
                ])
            : collect();

        return [
            'order_review' => $orderReview ? $this->formatOrderReview($orderReview) : null,
            'product_reviews' => $productReviews->toArray(),
        ];
    }

    private function formatOrderReview(OrderReview $review): array
    {
        return [
            'id' => $review->id,
            'rating' => $review->rating,
            'comment' => $review->comment,
            'is_approved' => $review->is_approved,
            'blocked_reason' => $review->blocked_reason,
            'created_at' => $review->created_at?->toIso8601String(),
        ];
    }

    private function formatProductReview(ProductReview $review): array
    {
        return [
            'id' => $review->id,
            'product_id' => $review->product_id,
            'rating' => $review->rating,
            'title' => $review->title,
            'comment' => $review->comment,
            'is_approved' => $review->is_approved,
            'blocked_reason' => $review->blocked_reason,
            'created_at' => $review->created_at?->toIso8601String(),
        ];
    }
}
