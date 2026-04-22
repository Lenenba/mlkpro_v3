<?php

namespace App\Models;

use App\Models\Request as LeadRequest;
use App\Services\TaskTimingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Task extends Model
{
    /** @use HasFactory<\Database\Factories\TaskFactory> */
    use HasFactory;

    public const STATUS_TODO = 'todo';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_DONE = 'done';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_TODO,
        self::STATUS_IN_PROGRESS,
        self::STATUS_DONE,
        self::STATUS_CANCELLED,
    ];

    public const OPEN_STATUSES = [
        self::STATUS_TODO,
        self::STATUS_IN_PROGRESS,
    ];

    public const CLOSED_STATUSES = [
        self::STATUS_DONE,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'account_id',
        'created_by_user_id',
        'assigned_team_member_id',
        'customer_id',
        'product_id',
        'work_id',
        'request_id',
        'title',
        'description',
        'status',
        'billable',
        'due_date',
        'start_time',
        'end_time',
        'completed_at',
        'cancelled_at',
        'completion_reason',
        'cancellation_reason',
        'delay_reason',
        'delay_started_at',
        'client_notified_at',
        'auto_started_at',
        'auto_completed_at',
        'start_alerted_at',
        'end_alerted_at',
    ];

    protected $appends = [
        'timing_status',
    ];

    protected $casts = [
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'delay_started_at' => 'datetime',
        'client_notified_at' => 'datetime',
        'auto_started_at' => 'datetime',
        'auto_completed_at' => 'datetime',
        'start_alerted_at' => 'datetime',
        'end_alerted_at' => 'datetime',
        'billable' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class, 'assigned_team_member_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeadRequest::class, 'request_id');
    }

    public function invoiceItem(): HasOne
    {
        return $this->hasOne(InvoiceItem::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(TaskMaterial::class)->orderBy('sort_order');
    }

    public function media(): HasMany
    {
        return $this->hasMany(TaskMedia::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(TaskStatusHistory::class)->latest('created_at');
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereIn('status', self::CLOSED_STATUSES);
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function getTimingStatusAttribute(): ?string
    {
        return TaskTimingService::resolveTimingStatus($this);
    }
}
