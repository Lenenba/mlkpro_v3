<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerPackage extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const RECURRENCE_ACTIVE = 'active';

    public const RECURRENCE_PAYMENT_DUE = 'payment_due';

    public const RECURRENCE_SUSPENDED = 'suspended';

    public const RECURRENCE_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'customer_id',
        'offer_package_id',
        'quote_id',
        'invoice_id',
        'invoice_item_id',
        'status',
        'starts_at',
        'expires_at',
        'consumed_at',
        'cancelled_at',
        'initial_quantity',
        'consumed_quantity',
        'remaining_quantity',
        'unit_type',
        'price_paid',
        'currency_code',
        'is_recurring',
        'recurrence_frequency',
        'recurrence_status',
        'current_period_starts_at',
        'current_period_ends_at',
        'next_renewal_at',
        'renewal_count',
        'renewed_from_customer_package_id',
        'source_details',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'expires_at' => 'date',
            'consumed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'initial_quantity' => 'integer',
            'consumed_quantity' => 'integer',
            'remaining_quantity' => 'integer',
            'price_paid' => 'decimal:2',
            'is_recurring' => 'boolean',
            'current_period_starts_at' => 'date',
            'current_period_ends_at' => 'date',
            'next_renewal_at' => 'date',
            'renewal_count' => 'integer',
            'source_details' => 'array',
            'metadata' => 'array',
        ];
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_ACTIVE,
            self::STATUS_CONSUMED,
            self::STATUS_EXPIRED,
            self::STATUS_CANCELLED,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function offerPackage(): BelongsTo
    {
        return $this->belongsTo(OfferPackage::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    public function renewedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewed_from_customer_package_id');
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(self::class, 'renewed_from_customer_package_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(CustomerPackageUsage::class)
            ->active()
            ->latest('used_at')
            ->latest('id');
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('user_id', $accountId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeRecurring(Builder $query): Builder
    {
        return $query->where('is_recurring', true);
    }
}
