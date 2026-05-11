<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfferPackageItem extends Model
{
    protected $fillable = [
        'offer_package_id',
        'product_id',
        'item_type_snapshot',
        'name_snapshot',
        'description_snapshot',
        'quantity',
        'unit_price',
        'included',
        'is_optional',
        'sort_order',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'included' => 'boolean',
            'is_optional' => 'boolean',
            'sort_order' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function offerPackage(): BelongsTo
    {
        return $this->belongsTo(OfferPackage::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
