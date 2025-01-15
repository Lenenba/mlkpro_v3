<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\GeneratesSequentialNumber;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Quote extends Model
{
    use HasFactory, GeneratesSequentialNumber;

    protected $fillable = [
        'user_id',
        'job_title',
        'status',
        'number',
        'customer_id',
        'property_id',
        'total',
        'initial_deposit',
        'is_fixed',
        'notes',
    ];

    protected static function boot()
    {
        parent::boot();

        // Auto-generate the quote number before creating
        static::creating(function ($quote) {
            $quote->number = self::generateNumber($quote->user_id, 'Q');
        });
    }

    /**
     * Relation : Un devis appartient à un client.
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Relation : Un devis appartient à une propriété.
     */
    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * Relation : Un devis peut avoir plusieurs produits attachés.
     */
    public function products()
    {
        return $this->belongsToMany(Product::class, 'quote_products')
            ->withPivot(['quantity', 'price', 'description', 'total'])
            ->withTimestamps();

    }

    /**
     * Relation : Un devis peut avoir plusieurs taxes attachées.
     */
    public function taxes()
    {
        return $this->hasMany(QuoteTax::class);
    }
}
