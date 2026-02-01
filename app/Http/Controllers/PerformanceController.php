<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PerformanceController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $accountOwner = $this->resolvePerformanceAccount($user);
        $now = now();

        $employeePerformance = $this->buildSellerPerformance($accountOwner->id, $now, 12, 6);
        $clientPerformance = $this->buildCustomerPerformance($accountOwner->id, $now, 10);

        return $this->inertiaOrJson('Performance/Index', [
            'employeePerformance' => $employeePerformance,
            'clientPerformance' => $clientPerformance,
            'tab' => $request->query('tab', 'clients'),
        ]);
    }

    public function employee(Request $request, User $employee)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $accountOwner = $this->resolvePerformanceAccount($user);
        $accountId = $accountOwner->id;

        $membership = null;
        if ($employee->id !== $accountId) {
            $membership = TeamMember::query()
                ->where('account_id', $accountId)
                ->where('user_id', $employee->id)
                ->first();

            if (!$membership) {
                abort(404);
            }
        }

        $employeePayload = [
            'id' => $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'profile_picture_url' => $employee->profile_picture_url,
            'phone' => $membership?->phone ?? $employee->phone_number,
            'title' => $membership?->title,
            'role' => $membership?->role ?? ($employee->id === $accountId ? 'owner' : null),
            'is_active' => $membership?->is_active ?? true,
            'joined_at' => $membership?->created_at?->toDateString() ?? $employee->created_at?->toDateString(),
        ];

        $performance = $this->buildSellerDetailPerformance($accountId, $employee->id, now(), 6, 6);

        return $this->inertiaOrJson('Performance/EmployeeShow', [
            'employee' => $employeePayload,
            'performance' => $performance,
        ]);
    }

    private function resolvePerformanceAccount(User $user): User
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

            $canManage = $membership?->hasPermission('sales.manage') ?? false;
            if (!$canManage) {
                abort(403);
            }
        }

        return $owner;
    }

    private function buildSellerPerformance(int $accountId, Carbon $now, int $sellerLimit = 12, int $productLimit = 6): array
    {
        $periods = [
            'day' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
        ];

        $periodData = [];

        foreach ($periods as $key => [$start, $end]) {
            $periodData[$key] = $this->buildSellerPerformancePeriod($accountId, $start, $end, $sellerLimit, $productLimit);
        }

        $sellerOfPeriods = [];

        foreach ($periodData as $key => $period) {
            $topSellers = $period['top_sellers'] ?? [];
            $sellerOfPeriods[$key] = collect($topSellers)
                ->first(fn($seller) => ($seller['type'] ?? null) === 'user')
                ?? ($topSellers[0] ?? null);
        }

        $sellerOfYear = $sellerOfPeriods['year'] ?? null;

        return [
            'periods' => $periodData,
            'seller_of_periods' => $sellerOfPeriods,
            'seller_of_year' => $sellerOfYear,
        ];
    }

    private function buildSellerPerformancePeriod(
        int $accountId,
        Carbon $start,
        Carbon $end,
        ?int $sellerLimit = 12,
        ?int $productLimit = 6
    ): array {
        $salesQuery = Sale::query()
            ->where('user_id', $accountId)
            ->where('status', Sale::STATUS_PAID)
            ->whereBetween('created_at', [$start, $end]);

        $orders = (clone $salesQuery)->count();
        $revenue = (float) (clone $salesQuery)->sum('total');
        $avgOrder = $orders > 0 ? round($revenue / $orders, 2) : 0.0;
        $uniqueCustomers = (clone $salesQuery)
            ->whereNotNull('customer_id')
            ->distinct('customer_id')
            ->count('customer_id');

        $activeSellerIds = (clone $salesQuery)
            ->whereNotNull('created_by_user_id')
            ->distinct('created_by_user_id')
            ->pluck('created_by_user_id');
        $activeSellers = $activeSellerIds->count();
        $revenuePerSeller = $activeSellers > 0 ? round($revenue / $activeSellers, 2) : 0.0;

        $itemsSold = (int) SaleItem::query()
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.user_id', $accountId)
            ->where('sales.status', Sale::STATUS_PAID)
            ->whereBetween('sales.created_at', [$start, $end])
            ->sum('sale_items.quantity');

        $sellerRowsQuery = Sale::query()
            ->select(DB::raw('COALESCE(created_by_user_id, 0) as seller_id'), DB::raw('COUNT(*) as orders'), DB::raw('SUM(total) as revenue'))
            ->where('user_id', $accountId)
            ->where('status', Sale::STATUS_PAID)
            ->whereBetween('created_at', [$start, $end])
            ->groupBy(DB::raw('COALESCE(created_by_user_id, 0)'))
            ->orderByDesc('revenue');

        if ($sellerLimit) {
            $sellerRowsQuery->limit($sellerLimit);
        }

        $sellerRows = $sellerRowsQuery->get();

        $sellerIds = $sellerRows->pluck('seller_id')
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();
        $sellerMap = $sellerIds->isNotEmpty()
            ? User::query()
                ->whereIn('id', $sellerIds)
                ->get(['id', 'name', 'profile_picture'])
                ->keyBy('id')
            : collect();

        $itemsBySeller = SaleItem::query()
            ->select(DB::raw('COALESCE(sales.created_by_user_id, 0) as seller_id'), DB::raw('SUM(sale_items.quantity) as items'))
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.user_id', $accountId)
            ->where('sales.status', Sale::STATUS_PAID)
            ->whereBetween('sales.created_at', [$start, $end])
            ->groupBy(DB::raw('COALESCE(sales.created_by_user_id, 0)'))
            ->pluck('items', 'seller_id')
            ->toArray();

        $topSellers = $sellerRows->map(function ($row) use ($sellerMap, $itemsBySeller) {
            $sellerId = (int) $row->seller_id;
            $isOnline = $sellerId === 0;
            $seller = $isOnline ? null : $sellerMap->get($sellerId);
            $items = (int) ($itemsBySeller[$sellerId] ?? 0);

            return [
                'id' => $sellerId,
                'type' => $isOnline ? 'online' : 'user',
                'name' => $seller?->name ?? 'Seller',
                'profile_picture_url' => $seller?->profile_picture_url,
                'orders' => (int) $row->orders,
                'revenue' => (float) $row->revenue,
                'items' => $items,
            ];
        })->values();

        $topProductRowsQuery = SaleItem::query()
            ->select('sale_items.product_id', DB::raw('SUM(sale_items.quantity) as quantity'), DB::raw('SUM(sale_items.total) as revenue'))
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.user_id', $accountId)
            ->where('sales.status', Sale::STATUS_PAID)
            ->whereBetween('sales.created_at', [$start, $end])
            ->groupBy('sale_items.product_id')
            ->orderByDesc('revenue');

        if ($productLimit) {
            $topProductRowsQuery->limit($productLimit);
        }

        $topProductRows = $topProductRowsQuery->get();

        $productMap = $topProductRows->isNotEmpty()
            ? Product::query()
                ->whereIn('id', $topProductRows->pluck('product_id'))
                ->get(['id', 'name', 'image'])
                ->keyBy('id')
            : collect();

        $topProducts = $topProductRows->map(function ($row) use ($productMap) {
            $product = $productMap->get($row->product_id);

            return [
                'id' => (int) $row->product_id,
                'name' => $product?->name ?? 'Product',
                'image_url' => $product?->image_url,
                'quantity' => (int) $row->quantity,
                'revenue' => (float) $row->revenue,
            ];
        })->values();

        return [
            'range' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'orders' => $orders,
            'revenue' => $revenue,
            'avg_order' => $avgOrder,
            'revenue_per_seller' => $revenuePerSeller,
            'items_sold' => $itemsSold,
            'customers' => (int) $uniqueCustomers,
            'active_sellers' => $activeSellers,
            'top_sellers' => $topSellers,
            'top_products' => $topProducts,
        ];
    }

    private function buildCustomerPerformance(int $accountId, Carbon $now, int $customerLimit = 10): array
    {
        $periods = [
            'day' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
        ];

        $periodData = [];

        foreach ($periods as $key => [$start, $end]) {
            $periodData[$key] = $this->buildCustomerPerformancePeriod($accountId, $start, $end, $customerLimit);
        }

        $customerOfPeriods = [];

        foreach ($periodData as $key => $period) {
            $customerOfPeriods[$key] = $period['top_customers'][0] ?? null;
        }

        $customerOfYear = $customerOfPeriods['year'] ?? null;

        return [
            'periods' => $periodData,
            'customer_of_periods' => $customerOfPeriods,
            'customer_of_year' => $customerOfYear,
        ];
    }

    private function buildCustomerPerformancePeriod(
        int $accountId,
        Carbon $start,
        Carbon $end,
        ?int $customerLimit = 10
    ): array {
        $salesQuery = Sale::query()
            ->where('user_id', $accountId)
            ->where('status', Sale::STATUS_PAID)
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('customer_id');

        $orders = (clone $salesQuery)->count();
        $revenue = (float) (clone $salesQuery)->sum('total');
        $avgOrder = $orders > 0 ? round($revenue / $orders, 2) : 0.0;
        $uniqueCustomers = (clone $salesQuery)
            ->distinct('customer_id')
            ->count('customer_id');
        $avgCustomerValue = $uniqueCustomers > 0 ? round($revenue / $uniqueCustomers, 2) : 0.0;

        $itemsSold = (int) SaleItem::query()
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.user_id', $accountId)
            ->where('sales.status', Sale::STATUS_PAID)
            ->whereBetween('sales.created_at', [$start, $end])
            ->whereNotNull('sales.customer_id')
            ->sum('sale_items.quantity');

        $customerRowsQuery = Sale::query()
            ->select('customer_id', DB::raw('COUNT(*) as orders'), DB::raw('SUM(total) as revenue'))
            ->where('user_id', $accountId)
            ->where('status', Sale::STATUS_PAID)
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->orderByDesc('revenue');

        if ($customerLimit) {
            $customerRowsQuery->limit($customerLimit);
        }

        $customerRows = $customerRowsQuery->get();
        $customerIds = $customerRows->pluck('customer_id')->filter()->unique()->values();

        $customerMap = $customerIds->isNotEmpty()
            ? Customer::query()
                ->whereIn('id', $customerIds)
                ->get(['id', 'first_name', 'last_name', 'company_name', 'logo'])
                ->keyBy('id')
            : collect();

        $itemsByCustomer = SaleItem::query()
            ->select(DB::raw('sales.customer_id as customer_id'), DB::raw('SUM(sale_items.quantity) as items'))
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.user_id', $accountId)
            ->where('sales.status', Sale::STATUS_PAID)
            ->whereBetween('sales.created_at', [$start, $end])
            ->whereNotNull('sales.customer_id')
            ->groupBy('sales.customer_id')
            ->pluck('items', 'customer_id')
            ->toArray();

        $topCustomers = $customerRows->map(function ($row) use ($customerMap, $itemsByCustomer) {
            $customerId = (int) $row->customer_id;
            $customer = $customerMap->get($customerId);
            $items = (int) ($itemsByCustomer[$customerId] ?? 0);

            return [
                'id' => $customerId,
                'name' => $this->resolveCustomerName($customer),
                'logo_url' => $customer?->logo_url,
                'orders' => (int) $row->orders,
                'revenue' => (float) $row->revenue,
                'items' => $items,
            ];
        })->values();

        return [
            'range' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'orders' => $orders,
            'revenue' => $revenue,
            'avg_order' => $avgOrder,
            'avg_customer_value' => $avgCustomerValue,
            'items_sold' => $itemsSold,
            'customers' => (int) $uniqueCustomers,
            'top_customers' => $topCustomers,
        ];
    }

    private function buildSellerDetailPerformance(
        int $accountId,
        int $sellerId,
        Carbon $now,
        int $productLimit = 6,
        int $customerLimit = 6
    ): array {
        $periods = [
            'day' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
        ];

        $periodData = [];

        foreach ($periods as $key => [$start, $end]) {
            $periodData[$key] = $this->buildSellerDetailPerformancePeriod(
                $accountId,
                $sellerId,
                $start,
                $end,
                $productLimit,
                $customerLimit
            );
        }

        return [
            'periods' => $periodData,
        ];
    }

    private function buildSellerDetailPerformancePeriod(
        int $accountId,
        int $sellerId,
        Carbon $start,
        Carbon $end,
        ?int $productLimit = 6,
        ?int $customerLimit = 6
    ): array {
        $salesQuery = Sale::query()
            ->where('user_id', $accountId)
            ->where('status', Sale::STATUS_PAID)
            ->where('created_by_user_id', $sellerId)
            ->whereBetween('created_at', [$start, $end]);

        $orders = (clone $salesQuery)->count();
        $revenue = (float) (clone $salesQuery)->sum('total');
        $avgOrder = $orders > 0 ? round($revenue / $orders, 2) : 0.0;
        $uniqueCustomers = (clone $salesQuery)
            ->whereNotNull('customer_id')
            ->distinct('customer_id')
            ->count('customer_id');

        $itemsSold = (int) SaleItem::query()
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.user_id', $accountId)
            ->where('sales.status', Sale::STATUS_PAID)
            ->where('sales.created_by_user_id', $sellerId)
            ->whereBetween('sales.created_at', [$start, $end])
            ->sum('sale_items.quantity');

        $topProductRowsQuery = SaleItem::query()
            ->select('sale_items.product_id', DB::raw('SUM(sale_items.quantity) as quantity'), DB::raw('SUM(sale_items.total) as revenue'))
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.user_id', $accountId)
            ->where('sales.status', Sale::STATUS_PAID)
            ->where('sales.created_by_user_id', $sellerId)
            ->whereBetween('sales.created_at', [$start, $end])
            ->groupBy('sale_items.product_id')
            ->orderByDesc('revenue');

        if ($productLimit) {
            $topProductRowsQuery->limit($productLimit);
        }

        $topProductRows = $topProductRowsQuery->get();

        $productMap = $topProductRows->isNotEmpty()
            ? Product::query()
                ->whereIn('id', $topProductRows->pluck('product_id'))
                ->get(['id', 'name', 'image'])
                ->keyBy('id')
            : collect();

        $topProducts = $topProductRows->map(function ($row) use ($productMap) {
            $product = $productMap->get($row->product_id);

            return [
                'id' => (int) $row->product_id,
                'name' => $product?->name ?? 'Product',
                'image_url' => $product?->image_url,
                'quantity' => (int) $row->quantity,
                'revenue' => (float) $row->revenue,
            ];
        })->values();

        $customerRowsQuery = Sale::query()
            ->select('customer_id', DB::raw('COUNT(*) as orders'), DB::raw('SUM(total) as revenue'))
            ->where('user_id', $accountId)
            ->where('status', Sale::STATUS_PAID)
            ->where('created_by_user_id', $sellerId)
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->orderByDesc('revenue');

        if ($customerLimit) {
            $customerRowsQuery->limit($customerLimit);
        }

        $customerRows = $customerRowsQuery->get();
        $customerIds = $customerRows->pluck('customer_id')->filter()->unique()->values();

        $customerMap = $customerIds->isNotEmpty()
            ? Customer::query()
                ->whereIn('id', $customerIds)
                ->get(['id', 'first_name', 'last_name', 'company_name', 'logo'])
                ->keyBy('id')
            : collect();

        $itemsByCustomer = SaleItem::query()
            ->select(DB::raw('sales.customer_id as customer_id'), DB::raw('SUM(sale_items.quantity) as items'))
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.user_id', $accountId)
            ->where('sales.status', Sale::STATUS_PAID)
            ->where('sales.created_by_user_id', $sellerId)
            ->whereBetween('sales.created_at', [$start, $end])
            ->whereNotNull('sales.customer_id')
            ->groupBy('sales.customer_id')
            ->pluck('items', 'customer_id')
            ->toArray();

        $topCustomers = $customerRows->map(function ($row) use ($customerMap, $itemsByCustomer) {
            $customerId = (int) $row->customer_id;
            $customer = $customerMap->get($customerId);
            $items = (int) ($itemsByCustomer[$customerId] ?? 0);

            return [
                'id' => $customerId,
                'name' => $this->resolveCustomerName($customer),
                'logo_url' => $customer?->logo_url,
                'orders' => (int) $row->orders,
                'revenue' => (float) $row->revenue,
                'items' => $items,
            ];
        })->values();

        return [
            'range' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'orders' => $orders,
            'revenue' => $revenue,
            'avg_order' => $avgOrder,
            'items_sold' => $itemsSold,
            'customers' => (int) $uniqueCustomers,
            'top_products' => $topProducts,
            'top_customers' => $topCustomers,
        ];
    }

    private function resolveCustomerName(?Customer $customer): string
    {
        if (!$customer) {
            return 'Customer';
        }

        if ($customer->company_name) {
            return $customer->company_name;
        }

        $name = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
        return $name !== '' ? $name : 'Customer';
    }
}
