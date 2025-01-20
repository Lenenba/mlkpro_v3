<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use App\Traits\GeneratesSequentialNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Customer extends Model
{
    use HasFactory, GeneratesSequentialNumber, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'user_id',
        'number',
        'first_name',
        'last_name',
        'company_name',
        'email',
        'phone',
        'description',
        'logo',
        'refer_by',
        'salutation',
        'billing_same_as_physical',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<string>
     */
    protected $hidden = [
        'user_id', // Optionnel si vous ne souhaitez pas exposer l'ID de l'utilisateur
        'created_at',
        'updated_at',
    ];

    protected static function boot()
    {
        parent::boot();

        // Auto-generate the customer number before creating
        static::creating(function ($customer) {
            $customer->number = self::generateNumber($customer->user_id, 'C');
        });
    }
    /**
     * Get the user that owns the customer.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function properties()
    {
        return $this->hasMany(Property::class);
    }

    public function physicalAddress()
    {
        return $this->properties()->where('type', 'physical')->first();
    }

    public function billingAddress()
    {
        return $this->properties()->where('type', 'billing')->first();
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function quotes()
    {
        return $this->hasMany(Quote::class)->with(['products','property']);
    }
    /**
     * Get the works for the customer.
     */
    public function works(): HasMany
    {
        return $this->hasMany(Work::class, 'customer_id');
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
     * Get the total number of works for the customer.
     *
     * @return int
     */
    public function getTotalWorks(): int
    {
        return $this->works()->count();
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
            fn($query, $name) => $query->where('company_name', 'like', '%' . $name . '%')
        )->when(
            $filters['name'] ?? null,
            fn($query, $name) => $query->where('first_name', 'like', '%' . $name . '%')
        )->when(
            $filters['name'] ?? null,
            fn($query, $name) => $query->where('last_name', 'like', '%' . $name . '%')
        );
    }
}
