<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PettyCashClosure extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_REOPENED = 'reopened';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_REVIEW,
        self::STATUS_CLOSED,
        self::STATUS_REOPENED,
    ];

    protected $fillable = [
        'user_id',
        'petty_cash_account_id',
        'period_start',
        'period_end',
        'expected_balance',
        'counted_balance',
        'difference',
        'status',
        'reviewed_by_user_id',
        'closed_by_user_id',
        'closed_at',
        'reopened_by_user_id',
        'reopened_at',
        'comment',
        'meta',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'expected_balance' => 'decimal:2',
        'counted_balance' => 'decimal:2',
        'difference' => 'decimal:2',
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
        'meta' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(PettyCashAccount::class, 'petty_cash_account_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by_user_id');
    }
}
