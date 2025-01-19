<?php

namespace App\Models;

use Illuminate\Support\Facades\Gate;
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

         // Automatically generate the quote number before creating
         static::creating(function ($quote) {
            // Ensure `customer_id` is set before generating the number
            if (!$quote->customer_id) {
                throw new \Exception('Customer ID is required to generate a quote number.');
            }

            // Generate the number scoped by customer and user
            $quote->number = self::generateScopedNumber($quote->customer_id, 'Q');
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
