<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;

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

        $accountCompanyType = null;
        if ($user && $accountId) {
            $accountCompanyType = $accountId === $user->id
                ? $user->company_type
                : User::query()->whereKey($accountId)->value('company_type');
        }

        $defaultItemType = $accountCompanyType === 'products'
            ? Product::ITEM_TYPE_PRODUCT
            : Product::ITEM_TYPE_SERVICE;

        $itemType = $defaultItemType;
        $requestedItemType = $request->input('item_type');
        if (!$accountCompanyType && in_array($requestedItemType, [Product::ITEM_TYPE_PRODUCT, Product::ITEM_TYPE_SERVICE], true)) {
            $itemType = $requestedItemType;
        }

        $products = Product::query()
            ->where('name', 'like', "%{$query}%")
            ->byUser($accountId)
            ->where('item_type', $itemType)
            ->where('is_active', true)
            ->limit(10)
            ->get(['id', 'name', 'price', 'image', 'unit']);

        return response()->json($products);
    }
}

