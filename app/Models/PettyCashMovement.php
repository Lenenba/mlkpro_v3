<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PettyCashMovement extends Model
{
    public const TYPE_FUNDING = 'funding';

    public const TYPE_EXPENSE = 'expense';

    public const TYPE_ADVANCE = 'advance';

    public const TYPE_REIMBURSEMENT = 'reimbursement';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_VOIDED = 'voided';

    public const TYPES = [
        self::TYPE_FUNDING,
        self::TYPE_EXPENSE,
        self::TYPE_ADVANCE,
        self::TYPE_REIMBURSEMENT,
        self::TYPE_ADJUSTMENT,
    ];

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_POSTED,
        self::STATUS_VOIDED,
    ];

    protected $fillable = [
        'user_id',
        'petty_cash_account_id',
        'expense_id',
        'team_member_id',
        'created_by_user_id',
        'responsible_user_id',
        'voided_by_user_id',
        'type',
        'status',
        'amount',
        'currency_code',
        'movement_date',
        'note',
        'requires_receipt',
        'receipt_attached',
        'posted_at',
        'voided_at',
        'void_reason',
        'meta',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'movement_date' => 'date',
        'requires_receipt' => 'boolean',
        'receipt_attached' => 'boolean',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function teamMember(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PettyCashAttachment::class);
    }
}
