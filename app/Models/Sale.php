<?php

namespace App\Models;

use App\Traits\GeneratesSequentialNumber;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use HasFactory, GeneratesSequentialNumber;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELED = 'canceled';
    public const FULFILLMENT_PENDING = 'pending';
    public const FULFILLMENT_PREPARING = 'preparing';
    public const FULFILLMENT_OUT_FOR_DELIVERY = 'out_for_delivery';
    public const FULFILLMENT_READY_FOR_PICKUP = 'ready_for_pickup';
    public const FULFILLMENT_COMPLETED = 'completed';

    protected $fillable = [
        'user_id',
        'created_by_user_id',
        'customer_id',
        'status',
        'number',
        'subtotal',
        'tax_total',
        'discount_rate',
        'discount_total',
        'total',
        'delivery_fee',
        'fulfillment_method',
        'fulfillment_status',
        'delivery_address',
        'delivery_notes',
        'pickup_notes',
        'scheduled_for',
        'pickup_code',
        'pickup_confirmed_at',
        'pickup_confirmed_by_user_id',
        'customer_notes',
        'substitution_allowed',
        'substitution_notes',
        'source',
        'notes',
        'paid_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'discount_rate' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'total' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'paid_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'pickup_confirmed_at' => 'datetime',
        'substitution_allowed' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $sale) {
            if (!$sale->number) {
                $sale->number = self::generateNumber($sale->user_id, 'SO');
            }
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function pickupConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pickup_confirmed_by_user_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
