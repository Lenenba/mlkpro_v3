<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductInventory extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'warehouse_id',
        'on_hand',
        'reserved',
        'damaged',
        'minimum_stock',
        'reorder_point',
        'bin_location',
    ];

    protected $casts = [
        'on_hand' => 'integer',
        'reserved' => 'integer',
        'damaged' => 'integer',
        'minimum_stock' => 'integer',
        'reorder_point' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function getAvailableAttribute(): int
    {
        return max(0, (int) $this->on_hand - (int) $this->reserved);
    }

    public function getIsLowStockAttribute(): bool
    {
        return $this->available <= $this->minimum_stock;
    }
}
