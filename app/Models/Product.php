<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory;

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
        'user_id', // Ajout pour permettre une meilleure gestion multi-utilisateurs
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
        return $this->belongsToMany(Work::class, 'product_works')->withPivot('quantity_used', 'unit');
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
     * Scope a query to filter products based on given criteria.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query->when(
            $filters['name'] ?? null,
            fn($query, $name) => $query->where('name', 'like', '%' . $name . '%')
                ->orWhere('description', 'like', '%' . $name . '%')
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
