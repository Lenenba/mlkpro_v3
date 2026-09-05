<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\User;
use App\Services\Rbac\AccessControl;
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

        if (! $user) {
            abort(403);
        }

        $owner = (int) $user->id === (int) $accountId
            ? $user
            : User::query()->find($accountId);
        $isCatalogSearch = $request->routeIs('catalog.search');
        $canUseProductModule = Gate::forUser($user)->allows('viewAny', Product::class);
        $context = (string) $request->query('scope', '');
        $contextRule = match ($context) {
            'quote' => [
                'feature' => 'quotes',
                'permissions' => ['quotes.create', 'quotes.edit'],
            ],
            'job' => [
                'feature' => 'jobs',
                'permissions' => ['jobs.create', 'jobs.edit'],
            ],
            'sales' => [
                'feature' => 'sales',
                'permissions' => ['sales.manage', 'sales.pos'],
            ],
            default => null,
        };
        $canUseContextualSearch = $contextRule
            && $owner?->hasCompanyFeature($contextRule['feature'])
            && collect($contextRule['permissions'])->contains(
                fn (string $permission): bool => app(AccessControl::class)
                    ->userHasPermission($user, $permission, (int) $accountId)
            );

        if ($isCatalogSearch) {
            if ((int) $user->id !== (int) $accountId) {
                abort(403);
            }
        } elseif (! $canUseProductModule && ! $canUseContextualSearch) {
            abort(403);
        }

        $defaultItemType = $isCatalogSearch
            ? ($owner?->company_type === 'products'
                ? Product::ITEM_TYPE_PRODUCT
                : Product::ITEM_TYPE_SERVICE)
            : Product::ITEM_TYPE_PRODUCT;

        $requestedItemType = $request->input('item_type');
        $allowedItemTypes = $isCatalogSearch || $canUseContextualSearch
            ? [Product::ITEM_TYPE_PRODUCT, Product::ITEM_TYPE_SERVICE, 'all']
            : [Product::ITEM_TYPE_PRODUCT];
        if ($requestedItemType !== null && ! in_array($requestedItemType, $allowedItemTypes, true)) {
            abort(403);
        }
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
