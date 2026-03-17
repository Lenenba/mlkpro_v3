<?php

namespace App\Services\Campaigns;

use App\Enums\OfferType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OfferSearchService
{
    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function search(User $accountOwner, array $filters): array
    {
        $search = trim((string) ($filters['q'] ?? ''));
        $type = $this->normalizedType($filters['type'] ?? null);
        $sort = $this->normalizedSort($filters['sort'] ?? null, $search !== '');
        $limit = $this->normalizedLimit($filters['limit'] ?? null);
        $cursor = $this->normalizedCursor($filters['cursor'] ?? null);

        $query = Product::query()
            ->where('products.user_id', $accountOwner->id)
            ->with(['category:id,name']);

        if ($type !== 'all') {
            $query->where('products.item_type', $type);
        }

        $this->applySearch($query, $search, $sort);
        $this->applyFilters($query, $filters);
        $this->applySorting($query, $sort, $search);

        /** @var CursorPaginator<int, Product> $paginator */
        $paginator = $query->cursorPaginate($limit, ['*'], 'cursor', $cursor);

        return [
            'items' => $this->mapItems($paginator->getCollection()),
            'nextCursor' => $paginator->nextCursor()?->encode(),
            'meta' => [
                'limit' => $limit,
                'sort' => $sort,
                'type' => $type,
                'hasMore' => $paginator->hasMorePages(),
            ],
            'filters' => $this->filterOptions($accountOwner),
        ];
    }

    private function normalizedType(mixed $type): string
    {
        $candidate = strtolower(trim((string) $type));
        if ($candidate === OfferType::PRODUCT->value || $candidate === OfferType::SERVICE->value) {
            return $candidate;
        }

        return 'all';
    }

    private function normalizedSort(mixed $sort, bool $hasSearch): string
    {
        $candidate = strtolower(trim((string) $sort));
        $allowed = ['relevance', 'newest', 'best_sellers', 'alphabetical'];
        if (in_array($candidate, $allowed, true)) {
            return $candidate;
        }

        return $hasSearch ? 'relevance' : 'newest';
    }

    private function normalizedLimit(mixed $limit): int
    {
        $candidate = is_numeric($limit) ? (int) $limit : 20;
        if ($candidate < 1) {
            return 20;
        }

        return min($candidate, 50);
    }

    private function normalizedCursor(mixed $cursor): ?string
    {
        $candidate = trim((string) $cursor);
        return $candidate !== '' ? $candidate : null;
    }

    private function applySearch(Builder $query, string $search, string $sort): void
    {
        if ($search === '') {
            return;
        }

        $query->where(function (Builder $builder) use ($search): void {
            $builder
                ->where('products.name', 'like', '%' . $search . '%')
                ->orWhere('products.description', 'like', '%' . $search . '%')
                ->orWhere('products.sku', 'like', '%' . $search . '%')
                ->orWhere('products.number', 'like', '%' . $search . '%');
        });

        if ($sort !== 'relevance') {
            return;
        }

        $query->selectRaw(
            'CASE
                WHEN products.name LIKE ? THEN 4
                WHEN products.name LIKE ? THEN 3
                WHEN products.sku LIKE ? THEN 2
                WHEN products.number LIKE ? THEN 2
                WHEN products.description LIKE ? THEN 1
                ELSE 0
            END AS relevance_score',
            [
                $search . '%',
                '%' . $search . '%',
                $search . '%',
                $search . '%',
                '%' . $search . '%',
            ]
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $categories = collect($filters['category'] ?? $filters['categories'] ?? [])
            ->merge([$filters['category_id'] ?? null])
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values()
            ->all();

        if ($categories !== []) {
            $query->whereIn('products.category_id', $categories);
        }

        $status = strtolower(trim((string) ($filters['status'] ?? 'active')));
        if ($status === 'active') {
            $query->where('products.is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('products.is_active', false);
        }

        $availability = strtolower(trim((string) ($filters['availability'] ?? '')));
        if ($availability === 'in_stock') {
            $query->where('products.item_type', OfferType::PRODUCT->value)
                ->where('products.stock', '>', 0);
        } elseif ($availability === 'bookable') {
            $query->where('products.item_type', OfferType::SERVICE->value)
                ->where('products.is_active', true);
        }

        $minPrice = $filters['price_min'] ?? null;
        if (is_numeric($minPrice)) {
            $query->where('products.price', '>=', (float) $minPrice);
        }

        $maxPrice = $filters['price_max'] ?? null;
        if (is_numeric($maxPrice)) {
            $query->where('products.price', '<=', (float) $maxPrice);
        }

        $tags = collect($filters['tags'] ?? [])
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn ($value) => trim((string) $value))
            ->unique()
            ->values();

        if ($tags->isNotEmpty()) {
            $query->where(function (Builder $builder) use ($tags): void {
                foreach ($tags as $index => $tag) {
                    if ($index === 0) {
                        $builder->whereJsonContains('products.tags', $tag);
                        continue;
                    }

                    $builder->orWhereJsonContains('products.tags', $tag);
                }
            });
        }
    }

    private function applySorting(Builder $query, string $sort, string $search): void
    {
        switch ($sort) {
            case 'best_sellers':
                $salesSubquery = DB::table('sale_items')
                    ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                    ->where('sales.status', Sale::STATUS_PAID)
                    ->groupBy('sale_items.product_id')
                    ->selectRaw('sale_items.product_id, SUM(sale_items.quantity) as sold_qty');

                $query->leftJoinSub($salesSubquery, 'offer_sales', function ($join): void {
                    $join->on('offer_sales.product_id', '=', 'products.id');
                });

                $query->selectRaw('COALESCE(offer_sales.sold_qty, 0) as best_sellers_count')
                    ->orderByDesc('best_sellers_count')
                    ->orderByDesc('products.id');
                return;

            case 'alphabetical':
                $query->orderBy('products.name')
                    ->orderBy('products.id');
                return;

            case 'relevance':
                if ($search !== '') {
                    $query->orderByDesc('relevance_score')
                        ->orderByDesc('products.id');
                    return;
                }

                $query->orderByDesc('products.created_at')
                    ->orderByDesc('products.id');
                return;

            case 'newest':
            default:
                $query->orderByDesc('products.created_at')
                    ->orderByDesc('products.id');
                return;
        }
    }

    /**
     * @param Collection<int, Product> $items
     * @return array<int, array<string, mixed>>
     */
    private function mapItems(Collection $items): array
    {
        return $items->map(function (Product $offer): array {
            $type = strtolower((string) $offer->item_type);
            $availability = $type === OfferType::PRODUCT->value
                ? ((int) $offer->stock > 0 ? 'in_stock' : 'out_of_stock')
                : 'bookable';

            return [
                'id' => (int) $offer->id,
                'type' => $type,
                'name' => (string) $offer->name,
                'price' => (float) $offer->price,
                'status' => $offer->is_active ? 'active' : 'inactive',
                'availability' => $availability,
                'thumbnailUrl' => $offer->image_url,
                'categoryName' => $offer->category?->name,
                'sku' => $type === OfferType::PRODUCT->value ? (string) ($offer->sku ?? '') : null,
                'serviceCode' => $type === OfferType::SERVICE->value
                    ? (string) ($offer->number ?? $offer->sku ?? '')
                    : null,
                'tags' => is_array($offer->tags) ? $offer->tags : [],
            ];
        })->values()->all();
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function filterOptions(User $accountOwner): array
    {
        $categories = ProductCategory::query()
            ->forAccount($accountOwner->id)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (ProductCategory $category): array => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
            ])
            ->values()
            ->all();

        $tags = Product::query()
            ->where('user_id', $accountOwner->id)
            ->pluck('tags')
            ->filter(fn ($value) => is_array($value))
            ->flatMap(fn (array $value) => $value)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->map(fn (string $tag): array => [
                'value' => $tag,
                'label' => $tag,
            ])
            ->all();

        return [
            'categories' => $categories,
            'tags' => $tags,
        ];
    }
}
