<?php

namespace App\Models;

use App\Enums\CurrencyCode;
use App\Services\FinanceApprovalService;
use App\Traits\GeneratesSequentialNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    /** @use HasFactory<\Database\Factories\InvoiceFactory> */
    use GeneratesSequentialNumber, HasFactory;

    public const STATUSES = [
        'draft',
        'sent',
        'awaiting_acceptance',
        'accepted',
        'rejected',
        'partial',
        'paid',
        'overdue',
        'void',
    ];

    public const APPROVAL_STATUSES = FinanceApprovalService::APPROVAL_STATUSES;

    protected $fillable = [
        'work_id',
        'customer_id',
        'reservation_queue_item_id',
        'user_id',
        'created_by_user_id',
        'approved_by_user_id',
        'rejected_by_user_id',
        'processed_by_user_id',
        'number',
        'status',
        'approval_status',
        'current_approver_role_key',
        'current_approval_level',
        'subtotal',
        'tax_total',
        'total',
        'currency_code',
        'source',
        'billing_snapshot',
        'customer_snapshot',
        'receipt_delivery',
        'receipt_delivery_status',
        'receipt_delivery_queued_at',
        'receipt_delivery_started_at',
        'receipt_delivery_claim_token',
        'receipt_delivery_attempts',
        'receipt_delivery_last_error',
        'receipt_delivered_at',
        'approved_at',
        'rejected_at',
        'processed_at',
        'approval_meta',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total' => 'decimal:2',
        'currency_code' => 'string',
        'source' => 'string',
        'billing_snapshot' => 'array',
        'customer_snapshot' => 'array',
        'receipt_delivery' => 'string',
        'receipt_delivery_status' => 'string',
        'receipt_delivery_queued_at' => 'datetime',
        'receipt_delivery_started_at' => 'datetime',
        'receipt_delivery_claim_token' => 'string',
        'receipt_delivery_attempts' => 'integer',
        'receipt_delivery_last_error' => 'string',
        'receipt_delivered_at' => 'datetime',
        'current_approval_level' => 'integer',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'processed_at' => 'datetime',
        'approval_meta' => 'array',
    ];

    protected $appends = [
        'amount_paid',
        'balance_due',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($invoice) {
            if (! $invoice->number && $invoice->user_id) {
                $invoice->number = self::generateNumber($invoice->user_id, 'I');
            }

            if (! $invoice->currency_code) {
                $owner = $invoice->user_id ? User::query()->find($invoice->user_id) : null;
                $invoice->currency_code = $owner?->businessCurrencyCode() ?? CurrencyCode::default()->value;
            }

            if (! $invoice->approval_status) {
                $invoice->approval_status = FinanceApprovalService::APPROVAL_STATUS_DRAFT;
            }
        });
    }

    /**
     * Get the work that owns the invoice.
     */
    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    /**
     * Get the customer for the invoice.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function reservationQueueItem(): BelongsTo
    {
        return $this->belongsTo(ReservationQueueItem::class);
    }

    /**
     * Get the user that owns the invoice.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /* Scope a query to filter products based on given criteria.
    *
    * @param \Illuminate\Database\Eloquent\Builder $query
    * @param array $filters
    * @return \Illuminate\Database\Eloquent\Builder
    */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                $filters['search'] ?? null,
                function (Builder $query, $search) {
                    $query->where(function (Builder $sub) use ($search) {
                        $sub->where('number', 'like', '%'.$search.'%')
                            ->orWhereHas('customer', function (Builder $customerQuery) use ($search) {
                                $customerQuery->where('company_name', 'like', '%'.$search.'%')
                                    ->orWhere('first_name', 'like', '%'.$search.'%')
                                    ->orWhere('last_name', 'like', '%'.$search.'%')
                                    ->orWhere('email', 'like', '%'.$search.'%');
                            });
                    });
                }
            )
            ->when(
                $filters['status'] ?? null,
                fn (Builder $query, $status) => $query->where('status', $status)
            )
            ->when(
                $filters['approval_status'] ?? null,
                fn (Builder $query, $status) => $query->where('approval_status', $status)
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
                fn (Builder $query, $min) => $query->where('total', '>=', $min)
            )
            ->when(
                $filters['total_max'] ?? null,
                fn (Builder $query, $max) => $query->where('total', '<=', $max)
            )
            ->when(
                $filters['created_from'] ?? null,
                fn (Builder $query, $from) => $query->whereDate('created_at', '>=', $from)
            )
            ->when(
                $filters['created_to'] ?? null,
                fn (Builder $query, $to) => $query->whereDate('created_at', '<=', $to)
            );
    }

    /**
     * Scope a query to only include customers of a given user.
     */
    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function getAmountPaidAttribute(): float
    {
        if (array_key_exists('payments_sum_amount', $this->attributes)) {
            return round((float) $this->attributes['payments_sum_amount'], 2);
        }

        if ($this->relationLoaded('payments')) {
            return round((float) $this->payments
                ->whereIn('status', Payment::settledStatuses())
                ->sum('amount'), 2);
        }

        return round((float) $this->payments()
            ->whereIn('status', Payment::settledStatuses())
            ->sum('amount'), 2);
    }

    public function getBalanceDueAttribute(): float
    {
        $total = round((float) $this->total, 2);
        $paid = round($this->amount_paid, 2);

        return max(0, round($total - $paid, 2));
    }

    public function refreshPaymentStatus(): void
    {
        if ($this->status === 'void') {
            return;
        }

        $total = round((float) $this->total, 2);
        $paid = round($this->amount_paid, 2);

        if ($total <= 0 && $paid <= 0) {
            return;
        }

        if ($paid >= $total && $total > 0) {
            $this->status = 'paid';
        } elseif ($paid > 0) {
            $this->status = 'partial';
        }

        $this->save();
    }
}
