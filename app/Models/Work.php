<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;

class Work extends Model
{
    /** @use HasFactory<\Database\Factories\WorkFactory> */
    use HasFactory;


    protected $fillable = ['user_id', 'company_id', 'description', 'type', 'work_date', 'time_spent', 'is_completed', 'cost', 'location','category'];



    protected static function boot()
    {
        parent::boot();

         // Automatically generate the quote number before creating
         static::creating(function ($work) {
            // Ensure `customer_id` is set before generating the number
            if (!$work->customer_id) {
                throw new \Exception('Customer ID is required to generate a work number.');
            }

            // Generate the number scoped by customer and user
            $work->number = self::generateScopedNumber($work->customer_id, 'Q');
        });

    }

    /**
     * Get the user that owns the work.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the invoice that owns the work.
     */
    public function invoice()
    {
        return $this->hasOne(Invoice::class);
    }

    /**
     * Get the company that owns the work.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /**
     * Get the products used in the work.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_works')->withPivot('quantity_used', 'unit');
    }

    /**
     * Get the ratings for the work.
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(WorkRating::class);
    }

    /**
     * Scope a query to order products by the most recent.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCompleted($query): Builder
    {
        return $query->where('is_completed', true);
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
     * Scope a query to filter by one or more customer IDs.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array|int $customerIds
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByCustomer($query, $customerIds)
    {
        // Vérifier si un seul ID est passé et le convertir en tableau
        if (!is_array($customerIds)) {
            $customerIds = [$customerIds];
        }

        return $query->whereIn('customer_id', $customerIds);
    }

    /**
     * Scope a query to filter by one or more customer IDs.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array|int $customerIds
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
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
     * Scope a query to order products by the most recent.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecent($query, $days)
    {
        return $query->where('work_date', '>=', now()->subDays($days));
    }

    /**
     * Get the duration of the work in hours.
     *
     * @return float
     */
    public function getDurationInHours(): float
    {
        return round($this->time_spent / 60, 2);
    }

    /**
     * Get the formatted date of the work.
     *
     * @return string
     */
    public function getFormattedDate(): string
    {
        return $this->work_date->format('d M Y, H:i');
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
            fn($query, $name) => $query->where('type', 'like', '%' . $name . '%')
        )->when(
            $filters['status'] ?? null,
            fn($query, $status) => $query->where('is_completed', $status  )
        )->when(
            $filters['month'] ?? null,
            fn($query, $month) => $query->whereMonth('work_date', $month))
        ;
    }

}
