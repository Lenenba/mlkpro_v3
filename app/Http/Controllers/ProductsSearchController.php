<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProductsSearchController extends Controller
{
    /**
     * Search for products or services by name (scoped to the account owner).
     */
    public function __invoke(Request $request)
    {
        $query = $request->input('query', '');
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? 0;

        if ($request->routeIs('product.search')) {
            if (! $user) {
                abort(403);
            }

            Gate::forUser($user)->authorize('viewAny', Product::class);
        }

        $accountCompanyType = null;
        if ($user && $accountId) {
            $accountCompanyType = $accountId === $user->id
                ? $user->company_type
                : User::query()->whereKey($accountId)->value('company_type');
        }

        $defaultItemType = $request->routeIs('product.search')
            ? Product::ITEM_TYPE_PRODUCT
            : ($accountCompanyType === 'products'
                ? Product::ITEM_TYPE_PRODUCT
                : Product::ITEM_TYPE_SERVICE);

        $requestedItemType = $request->input('item_type');
        $allowedItemTypes = [Product::ITEM_TYPE_PRODUCT, Product::ITEM_TYPE_SERVICE, 'all'];
        $itemType = in_array($requestedItemType, $allowedItemTypes, true)
            ? $requestedItemType
            : $defaultItemType;

        $productsQuery = Product::query()
            ->where('name', 'like', "%{$query}%")
            ->byUser($accountId)
            ->where('is_active', true)
            ->limit(10);

        if ($itemType !== 'all') {
            $productsQuery->where('item_type', $itemType);
        }

        $products = $productsQuery->get(['id', 'name', 'price', 'image', 'unit', 'item_type']);

        return response()->json($products);
    }
}
