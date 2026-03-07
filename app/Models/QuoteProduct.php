<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'quote_id',
        'product_id',
        'quantity',
        'price',
        'description',
        'source_details',
        'total',
    ];

    protected $casts = [
        'source_details' => 'array',
    ];

    /**
     * Relation : Ce produit appartient à un devis.
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * Relation : Ce produit peut être lié à un produit existant.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Calculer le total (quantité * prix).
     */
    public function calculateTotal(): float
    {
        return (float) ($this->quantity * $this->price);
    }
}
