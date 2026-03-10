<?php

namespace App\Queries\Dashboard;

use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductLot;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\TeamMember;
use App\Models\User;
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
        $restrictSales = $membership
            && ! $membership->hasPermission('sales.manage')
            && $membership->hasPermission('sales.pos');

        $salesBaseQuery = Sale::query()
            ->where('user_id', $accountId)
            ->where('status', Sale::STATUS_PAID)
            ->when($restrictSales && $user, fn ($query) => $query->where('created_by_user_id', $user->id));
        $salesTodayQuery = (clone $salesBaseQuery)->whereDate('created_at', $today);
        $salesMonthQuery = (clone $salesBaseQuery)
            ->whereBetween('created_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()]);

        $productsQuery = Product::query()
            ->products()
            ->byUser($accountId);

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

        $stats['reserved_total'] = (int) ProductInventory::query()
            ->whereHas('product', fn ($query) => $query->where('user_id', $accountId))
            ->sum('reserved');
        $stats['damaged_total'] = (int) ProductInventory::query()
            ->whereHas('product', fn ($query) => $query->where('user_id', $accountId))
            ->sum('damaged');

        $expiringDate = $now->copy()->addDays(30)->toDateString();
        $stats['expired_lots'] = (int) ProductLot::query()
            ->whereHas('product', fn ($query) => $query->where('user_id', $accountId))
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', $today)
            ->count();
        $stats['expiring_lots'] = (int) ProductLot::query()
            ->whereHas('product', fn ($query) => $query->where('user_id', $accountId))
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '>=', $today)
            ->whereDate('expires_at', '<=', $expiringDate)
            ->count();

        $recentSales = (clone $salesBaseQuery)
            ->with('customer:id,first_name,last_name,company_name')
            ->latest()
            ->limit(8)
            ->get(['id', 'number', 'status', 'total', 'created_at', 'customer_id']);

        $stockAlerts = (clone $productsQuery)
            ->where(function ($query) {
                $query->where('stock', '<=', 0)
                    ->orWhereColumn('stock', '<=', 'minimum_stock');
            })
            ->orderBy('stock')
            ->limit(8)
            ->get(['id', 'name', 'stock', 'minimum_stock', 'image', 'supplier_name', 'supplier_email']);

        $topSales = SaleItem::query()
            ->select('sale_items.product_id', DB::raw('SUM(sale_items.quantity) as quantity'))
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.user_id', $accountId)
            ->where('sales.status', Sale::STATUS_PAID)
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
            'stats' => $stats,
            'recentSales' => $recentSales,
            'stockAlerts' => $stockAlerts,
            'topProducts' => $topProducts,
        ];
    }
}
