<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use App\Traits\GeneratesSequentialNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Quote extends Model
{
    use HasFactory, GeneratesSequentialNumber, Notifiable;

    protected $fillable = [
        'user_id',
        'job_title',
        'status',
        'number',
        'customer_id',
        'property_id',
        'total',
        'subtotal',
        'initial_deposit',
        'is_fixed',
        'notes',
        'messages',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'initial_deposit' => 'decimal:2',
        'is_fixed' => 'boolean',
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

    public function works()
    {
        return $this->hasMany(Work::class);
    }

    /**
     * Scope a query to only include quotes of a given user.
     */
    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to filter quotes based on given criteria.
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                $filters['search'] ?? null,
                function (Builder $query, $search) {
                    $query->where(function (Builder $sub) use ($search) {
                        $sub->where('number', 'like', '%' . $search . '%')
                            ->orWhere('job_title', 'like', '%' . $search . '%')
                            ->orWhere('notes', 'like', '%' . $search . '%')
                            ->orWhere('messages', 'like', '%' . $search . '%')
                            ->orWhereHas('customer', function (Builder $customerQuery) use ($search) {
                                $customerQuery->where('company_name', 'like', '%' . $search . '%')
                                    ->orWhere('first_name', 'like', '%' . $search . '%')
                                    ->orWhere('last_name', 'like', '%' . $search . '%')
                                    ->orWhere('email', 'like', '%' . $search . '%')
                                    ->orWhere('phone', 'like', '%' . $search . '%');
                            });
                    });
                }
            )
            ->when(
                $filters['status'] ?? null,
                fn(Builder $query, $status) => $query->where('status', $status)
            )
            ->when(
                $filters['customer_id'] ?? null,
                function (Builder $query, $customerIds) {
                    $ids = is_array($customerIds) ? $customerIds : [$customerIds];
                    $query->whereIn('customer_id', $ids);
                }
            )
            ->when(
                $filters['total_min'] ?? null,
                fn(Builder $query, $min) => $query->where('total', '>=', $min)
            )
            ->when(
                $filters['total_max'] ?? null,
                fn(Builder $query, $max) => $query->where('total', '<=', $max)
            )
            ->when(
                array_key_exists('has_deposit', $filters) && $filters['has_deposit'] !== '',
                function (Builder $query) use ($filters) {
                    $hasDeposit = filter_var($filters['has_deposit'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($hasDeposit === null) {
                        return;
                    }
                    $hasDeposit
                        ? $query->where('initial_deposit', '>', 0)
                        : $query->where('initial_deposit', '<=', 0);
                }
            )
            ->when(
                array_key_exists('has_tax', $filters) && $filters['has_tax'] !== '',
                function (Builder $query) use ($filters) {
                    $hasTax = filter_var($filters['has_tax'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($hasTax === null) {
                        return;
                    }
                    $hasTax ? $query->whereHas('taxes') : $query->whereDoesntHave('taxes');
                }
            )
            ->when(
                $filters['created_from'] ?? null,
                fn(Builder $query, $from) => $query->whereDate('created_at', '>=', $from)
            )
            ->when(
                $filters['created_to'] ?? null,
                fn(Builder $query, $to) => $query->whereDate('created_at', '<=', $to)
            );
    }
}
