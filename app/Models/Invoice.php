<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\GeneratesSequentialNumber;

class Invoice extends Model
{
    /** @use HasFactory<\Database\Factories\InvoiceFactory> */
    use HasFactory, GeneratesSequentialNumber;

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

    protected $fillable = [
        'work_id',
        'customer_id',
        'user_id',
        'number',
        'status',
        'total',
    ];

    protected $casts = [
        'total' => 'decimal:2',
    ];

    protected $appends = [
        'amount_paid',
        'balance_due',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($invoice) {
            if (!$invoice->number && $invoice->user_id) {
                $invoice->number = self::generateNumber($invoice->user_id, 'I');
            }
        });
    }

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
                        $sub->where('number', 'like', '%' . $search . '%')
                            ->orWhereHas('customer', function (Builder $customerQuery) use ($search) {
                                $customerQuery->where('company_name', 'like', '%' . $search . '%')
                                    ->orWhere('first_name', 'like', '%' . $search . '%')
                                    ->orWhere('last_name', 'like', '%' . $search . '%')
                                    ->orWhere('email', 'like', '%' . $search . '%');
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
                $filters['created_from'] ?? null,
                fn(Builder $query, $from) => $query->whereDate('created_at', '>=', $from)
            )
            ->when(
                $filters['created_to'] ?? null,
                fn(Builder $query, $to) => $query->whereDate('created_at', '<=', $to)
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

    public function getAmountPaidAttribute(): float
    {
        if (array_key_exists('payments_sum_amount', $this->attributes)) {
            return (float) $this->attributes['payments_sum_amount'];
        }

        if ($this->relationLoaded('payments')) {
            return (float) $this->payments
                ->whereIn('status', Payment::settledStatuses())
                ->sum('amount');
        }

        return (float) $this->payments()
            ->whereIn('status', Payment::settledStatuses())
            ->sum('amount');
    }

    public function getBalanceDueAttribute(): float
    {
        $total = (float) $this->total;
        $paid = $this->amount_paid;

        return max(0, round($total - $paid, 2));
    }

    public function refreshPaymentStatus(): void
    {
        if ($this->status === 'void') {
            return;
        }

        $total = (float) $this->total;
        $paid = $this->amount_paid;

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
