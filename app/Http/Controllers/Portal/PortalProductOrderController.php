<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\OrderStatusNotification;
use App\Services\InventoryService;
use App\Services\SaleTimelineService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

            $price = (float) $product->price;
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
            ->get(['id']);

        $actionUrl = route('sales.edit', $sale);
        foreach ($users as $user) {
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
        ]);
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

        return redirect()
            ->route('portal.orders.index')
            ->with('success', 'Commande envoyee. Nous preparons votre commande.');
    }

    public function edit(Request $request, Sale $sale)
    {
        [$customer, $owner, $sale] = $this->resolvePortalSale($request, $sale);
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
        ]);
    }

    public function update(Request $request, Sale $sale)
    {
        [$customer, $owner, $sale] = $this->resolvePortalSale($request, $sale);

        if (!$this->canEditSale($sale)) {
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

        return redirect()
            ->route('portal.orders.edit', $sale)
            ->with('success', 'Commande mise a jour.');
    }

    public function confirmReceipt(Request $request, Sale $sale)
    {
        [$customer, $owner, $sale] = $this->resolvePortalSale($request, $sale);

        if ($sale->status === Sale::STATUS_CANCELED) {
            return redirect()
                ->route('portal.orders.edit', $sale)
                ->with('error', 'Commande annulee.');
        }

        if ($sale->fulfillment_status !== Sale::FULFILLMENT_COMPLETED) {
            return redirect()
                ->route('portal.orders.edit', $sale)
                ->with('error', 'La commande n est pas encore livree.');
        }

        if ($sale->delivery_confirmed_at) {
            return redirect()
                ->route('portal.orders.edit', $sale)
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

        return redirect()
            ->route('portal.orders.edit', $sale)
            ->with('success', 'Merci. Votre commande est confirmee.');
    }

    public function destroy(Request $request, Sale $sale)
    {
        [, , $sale] = $this->resolvePortalSale($request, $sale);

        if (!$this->canEditSale($sale)) {
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

        return redirect()
            ->route('portal.orders.index')
            ->with('success', 'Commande annulee.');
    }

    public function reorder(Request $request, Sale $sale)
    {
        [$customer, $owner, $sale] = $this->resolvePortalSale($request, $sale);

        $items = $sale->items()->get(['product_id', 'quantity']);
        if ($items->isEmpty()) {
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

        return redirect()
            ->route('portal.orders.edit', $newSale)
            ->with('success', 'Commande recommendee.');
    }
}
