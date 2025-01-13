<?php

namespace App\Models;

use App\Models\Work;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProductWork extends Model
{
    /** @use HasFactory<\Database\Factories\WorkFactory> */
    use HasFactory;

    protected $fillable = ['work_id', 'product_id', 'quantity_used', 'unit'];

    /**
     * Get the work that owns the product work.
     */
    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    /**
     * Get the product that owns the product work.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
