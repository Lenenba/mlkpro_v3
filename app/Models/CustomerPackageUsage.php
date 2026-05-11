<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPackageUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_package_id',
        'user_id',
        'customer_id',
        'reservation_id',
        'work_id',
        'invoice_id',
        'product_id',
        'created_by_user_id',
        'quantity',
        'used_at',
        'note',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'used_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function customerPackage(): BelongsTo
    {
        return $this->belongsTo(CustomerPackage::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('user_id', $accountId);
    }
}
