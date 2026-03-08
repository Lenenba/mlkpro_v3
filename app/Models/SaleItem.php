<?php

namespace App\Models;

use App\Enums\CurrencyCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'product_id',
        'warehouse_id',
        'lot_id',
        'description',
        'quantity',
        'price',
        'currency_code',
        'total',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price' => 'decimal:2',
        'currency_code' => 'string',
        'total' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $item) {
            if ($item->currency_code) {
                return;
            }

            if ($item->sale_id) {
                $item->currency_code = Sale::query()
                    ->whereKey($item->sale_id)
                    ->value('currency_code') ?: CurrencyCode::default()->value;

                return;
            }

            if ($item->product_id) {
                $item->currency_code = Product::query()
                    ->whereKey($item->product_id)
                    ->value('currency_code') ?: CurrencyCode::default()->value;

                return;
            }

            $item->currency_code = CurrencyCode::default()->value;
        });
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(ProductLot::class, 'lot_id');
    }
}
