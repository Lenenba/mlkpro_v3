<?php

namespace App\Queries\Dashboard;

use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductLot;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Rbac\CompanyModuleAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardProductsOverviewQuery
{
    public function execute(
        int $accountId,
        ?User $user,
        ?TeamMember $membership,
        Carbon $now,
        string $today
    ): array {
        if ($membership && $user) {
            $user->setRelation('teamMembership', $membership);
        }

        $canViewSales = ! $membership
            || $membership->hasPermission('sales.manage')
            || $membership->hasPermission('sales.pos');
        $canViewProducts = ! $membership
            || ($user && app(CompanyModuleAccess::class)->allows($user, 'products', $accountId));
        $canRequestStock = ! $membership;
        $restrictSales = $membership
            && ! $membership->hasPermission('sales.manage')
            && $membership->hasPermission('sales.pos');

        $salesBaseQuery = Sale::query()
            ->where('user_id', $accountId)
            ->where('status', Sale::STATUS_PAID)
            ->when(! $canViewSales, fn ($query) => $query->whereRaw('1 = 0'))
            ->when($restrictSales && $user, fn ($query) => $query->where('created_by_user_id', $user->id));
        $salesTodayQuery = (clone $salesBaseQuery)->whereDate('created_at', $today);
        $salesMonthQuery = (clone $salesBaseQuery)
            ->whereBetween('created_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()]);

        $productsQuery = Product::query()
            ->products()
            ->byUser($accountId)
            ->when(! $canViewProducts, fn ($query) => $query->whereRaw('1 = 0'));

        $stats = [
            'sales_today' => (clone $salesTodayQuery)->count(),
            'sales_month' => (clone $salesMonthQuery)->count(),
            'revenue_today' => (float) (clone $salesTodayQuery)->sum('total'),
            'revenue_month' => (float) (clone $salesMonthQuery)->sum('total'),
            'inventory_value' => (float) (clone $productsQuery)
                ->select(DB::raw('COALESCE(SUM(stock * COALESCE(NULLIF(cost_price, 0), price)), 0) as value'))
                ->value('value'),
            'products_total' => (clone $productsQuery)->count(),
            'low_stock' => (clone $productsQuery)
                ->whereColumn('stock', '<=', 'minimum_stock')
                ->where('stock', '>', 0)
                ->count(),
            'out_of_stock' => (clone $productsQuery)
                ->where('stock', '<=', 0)
                ->count(),
        ];

        $stats['reserved_total'] = $canViewProducts
            ? (int) ProductInventory::query()
                ->whereHas('product', fn ($query) => $query->products()->byUser($accountId))
                ->sum('reserved')
            : 0;
        $stats['damaged_total'] = $canViewProducts
            ? (int) ProductInventory::query()
                ->whereHas('product', fn ($query) => $query->products()->byUser($accountId))
                ->sum('damaged')
            : 0;

        $expiringDate = $now->copy()->addDays(30)->toDateString();
        $stats['expired_lots'] = $canViewProducts
            ? (int) ProductLot::query()
                ->whereHas('product', fn ($query) => $query->products()->byUser($accountId))
                ->whereNotNull('expires_at')
                ->whereDate('expires_at', '<', $today)
                ->count()
            : 0;
        $stats['expiring_lots'] = $canViewProducts
            ? (int) ProductLot::query()
                ->whereHas('product', fn ($query) => $query->products()->byUser($accountId))
                ->whereNotNull('expires_at')
                ->whereDate('expires_at', '>=', $today)
                ->whereDate('expires_at', '<=', $expiringDate)
                ->count()
            : 0;

        $recentSales = $canViewSales
            ? (clone $salesBaseQuery)
                ->with('customer:id,first_name,last_name,company_name')
                ->latest()
                ->limit(8)
                ->get(['id', 'number', 'status', 'total', 'created_at', 'customer_id'])
            : collect();

        $stockAlerts = $canViewProducts
            ? (clone $productsQuery)
                ->where(function ($query) {
                    $query->where('stock', '<=', 0)
                        ->orWhereColumn('stock', '<=', 'minimum_stock');
                })
                ->orderBy('stock')
                ->limit(8)
                ->get(['id', 'name', 'stock', 'minimum_stock', 'image', 'supplier_name', 'supplier_email'])
            : collect();

        $topSales = SaleItem::query()
            ->select('sale_items.product_id', DB::raw('SUM(sale_items.quantity) as quantity'))
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.user_id', $accountId)
            ->where('sales.status', Sale::STATUS_PAID)
            ->when(! $canViewSales, fn ($query) => $query->whereRaw('1 = 0'))
            ->when($restrictSales && $user, fn ($query) => $query->where('sales.created_by_user_id', $user->id))
            ->groupBy('sale_items.product_id')
            ->orderByDesc('quantity')
            ->limit(6)
            ->get();

        $topProducts = collect();
        if ($topSales->isNotEmpty()) {
            $productMap = Product::query()
                ->whereIn('id', $topSales->pluck('product_id'))
                ->get(['id', 'name', 'image'])
                ->keyBy('id');

            $topProducts = $topSales->map(function ($row) use ($productMap) {
                $product = $productMap->get($row->product_id);

                return [
                    'id' => $row->product_id,
                    'name' => $product?->name ?? 'Product',
                    'image_url' => $product?->image_url,
                    'quantity' => (int) $row->quantity,
                ];
            })->values();
        }

        return [
            'access' => [
                'sales' => $canViewSales,
                'products' => $canViewProducts,
                'request_stock' => $canRequestStock,
            ],
            'stats' => $stats,
            'recentSales' => $recentSales,
            'stockAlerts' => $stockAlerts,
            'topProducts' => $topProducts,
        ];
    }
}
