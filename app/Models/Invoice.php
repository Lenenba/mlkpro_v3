<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    /** @use HasFactory<\Database\Factories\InvoiceFactory> */
    use HasFactory;

    protected $fillable = ['work_id', 'status', 'total'];

    /**
     * Get the work that owns the invoice.
     */
    public function work()
    {
        return $this->belongsTo(Work::class);
    }

    /**
     * Get the customer for the invoice.
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the user that owns the invoice.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /* Scope a query to filter products based on given criteria.
    *
    * @param \Illuminate\Database\Eloquent\Builder $query
    * @param array $filters
    * @return \Illuminate\Database\Eloquent\Builder
    */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query->when(
            $filters['name'] ?? null,
            fn($query, $name) => $query->where('number', 'like', '%' . $name . '%')
        );
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
}
