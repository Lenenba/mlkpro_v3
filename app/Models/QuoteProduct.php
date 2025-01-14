<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuoteProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'quote_id',
        'product_id',
        'quantity',
        'price',
        'description',
        'total',
    ];

    /**
     * Relation : Ce produit appartient à un devis.
     */
    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * Relation : Ce produit peut être lié à un produit existant.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Calculer le total (quantité * prix).
     */
    public function calculateTotal()
    {
        return $this->quantity * $this->price;
    }
}
