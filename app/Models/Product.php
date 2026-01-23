<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\GeneratesSequentialNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory, GeneratesSequentialNumber;

    public const ITEM_TYPE_PRODUCT = 'product';
    public const ITEM_TYPE_SERVICE = 'service';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'description',
        'category_id',
        'stock',
        'minimum_stock',
        'price',
        'image',
        'sku',
        'barcode',
        'unit',
        'supplier_name',
        'supplier_email',
        'cost_price',
        'margin_percent',
        'tax_rate',
        'is_active',
        'user_id', // Ajout pour permettre une meilleure gestion multi-utilisateurs
        'item_type',
        'tracking_type',
        'stripe_product_id',
        'stripe_price_id',
        'stripe_price_account_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<string>
     */
    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'stock' => 'integer',
        'minimum_stock' => 'integer',
        'price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'margin_percent' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'image_url',
        'stock_available',
        'stock_reserved',
        'stock_damaged',
        'warehouse_count',
        'stock_value',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $product) {
            $itemType = $product->item_type ?? self::ITEM_TYPE_PRODUCT;
            $prefix = $itemType === self::ITEM_TYPE_SERVICE ? 'S' : 'P';
            $product->number = self::generateNumber($product->user_id, $prefix);
        });
    }
    /**
     * Get the category of the product.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /**
     * Get the user who owns the product.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the works that use the product.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function works(): BelongsToMany
    {
        return $this->belongsToMany(Work::class, 'product_works')
            ->withPivot(['quantity', 'price', 'description', 'total']);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function serviceMaterials(): HasMany
    {
        return $this->hasMany(ServiceMaterial::class, 'service_id')->orderBy('sort_order');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(ProductStockMovement::class)->latest();
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(ProductInventory::class);
    }

    public function lots(): HasMany
    {
        return $this->hasMany(ProductLot::class);
    }

    /**
     * Relation : Un devis peut avoir plusieurs produits attachés.
     */
    public function quotes()
    {
        return $this->belongsToMany(Quote::class, 'quote_products')
            ->withPivot(['quantity', 'price', 'description', 'total'])
            ->withTimestamps();
    }
    /**
     * Scope a query to order products by the most recent.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeMostRecent(Builder $query): Builder
    {
        return $query->orderByDesc('created_at');
    }

    /**
     * Scope a query to find products with low stock.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereColumn('stock', '<=', 'minimum_stock');
    }

    public function getImageUrlAttribute(): ?string
    {
        $path = $this->image;

        if (!$path) {
            $path = 'products/product.jpg';
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Storage::url($path);
    }

    /**
     * Get a flag indicating if the product is low on stock.
     *
     * @return bool
     */
    public function getIsLowStockAttribute(): bool
    {
        return $this->stock <= $this->minimum_stock;
    }

    /**
     * Get a human-readable stock status.
     *
     * @return string
     */
    public function getStockStatusAttribute(): string
    {
        if ($this->is_low_stock) {
            return 'Low Stock';
        }

        return 'In Stock';
    }

    /**
     * Get the available stock across warehouses.
     *
     * @return int
     */
    public function getStockAvailableAttribute(): int
    {
        $onHand = $this->getAttribute('on_hand_total');
        $reserved = $this->getAttribute('reserved_total');
        if ($onHand !== null || $reserved !== null) {
            return max(0, (int) $onHand - (int) $reserved);
        }

        if ($this->relationLoaded('inventories')) {
            return (int) $this->inventories->sum(fn (ProductInventory $inventory) => $inventory->available);
        }

        return (int) $this->stock;
    }

    /**
     * Get the reserved stock across warehouses.
     *
     * @return int
     */
    public function getStockReservedAttribute(): int
    {
        $reserved = $this->getAttribute('reserved_total');
        if ($reserved !== null) {
            return (int) $reserved;
        }

        if ($this->relationLoaded('inventories')) {
            return (int) $this->inventories->sum('reserved');
        }

        return 0;
    }

    /**
     * Get the damaged stock across warehouses.
     *
     * @return int
     */
    public function getStockDamagedAttribute(): int
    {
        $damaged = $this->getAttribute('damaged_total');
        if ($damaged !== null) {
            return (int) $damaged;
        }

        if ($this->relationLoaded('inventories')) {
            return (int) $this->inventories->sum('damaged');
        }

        return 0;
    }

    /**
     * Get the warehouse count for this product.
     *
     * @return int
     */
    public function getWarehouseCountAttribute(): int
    {
        $count = $this->getAttributeFromArray('warehouse_count');
        if ($count !== null) {
            return (int) $count;
        }

        if ($this->relationLoaded('inventories')) {
            return $this->inventories->count();
        }

        return 0;
    }

    /**
     * Get the inventory value for available stock.
     *
     * @return float
     */
    public function getStockValueAttribute(): float
    {
        $cost = (float) ($this->cost_price ?: $this->price);
        return round($this->stock_available * $cost, 2);
    }

    /**
     * Scope a query to filter products based on given criteria.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                $filters['name'] ?? null,
                fn(Builder $query, $name) => $query->where(function (Builder $query) use ($name) {
                    $query->where('name', 'like', '%' . $name . '%')
                        ->orWhere('description', 'like', '%' . $name . '%');
                })
            )
            ->when(
                $filters['supplier_name'] ?? null,
                fn(Builder $query, $supplier) => $query->where('supplier_name', 'like', '%' . $supplier . '%')
            )
            ->when(
                $filters['category_id'] ?? null,
                fn(Builder $query, $categoryId) => $query->where('category_id', $categoryId)
            )
            ->when(
                $filters['stock_status'] ?? null,
                function (Builder $query, $status) {
                    if ($status === 'in') {
                        $query->where('stock', '>', 0)
                            ->whereColumn('stock', '>', 'minimum_stock');
                    } elseif ($status === 'low') {
                        $query->whereColumn('stock', '<=', 'minimum_stock')
                            ->where('stock', '>', 0);
                    } elseif ($status === 'out') {
                        $query->where('stock', '<=', 0);
                    }
                }
            )
            ->when(
                $filters['category_ids'] ?? null,
                fn(Builder $query, $categoryIds) => $query->whereIn('category_id', (array) $categoryIds)
            )
            ->when(
                $filters['tracking_type'] ?? null,
                function (Builder $query, $trackingType) {
                    if ($trackingType === 'none') {
                        $query->where(function (Builder $query) {
                            $query->whereNull('tracking_type')
                                ->orWhere('tracking_type', 'none');
                        });
                    } else {
                        $query->where('tracking_type', $trackingType);
                    }
                }
            )
            ->when(
                $filters['warehouse_id'] ?? null,
                fn(Builder $query, $warehouseId) => $query->whereHas('inventories', function (Builder $inventoryQuery) use ($warehouseId) {
                    $inventoryQuery->where('warehouse_id', $warehouseId);
                })
            )
            ->when(
                $filters['alert'] ?? null,
                function (Builder $query, $alert) {
                    if ($alert === 'damaged') {
                        $query->whereHas('inventories', function (Builder $inventoryQuery) {
                            $inventoryQuery->where('damaged', '>', 0);
                        });
                    } elseif ($alert === 'reserved') {
                        $query->whereHas('inventories', function (Builder $inventoryQuery) {
                            $inventoryQuery->where('reserved', '>', 0);
                        });
                    } elseif ($alert === 'expiring') {
                        $today = now()->toDateString();
                        $soon = now()->addDays(30)->toDateString();
                        $query->whereHas('lots', function (Builder $lotQuery) use ($today, $soon) {
                            $lotQuery->whereNotNull('expires_at')
                                ->whereDate('expires_at', '>=', $today)
                                ->whereDate('expires_at', '<=', $soon);
                        });
                    } elseif ($alert === 'expired') {
                        $today = now()->toDateString();
                        $query->whereHas('lots', function (Builder $lotQuery) use ($today) {
                            $lotQuery->whereNotNull('expires_at')
                                ->whereDate('expires_at', '<', $today);
                        });
                    } elseif ($alert === 'reorder') {
                        $query->whereColumn('stock', '<=', 'minimum_stock');
                    }
                }
            )
            ->when(
                $filters['price_min'] ?? null,
                fn(Builder $query, $priceMin) => $query->where('price', '>=', $priceMin)
            )
            ->when(
                $filters['price_max'] ?? null,
                fn(Builder $query, $priceMax) => $query->where('price', '<=', $priceMax)
            )
            ->when(
                $filters['stock_min'] ?? null,
                fn(Builder $query, $stockMin) => $query->where('stock', '>=', $stockMin)
            )
            ->when(
                $filters['stock_max'] ?? null,
                fn(Builder $query, $stockMax) => $query->where('stock', '<=', $stockMax)
            )
            ->when(
                array_key_exists('has_image', $filters) && $filters['has_image'] !== '',
                function (Builder $query) use ($filters) {
                    $hasImage = filter_var($filters['has_image'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($hasImage === null) {
                        return;
                    }
                    $hasImage
                        ? $query->whereNotNull('image')
                        : $query->whereNull('image');
                }
            )
            ->when(
                array_key_exists('has_barcode', $filters) && $filters['has_barcode'] !== '',
                function (Builder $query) use ($filters) {
                    $hasBarcode = filter_var($filters['has_barcode'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($hasBarcode === null) {
                        return;
                    }
                    $hasBarcode
                        ? $query->whereNotNull('barcode')
                        : $query->whereNull('barcode');
                }
            )
            ->when(
                $filters['created_from'] ?? null,
                fn(Builder $query, $createdFrom) => $query->whereDate('created_at', '>=', $createdFrom)
            )
            ->when(
                $filters['created_to'] ?? null,
                fn(Builder $query, $createdTo) => $query->whereDate('created_at', '<=', $createdTo)
            )
            ->when(
                $filters['status'] ?? null,
                function (Builder $query, $status) {
                    if ($status === 'active') {
                        $query->where('is_active', true);
                    } elseif ($status === 'archived') {
                        $query->where('is_active', false);
                    }
                }
            );
    }

    public function scopeInStock($query)
    {
        return $query->where('stock', '>', 0);
    }

    /**
     * Scope a query to only include customers of a given user.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeProducts(Builder $query): Builder
    {
        return $query->where('item_type', self::ITEM_TYPE_PRODUCT);
    }

    public function scopeServices(Builder $query): Builder
    {
        return $query->where('item_type', self::ITEM_TYPE_SERVICE);
    }

    /**
     * Scope a query to filter products by a specific work.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $workId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForWork(Builder $query, int $workId): Builder
    {
        return $query->whereHas('works', function (Builder $q) use ($workId) {
            $q->where('work_id', $workId);
        });
    }

    /**
     * Apply stock range filter.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $stockRange
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function applyStockFilter(Builder $query, string $stockRange): Builder
    {
        if ($stockRange === '0-10') {
            return $query->whereBetween('stock', [0, 10]);
        }

        if ($stockRange === '10-100') {
            return $query->whereBetween('stock', [10, 100]);
        }

        if ($stockRange === '>100') {
            return $query->where('stock', '>', 100);
        }

        return $query;
    }

    // // Helpers
    // public function getFormattedPrice(): string
    // {
    //     return '$' . number_format($this->price, 2);
    // }

    // public function isAvailable(): bool
    // {
    //     return $this->stock_quantity > 0;
    // }
}
