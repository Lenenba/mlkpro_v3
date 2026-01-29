<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductReview;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\OrderStatusNotification;
use App\Services\InventoryService;
use App\Services\NotificationPreferenceService;
use App\Services\SaleTimelineService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PublicStoreController extends Controller
{
    private function resolveOwner(string $slug): User
    {
        return User::query()
            ->where('company_slug', $slug)
            ->where('company_type', 'products')
            ->where('is_suspended', false)
            ->firstOrFail();
    }

    private function cartKey(User $owner): string
    {
        return "public_store_cart_{$owner->id}";
    }

    private function getCartItems(Request $request, User $owner): array
    {
        $cart = $request->session()->get($this->cartKey($owner), []);
        $rawItems = $cart['items'] ?? [];
        if (!is_array($rawItems)) {
            return [];
        }

        $items = [];
        foreach ($rawItems as $productId => $quantity) {
            $id = (int) $productId;
            $qty = (int) $quantity;
            if ($id > 0 && $qty > 0) {
                $items[$id] = $qty;
            }
        }

        return $items;
    }

    private function putCartItems(Request $request, User $owner, array $items): void
    {
        if (empty($items)) {
            $request->session()->forget($this->cartKey($owner));
            return;
        }

        $request->session()->put($this->cartKey($owner), ['items' => $items]);
    }

    private function resolvePromoPricing(Product $product, Carbon $now): array
    {
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

    private function buildCartPayload(User $owner, array $items): array
    {
        $now = now();
        if (!$items) {
            return [
                'items' => [],
                'cart' => [
                    'items' => [],
                    'subtotal' => 0,
                    'tax_total' => 0,
                    'total' => 0,
                    'item_count' => 0,
                ],
            ];
        }

        $productIds = array_keys($items);
        $products = Product::query()
            ->where('user_id', $owner->id)
            ->where('item_type', Product::ITEM_TYPE_PRODUCT)
            ->where('is_active', true)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $normalizedItems = [];
        $cartItems = [];
        $subtotal = 0;
        $taxTotal = 0;
        $itemCount = 0;

        foreach ($items as $productId => $quantity) {
            $product = $products->get((int) $productId);
            if (!$product) {
                continue;
            }

            $available = (int) $product->stock_available;
            $qty = (int) $quantity;
            if ($available <= 0 || $qty <= 0) {
                continue;
            }
            $qty = min($qty, $available);
            $normalizedItems[$product->id] = $qty;

            [$basePrice, $effectivePrice, $promoActive, $discount] = $this->resolvePromoPricing($product, $now);
            $lineTotal = round($effectivePrice * $qty, 2);
            $taxRate = (float) ($product->tax_rate ?? 0);
            $lineTax = $taxRate > 0 ? round($lineTotal * ($taxRate / 100), 2) : 0;

            $cartItems[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'image_url' => $product->image_url,
                'sku' => $product->sku,
                'stock' => $available,
                'quantity' => $qty,
                'price' => $effectivePrice,
                'base_price' => $basePrice,
                'promo_price' => $promoActive ? $effectivePrice : null,
                'promo_active' => $promoActive,
                'promo_discount_percent' => $promoActive ? $discount : null,
                'line_total' => $lineTotal,
            ];

            $subtotal += $lineTotal;
            $taxTotal += $lineTax;
            $itemCount += $qty;
        }

        $subtotal = round($subtotal, 2);
        $taxTotal = round($taxTotal, 2);
        $total = round($subtotal + $taxTotal, 2);

        return [
            'items' => $normalizedItems,
            'cart' => [
                'items' => $cartItems,
                'subtotal' => $subtotal,
                'tax_total' => $taxTotal,
                'total' => $total,
                'item_count' => $itemCount,
            ],
        ];
    }

    private function normalizeFulfillment(?array $settings, User $owner): array
    {
        $settings = is_array($settings) ? $settings : [];

        $defaults = [
            'delivery_enabled' => false,
            'pickup_enabled' => false,
            'delivery_fee' => 0,
            'delivery_zone' => null,
            'pickup_address' => null,
            'prep_time_minutes' => null,
            'delivery_notes' => null,
            'pickup_notes' => null,
        ];

        $merged = array_merge($defaults, $settings);

        $deliveryEnabled = filter_var($merged['delivery_enabled'], FILTER_VALIDATE_BOOLEAN);
        $pickupEnabled = filter_var($merged['pickup_enabled'], FILTER_VALIDATE_BOOLEAN);

        $hasDeliveryZone = trim((string) ($merged['delivery_zone'] ?? '')) !== '';
        $hasPickupAddress = trim((string) ($merged['pickup_address'] ?? '')) !== '';
        $hasPrepTime = $merged['prep_time_minutes'] !== null && $merged['prep_time_minutes'] !== '';

        if ($deliveryEnabled && !$hasDeliveryZone) {
            $deliveryEnabled = false;
        }
        if ($pickupEnabled && (!$hasPickupAddress || !$hasPrepTime)) {
            $pickupEnabled = false;
        }

        $merged['delivery_enabled'] = $deliveryEnabled;
        $merged['pickup_enabled'] = $pickupEnabled;

        return $merged;
    }

    private function resolveClientRoleId(): int
    {
        return Role::firstOrCreate(
            ['name' => 'client'],
            ['description' => 'Access to client functionalities']
        )->id;
    }

    private function createPortalUser(array $payload, int $roleId): User
    {
        $name = $payload['name'] ?? $payload['email'];

        return User::create([
            'name' => $name ?: $payload['email'],
            'email' => $payload['email'],
            'password' => Hash::make(Str::random(32)),
            'role_id' => $roleId,
            'phone_number' => $payload['phone'] ?? null,
            'must_change_password' => true,
        ]);
    }

    private function createOrAttachCustomer(User $owner, User $user, array $payload): Customer
    {
        $existing = Customer::query()
            ->where('user_id', $owner->id)
            ->where('email', $payload['email'])
            ->first();

        if ($existing && !$existing->portal_user_id) {
            $existing->forceFill([
                'portal_user_id' => $user->id,
                'portal_access' => true,
                'first_name' => $payload['first_name'] ?? $existing->first_name,
                'last_name' => $payload['last_name'] ?? $existing->last_name,
                'phone' => $payload['phone'] ?? $existing->phone,
            ])->save();

            return $existing;
        }

        return Customer::create([
            'user_id' => $owner->id,
            'portal_user_id' => $user->id,
            'portal_access' => true,
            'first_name' => $payload['first_name'] ?? null,
            'last_name' => $payload['last_name'] ?? null,
            'email' => $payload['email'],
            'phone' => $payload['phone'] ?? null,
            'is_active' => true,
        ]);
    }

    private function resolveCheckoutCustomer(Request $request, User $owner, array $payload): array
    {
        $authUser = $request->user();

        if ($authUser) {
            if (!$authUser->isClient()) {
                throw ValidationException::withMessages([
                    'auth' => 'Veuillez utiliser un compte client pour commander.',
                ]);
            }

            $customer = Customer::query()
                ->where('user_id', $owner->id)
                ->where('portal_user_id', $authUser->id)
                ->first();

            if ($customer) {
                return [$customer, $authUser];
            }

            if ($authUser->customerProfile && $authUser->customerProfile->user_id !== $owner->id) {
                throw ValidationException::withMessages([
                    'email' => 'Ce compte client est deja lie a une autre entreprise.',
                ]);
            }

            $customer = $this->createOrAttachCustomer($owner, $authUser, $payload);

            return [$customer, $authUser];
        }

        $existingUser = User::query()->where('email', $payload['email'])->first();
        if ($existingUser) {
            if (!$existingUser->isClient()) {
                throw ValidationException::withMessages([
                    'email' => 'Cet email est deja utilise. Merci de vous connecter.',
                ]);
            }

            $customer = Customer::query()
                ->where('user_id', $owner->id)
                ->where('portal_user_id', $existingUser->id)
                ->first();

            if (!$customer) {
                throw ValidationException::withMessages([
                    'email' => 'Cet email est deja lie a un autre compte client.',
                ]);
            }

            Auth::login($existingUser);

            return [$customer, $existingUser];
        }

        $roleId = $this->resolveClientRoleId();
        $user = $this->createPortalUser($payload, $roleId);
        $customer = $this->createOrAttachCustomer($owner, $user, $payload);
        Auth::login($user);

        return [$customer, $user];
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

    private function applyReservations(Sale $sale, array $itemsPayload, int $accountId): void
    {
        $inventoryService = app(InventoryService::class);
        $warehouse = $inventoryService->resolveDefaultWarehouse($accountId);

        $productIds = collect($itemsPayload)->pluck('product_id')->unique()->values();
        $products = Product::query()
            ->where('user_id', $accountId)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        foreach ($itemsPayload as $payload) {
            $product = $products->get($payload['product_id']);
            if (!$product) {
                continue;
            }

            $inventoryService->adjustReserved($product, (int) $payload['quantity'], [
                'warehouse' => $warehouse,
                'reference' => $sale,
                'reason' => 'sale_reservation',
            ]);
        }
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
    public function show(Request $request, string $slug): Response
    {
        $owner = $this->resolveOwner($slug);

        $now = now();

        $products = Product::query()
            ->with('images')
            ->withAvg('approvedReviews as rating_avg', 'rating')
            ->withCount('approvedReviews as rating_count')
            ->where('user_id', $owner->id)
            ->where('item_type', Product::ITEM_TYPE_PRODUCT)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (Product $product) use ($now) {
                $discount = (float) ($product->promo_discount_percent ?? 0);
                $promoStart = $product->promo_start_at;
                $promoEnd = $product->promo_end_at;
                $promoActive = $discount > 0
                    && (!$promoStart || $promoStart->lessThanOrEqualTo($now))
                    && (!$promoEnd || $promoEnd->greaterThanOrEqualTo($now));
                $promoPrice = $discount > 0
                    ? round((float) $product->price * (1 - ($discount / 100)), 2)
                    : null;

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'price' => (float) $product->price,
                    'promo_discount_percent' => $discount ?: null,
                    'promo_start_at' => $promoStart?->toIso8601String(),
                    'promo_end_at' => $promoEnd?->toIso8601String(),
                    'promo_price' => $promoPrice,
                    'promo_active' => $promoActive,
                    'image_url' => $product->image_url,
                    'rating_avg' => $product->rating_avg !== null ? round((float) $product->rating_avg, 2) : null,
                    'rating_count' => (int) ($product->rating_count ?? 0),
                    'images' => $product->images
                        ->sortBy('sort_order')
                        ->values()
                        ->map(fn($image) => $image->url)
                        ->all(),
                    'stock' => $product->stock_available,
                    'category_id' => $product->category_id,
                    'sku' => $product->sku,
                    'created_at' => $product->created_at?->toIso8601String(),
                ];
            })
            ->values();

        $productsById = $products->keyBy('id');

        $bestSellerIds = SaleItem::query()
            ->select('sale_items.product_id', DB::raw('SUM(sale_items.quantity) as total_quantity'))
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.user_id', $owner->id)
            ->where('sales.status', Sale::STATUS_PAID)
            ->whereNotNull('sales.paid_at')
            ->where('sales.paid_at', '>=', now()->subDays(90))
            ->groupBy('sale_items.product_id')
            ->havingRaw('SUM(sale_items.quantity) >= ?', [3])
            ->orderByDesc('total_quantity')
            ->limit(8)
            ->pluck('sale_items.product_id');

        $bestSellers = collect($bestSellerIds)
            ->map(fn ($id) => $productsById->get($id))
            ->filter()
            ->values();

        $promotions = $products
            ->filter(fn ($product) => !empty($product['promo_active']))
            ->take(8)
            ->values();

        $newArrivals = $products
            ->filter(function ($product) {
                $createdAt = $product['created_at'] ?? null;
                if (!$createdAt) {
                    return false;
                }
                return Carbon::parse($createdAt)->greaterThanOrEqualTo(now()->subDays(30));
            })
            ->values();

        $storeSettings = is_array($owner->company_store_settings) ? $owner->company_store_settings : [];
        $featuredProductId = $storeSettings['featured_product_id'] ?? null;
        $featuredProduct = $featuredProductId ? $productsById->get((int) $featuredProductId) : null;

        $heroProduct = $featuredProduct
            ?? $bestSellers->first()
            ?? $promotions->first()
            ?? $newArrivals->first()
            ?? $products->first();

        $categories = ProductCategory::forAccount($owner->id)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name']);

        $cartItems = $this->getCartItems($request, $owner);
        $cartPayload = $this->buildCartPayload($owner, $cartItems);
        $this->putCartItems($request, $owner, $cartPayload['items']);
        $fulfillment = $this->normalizeFulfillment($owner->company_fulfillment, $owner);

        return Inertia::render('Public/Store', [
            'company' => [
                'name' => $owner->company_name,
                'slug' => $owner->company_slug,
                'logo_url' => $owner->company_logo_url,
                'description' => $owner->company_description,
                'store_settings' => $storeSettings,
            ],
            'products' => $products,
            'best_sellers' => $bestSellers,
            'promotions' => $promotions,
            'new_arrivals' => $newArrivals,
            'hero_product' => $heroProduct,
            'categories' => $categories,
            'cart' => $cartPayload['cart'],
            'fulfillment' => $fulfillment,
        ]);
    }

    public function reviews(Request $request, string $slug, Product $product)
    {
        $owner = $this->resolveOwner($slug);

        if ($product->user_id !== $owner->id || $product->item_type !== Product::ITEM_TYPE_PRODUCT || !$product->is_active) {
            abort(404);
        }

        $reviews = $product->approvedReviews()
            ->with(['customer:id,first_name,last_name,company_name'])
            ->latest()
            ->limit(6)
            ->get()
            ->map(function (ProductReview $review) {
                $customer = $review->customer;
                $name = 'Client';

                if ($customer) {
                    $company = trim((string) $customer->company_name);
                    $first = trim((string) $customer->first_name);
                    $last = trim((string) $customer->last_name);

                    if ($company !== '') {
                        $name = $company;
                    } elseif ($first !== '' || $last !== '') {
                        $lastInitial = $last !== '' ? Str::substr($last, 0, 1) . '.' : '';
                        $name = trim($first . ' ' . $lastInitial);
                    }
                }

                return [
                    'id' => $review->id,
                    'rating' => (int) $review->rating,
                    'title' => $review->title,
                    'comment' => $review->comment,
                    'author' => $name,
                    'created_at' => $review->created_at?->toIso8601String(),
                ];
            });

        return response()->json([
            'reviews' => $reviews,
        ]);
    }

    public function addToCart(Request $request, string $slug)
    {
        $owner = $this->resolveOwner($slug);

        $validated = $request->validate([
            'product_id' => 'required|integer',
            'quantity' => 'nullable|integer|min:1',
        ]);

        $product = Product::query()
            ->where('user_id', $owner->id)
            ->where('item_type', Product::ITEM_TYPE_PRODUCT)
            ->where('is_active', true)
            ->where('id', $validated['product_id'])
            ->firstOrFail();

        $available = (int) $product->stock_available;
        if ($available <= 0) {
            throw ValidationException::withMessages([
                'product_id' => 'Produit en rupture de stock.',
            ]);
        }

        $cartItems = $this->getCartItems($request, $owner);
        $quantity = min((int) ($validated['quantity'] ?? 1), $available);
        $cartItems[$product->id] = min($available, ($cartItems[$product->id] ?? 0) + $quantity);

        $cartPayload = $this->buildCartPayload($owner, $cartItems);
        $this->putCartItems($request, $owner, $cartPayload['items']);

        return response()->json([
            'message' => 'Panier mis a jour.',
            'cart' => $cartPayload['cart'],
        ]);
    }

    public function updateCartItem(Request $request, string $slug, Product $product)
    {
        $owner = $this->resolveOwner($slug);

        if ($product->user_id !== $owner->id || $product->item_type !== Product::ITEM_TYPE_PRODUCT || !$product->is_active) {
            abort(404);
        }

        $validated = $request->validate([
            'quantity' => 'required|integer|min:0',
        ]);

        $cartItems = $this->getCartItems($request, $owner);
        $quantity = (int) $validated['quantity'];
        $available = (int) $product->stock_available;

        if ($quantity <= 0) {
            unset($cartItems[$product->id]);
        } elseif ($available > 0) {
            $cartItems[$product->id] = min($available, $quantity);
        }

        $cartPayload = $this->buildCartPayload($owner, $cartItems);
        $this->putCartItems($request, $owner, $cartPayload['items']);

        return response()->json([
            'message' => 'Panier mis a jour.',
            'cart' => $cartPayload['cart'],
        ]);
    }

    public function removeCartItem(Request $request, string $slug, Product $product)
    {
        $owner = $this->resolveOwner($slug);

        if ($product->user_id !== $owner->id || $product->item_type !== Product::ITEM_TYPE_PRODUCT || !$product->is_active) {
            abort(404);
        }

        $cartItems = $this->getCartItems($request, $owner);
        unset($cartItems[$product->id]);

        $cartPayload = $this->buildCartPayload($owner, $cartItems);
        $this->putCartItems($request, $owner, $cartPayload['items']);

        return response()->json([
            'message' => 'Panier mis a jour.',
            'cart' => $cartPayload['cart'],
        ]);
    }

    public function clearCart(Request $request, string $slug)
    {
        $owner = $this->resolveOwner($slug);
        $this->putCartItems($request, $owner, []);

        return response()->json([
            'message' => 'Panier vide.',
            'cart' => [
                'items' => [],
                'subtotal' => 0,
                'tax_total' => 0,
                'total' => 0,
                'item_count' => 0,
            ],
        ]);
    }

    public function checkout(Request $request, string $slug)
    {
        $owner = $this->resolveOwner($slug);
        $cartItems = $this->getCartItems($request, $owner);
        $cartPayload = $this->buildCartPayload($owner, $cartItems);
        $normalizedItems = $cartPayload['items'];
        $cart = $cartPayload['cart'];

        if (empty($cart['items'])) {
            throw ValidationException::withMessages([
                'cart' => 'Votre panier est vide.',
            ]);
        }

        $rules = [
            'name' => 'nullable|string|max:150',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'delivery_address' => 'nullable|string|max:500',
            'fulfillment_method' => 'nullable|in:delivery,pickup',
            'delivery_notes' => 'nullable|string|max:2000',
            'pickup_notes' => 'nullable|string|max:2000',
            'scheduled_for' => 'nullable|date',
            'customer_notes' => 'nullable|string|max:2000',
            'substitution_allowed' => 'nullable|boolean',
            'substitution_notes' => 'nullable|string|max:2000',
        ];

        if (!$request->user()) {
            $rules['name'] = 'required|string|max:150';
            $rules['email'] = 'required|email|max:255';
        }

        $validated = $request->validate($rules);
        $name = trim($validated['name'] ?? ($request->user()?->name ?? ''));
        $email = $validated['email'] ?? ($request->user()?->email ?? null);
        $phone = $validated['phone'] ?? ($request->user()?->phone_number ?? null);

        if (!$email) {
            throw ValidationException::withMessages([
                'email' => 'Email requis.',
            ]);
        }

        $parts = preg_split('/\s+/', $name);
        $firstName = $parts ? array_shift($parts) : null;
        $lastName = $parts ? trim(implode(' ', $parts)) : null;

        [$customer, $portalUser] = $this->resolveCheckoutCustomer($request, $owner, [
            'name' => $name ?: $email,
            'email' => $email,
            'phone' => $phone,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);

        $fulfillment = $this->normalizeFulfillment($owner->company_fulfillment, $owner);
        $requestedMethod = $validated['fulfillment_method'] ?? null;
        $deliveryEnabled = (bool) ($fulfillment['delivery_enabled'] ?? false);
        $pickupEnabled = (bool) ($fulfillment['pickup_enabled'] ?? false);
        if (!$deliveryEnabled && !$pickupEnabled) {
            throw ValidationException::withMessages([
                'fulfillment_method' => 'Livraison ou retrait non configure.',
            ]);
        }
        $fulfillmentMethod = $deliveryEnabled ? 'delivery' : 'pickup';

        if ($requestedMethod === 'pickup' && $pickupEnabled) {
            $fulfillmentMethod = 'pickup';
        } elseif ($requestedMethod === 'delivery' && $deliveryEnabled) {
            $fulfillmentMethod = 'delivery';
        } elseif (!$deliveryEnabled && $pickupEnabled) {
            $fulfillmentMethod = 'pickup';
        }

        if ($fulfillmentMethod === 'delivery' && empty($validated['delivery_address'])) {
            throw ValidationException::withMessages([
                'delivery_address' => 'Adresse de livraison requise.',
            ]);
        }

        $deliveryFee = $fulfillmentMethod === 'delivery'
            ? (float) ($fulfillment['delivery_fee'] ?? 0)
            : 0;

        $scheduledFor = null;
        if (!empty($validated['scheduled_for'])) {
            $scheduledFor = Carbon::parse($validated['scheduled_for']);
        }

        [$discountRate, $discountTotal, $discountedSubtotal, $discountedTaxTotal] =
            $this->applyCustomerDiscount($customer, (float) $cart['subtotal'], (float) $cart['tax_total']);

        $total = round($discountedSubtotal + $discountedTaxTotal + $deliveryFee, 2);

        $sale = Sale::create([
            'user_id' => $owner->id,
            'customer_id' => $customer->id,
            'status' => Sale::STATUS_PENDING,
            'subtotal' => $cart['subtotal'],
            'tax_total' => $discountedTaxTotal,
            'discount_rate' => $discountRate,
            'discount_total' => $discountTotal,
            'delivery_fee' => $deliveryFee,
            'total' => $total,
            'fulfillment_method' => $fulfillmentMethod,
            'fulfillment_status' => Sale::FULFILLMENT_PENDING,
            'delivery_address' => $fulfillmentMethod === 'delivery' ? ($validated['delivery_address'] ?? null) : null,
            'delivery_notes' => $validated['delivery_notes'] ?? null,
            'pickup_notes' => $validated['pickup_notes'] ?? null,
            'scheduled_for' => $scheduledFor,
            'customer_notes' => $validated['customer_notes'] ?? null,
            'substitution_allowed' => array_key_exists('substitution_allowed', $validated)
                ? filter_var($validated['substitution_allowed'], FILTER_VALIDATE_BOOLEAN)
                : null,
            'substitution_notes' => $validated['substitution_notes'] ?? null,
            'source' => 'public_store',
        ]);

        $itemsPayload = collect($cart['items'])->map(fn($item) => [
            'product_id' => $item['product_id'],
            'description' => $item['name'],
            'quantity' => $item['quantity'],
            'price' => $item['price'],
            'total' => $item['line_total'],
        ])->values()->all();

        foreach ($itemsPayload as $payload) {
            $sale->items()->create($payload);
        }

        $this->applyReservations($sale, $itemsPayload, $owner->id);

        app(SaleTimelineService::class)->record($portalUser, $sale, 'sale_created', [
            'source' => 'public_store',
            'fulfillment_method' => $sale->fulfillment_method,
        ]);

        $this->notifyInternalOrder($owner, $sale, 'Nouvelle commande', 'Une nouvelle commande client est arrivee.');
        $this->putCartItems($request, $owner, []);

        $redirectUrl = route('portal.orders.show', $sale);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Commande envoyee.',
                'redirect_url' => $redirectUrl,
            ], 201);
        }

        return redirect($redirectUrl)->with('success', 'Commande envoyee.');
    }
}
