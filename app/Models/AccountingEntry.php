<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingEntry extends Model
{
    use HasFactory;

    public const DIRECTION_DEBIT = 'debit';

    public const DIRECTION_CREDIT = 'credit';

    public const REVIEW_STATUS_UNREVIEWED = 'unreviewed';

    public const REVIEW_STATUS_REVIEWED = 'reviewed';

    public const REVIEW_STATUS_RECONCILED = 'reconciled';

    protected $fillable = [
        'user_id',
        'batch_id',
        'account_id',
        'direction',
        'amount',
        'tax_amount',
        'currency_code',
        'entry_date',
        'description',
        'review_status',
        'reconciliation_status',
        'locked_at',
        'meta',
    ];

    protected $casts = [
        'batch_id' => 'integer',
        'account_id' => 'integer',
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'entry_date' => 'date',
        'locked_at' => 'datetime',
        'meta' => 'array',
    ];

    protected $appends = [
        'signed_amount',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AccountingEntryBatch::class, 'batch_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'account_id');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function getSignedAmountAttribute(): float
    {
        $amount = (float) $this->amount;

        return $this->direction === self::DIRECTION_CREDIT ? -1 * $amount : $amount;
    }
}
