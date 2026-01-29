<?php

namespace App\Models;

use App\Traits\GeneratesSequentialNumber;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
    public const FULFILLMENT_CONFIRMED = 'confirmed';

    protected $fillable = [
        'user_id',
        'created_by_user_id',
        'customer_id',
        'status',
        'payment_provider',
        'number',
        'subtotal',
        'tax_total',
        'discount_rate',
        'discount_total',
        'total',
        'deposit_amount',
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
        'delivery_confirmed_at',
        'delivery_confirmed_by_user_id',
        'delivery_proof',
        'customer_notes',
        'substitution_allowed',
        'substitution_notes',
        'source',
        'notes',
        'paid_at',
        'stripe_payment_intent_id',
        'stripe_checkout_session_id',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'discount_rate' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'total' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'paid_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'pickup_confirmed_at' => 'datetime',
        'delivery_confirmed_at' => 'datetime',
        'substitution_allowed' => 'boolean',
    ];

    protected $appends = [
        'delivery_proof_url',
        'amount_paid',
        'balance_due',
        'payment_status',
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

    public function orderReview(): HasOne
    {
        return $this->hasOne(OrderReview::class);
    }

    public function productReviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
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

    public function deliveryConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivery_confirmed_by_user_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getDeliveryProofUrlAttribute(): ?string
    {
        if (!$this->delivery_proof) {
            return null;
        }
        if (str_starts_with($this->delivery_proof, 'http://') || str_starts_with($this->delivery_proof, 'https://')) {
            return $this->delivery_proof;
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->url($this->delivery_proof);
    }

    public function getAmountPaidAttribute(): float
    {
        if (array_key_exists('payments_sum_amount', $this->attributes)) {
            return (float) $this->attributes['payments_sum_amount'];
        }

        if ($this->relationLoaded('payments')) {
            return (float) $this->payments->where('status', 'completed')->sum('amount');
        }

        return (float) $this->payments()
            ->where('status', 'completed')
            ->sum('amount');
    }

    public function getBalanceDueAttribute(): float
    {
        $total = (float) $this->total;
        $paid = $this->amount_paid;

        return max(0, round($total - $paid, 2));
    }

    public function getPaymentStatusAttribute(): string
    {
        if ($this->status === self::STATUS_PAID) {
            return self::STATUS_PAID;
        }
        if ($this->status === self::STATUS_CANCELED) {
            return self::STATUS_CANCELED;
        }

        $total = (float) $this->total;
        $paid = $this->amount_paid;

        if ($total > 0 && $paid >= $total) {
            return self::STATUS_PAID;
        }

        if ($paid > 0) {
            return 'partial';
        }

        $deposit = (float) ($this->deposit_amount ?? 0);
        if ($deposit > 0) {
            return 'deposit_required';
        }

        return 'unpaid';
    }
}
