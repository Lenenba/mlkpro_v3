<?php

namespace App\Models;

use App\Enums\CurrencyCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Expense extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DUE = 'due';
    public const STATUS_PAID = 'paid';
    public const STATUS_REIMBURSED = 'reimbursed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REVIEW_REQUIRED = 'review_required';
    public const REIMBURSEMENT_STATUS_NOT_APPLICABLE = 'not_applicable';
    public const REIMBURSEMENT_STATUS_PENDING = 'pending';
    public const REIMBURSEMENT_STATUS_REIMBURSED = 'reimbursed';
    public const RECURRENCE_FREQUENCY_MONTHLY = 'monthly';
    public const RECURRENCE_FREQUENCY_YEARLY = 'yearly';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
        self::STATUS_DUE,
        self::STATUS_PAID,
        self::STATUS_REIMBURSED,
        self::STATUS_CANCELLED,
        self::STATUS_REVIEW_REQUIRED,
    ];

    public const REIMBURSEMENT_STATUSES = [
        self::REIMBURSEMENT_STATUS_NOT_APPLICABLE,
        self::REIMBURSEMENT_STATUS_PENDING,
        self::REIMBURSEMENT_STATUS_REIMBURSED,
    ];

    public const RECURRENCE_FREQUENCIES = [
        self::RECURRENCE_FREQUENCY_MONTHLY,
        self::RECURRENCE_FREQUENCY_YEARLY,
    ];

    protected $fillable = [
        'user_id',
        'created_by_user_id',
        'approved_by_user_id',
        'paid_by_user_id',
        'reimbursed_by_user_id',
        'team_member_id',
        'recurrence_source_expense_id',
        'title',
        'category_key',
        'supplier_name',
        'reference_number',
        'currency_code',
        'subtotal',
        'tax_amount',
        'total',
        'expense_date',
        'due_date',
        'paid_date',
        'approved_at',
        'reimbursed_at',
        'payment_method',
        'status',
        'reimbursable',
        'reimbursement_status',
        'reimbursement_reference',
        'is_recurring',
        'recurrence_frequency',
        'recurrence_interval',
        'recurrence_next_date',
        'recurrence_ends_at',
        'recurrence_last_generated_at',
        'description',
        'notes',
        'meta',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'expense_date' => 'date',
        'due_date' => 'date',
        'paid_date' => 'date',
        'approved_at' => 'datetime',
        'reimbursed_at' => 'datetime',
        'reimbursable' => 'boolean',
        'is_recurring' => 'boolean',
        'recurrence_interval' => 'integer',
        'recurrence_next_date' => 'date',
        'recurrence_ends_at' => 'date',
        'recurrence_last_generated_at' => 'datetime',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Expense $expense) {
            if (! $expense->status) {
                $expense->status = self::STATUS_DRAFT;
            }

            if (! $expense->currency_code) {
                $expense->currency_code = $expense->user_id
                    ? (User::query()->whereKey($expense->user_id)->value('currency_code') ?: CurrencyCode::default()->value)
                    : CurrencyCode::default()->value;
            }

            if (! $expense->reimbursement_status) {
                $expense->reimbursement_status = $expense->reimbursable
                    ? self::REIMBURSEMENT_STATUS_PENDING
                    : self::REIMBURSEMENT_STATUS_NOT_APPLICABLE;
            }
        });
    }

    public function scopeByAccount($query, int $accountId)
    {
        return $query->where('user_id', $accountId);
    }

    public function accountOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function reimburser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reimbursed_by_user_id');
    }

    public function teamMember(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class);
    }

    public function recurrenceSource(): BelongsTo
    {
        return $this->belongsTo(self::class, 'recurrence_source_expense_id');
    }

    public function generatedRecurrences(): HasMany
    {
        return $this->hasMany(self::class, 'recurrence_source_expense_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ExpenseAttachment::class)->latest('created_at');
    }
}
