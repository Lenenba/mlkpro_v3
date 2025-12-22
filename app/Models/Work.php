<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\GeneratesSequentialNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

class Work extends Model
{
    /** @use HasFactory<\Database\Factories\WorkFactory> */
    use HasFactory, GeneratesSequentialNumber;

    public const STATUS_TO_SCHEDULE = 'to_schedule';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_EN_ROUTE = 'en_route';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_TECH_COMPLETE = 'tech_complete';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_AUTO_VALIDATED = 'auto_validated';
    public const STATUS_DISPUTE = 'dispute';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'user_id',
        'customer_id',
        'quote_id',
        'job_title',
        'instructions',
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        'is_all_day',
        'later',
        'ends',
        'frequencyNumber',
        'frequency',
        'totalVisits',
        'repeatsOn',
        'type',
        'category',
        'status',
        'is_completed',
        'completed_at',
        'subtotal',
        'total',
    ];

    public const STATUSES = [
        self::STATUS_TO_SCHEDULE,
        self::STATUS_SCHEDULED,
        self::STATUS_EN_ROUTE,
        self::STATUS_IN_PROGRESS,
        self::STATUS_TECH_COMPLETE,
        self::STATUS_PENDING_REVIEW,
        self::STATUS_VALIDATED,
        self::STATUS_AUTO_VALIDATED,
        self::STATUS_DISPUTE,
        self::STATUS_CLOSED,
        self::STATUS_CANCELLED,
        self::STATUS_COMPLETED,
    ];

    public const COMPLETED_STATUSES = [
        self::STATUS_TECH_COMPLETE,
        self::STATUS_PENDING_REVIEW,
        self::STATUS_VALIDATED,
        self::STATUS_AUTO_VALIDATED,
        self::STATUS_CLOSED,
        self::STATUS_COMPLETED,
    ];

    protected $casts = [
        'repeatsOn' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'completed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($work) {
            if (!$work->customer_id) {
                throw new \Exception('Customer ID is required to generate a work number.');
            }

            $work->number = self::generateScopedNumber($work->customer_id, 'W');
        });

        static::saving(function ($work) {
            if (in_array($work->status, self::COMPLETED_STATUSES, true)) {
                $work->is_completed = true;
                $work->completed_at = $work->completed_at ?? now();
                return;
            }

            if ($work->isDirty('status')) {
                $work->is_completed = false;
                $work->completed_at = null;
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoice()
    {
        return $this->hasOne(Invoice::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_works')
            ->withPivot(['quantity', 'price', 'description', 'total']);
    }

    public function teamMembers(): BelongsToMany
    {
        return $this->belongsToMany(TeamMember::class, 'work_team_members')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(WorkRating::class);
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(WorkChecklistItem::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(WorkMedia::class);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('is_completed', true);
    }

    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByCustomer(Builder $query, $customerIds): Builder
    {
        $ids = is_array($customerIds) ? $customerIds : [$customerIds];

        return $query->whereIn('customer_id', $ids);
    }

    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function scopeMostRecent(Builder $query): Builder
    {
        return $query->orderByDesc('created_at');
    }

    public function scopeRecent(Builder $query, int $days): Builder
    {
        return $query->whereDate('start_date', '>=', now()->subDays($days));
    }

    public function getDurationInHours(): float
    {
        if (!$this->start_time || !$this->end_time) {
            return 0.0;
        }

        $start = Carbon::createFromFormat('H:i:s', $this->start_time);
        $end = Carbon::createFromFormat('H:i:s', $this->end_time);

        if (!$start || !$end) {
            return 0.0;
        }

        $minutes = $start->diffInMinutes($end, false);

        return round(max(0, $minutes) / 60, 2);
    }

    public function getFormattedDate(): string
    {
        if (!$this->start_date) {
            return '';
        }

        $date = $this->start_date instanceof Carbon
            ? $this->start_date
            : Carbon::parse($this->start_date);

        $time = $this->start_time ?: '00:00:00';

        return Carbon::parse($date->toDateString() . ' ' . $time)->format('d M Y, H:i');
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        $search = $filters['search'] ?? $filters['name'] ?? null;

        return $query
            ->when(
                $search,
                fn(Builder $query, $value) => $query->where(function (Builder $sub) use ($value) {
                    $sub->where('job_title', 'like', '%' . $value . '%')
                        ->orWhere('instructions', 'like', '%' . $value . '%')
                        ->orWhere('type', 'like', '%' . $value . '%');
                })
            )
            ->when(
                $filters['status'] ?? null,
                function (Builder $query, $status) {
                    if (in_array($status, self::STATUSES, true)) {
                        $query->where('status', $status);
                        return;
                    }

                    $flag = filter_var($status, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($flag !== null) {
                        $query->where('is_completed', $flag);
                    }
                }
            )
            ->when(
                $filters['customer_id'] ?? null,
                function (Builder $query, $customerIds) {
                    $ids = is_array($customerIds) ? $customerIds : [$customerIds];
                    $query->whereIn('customer_id', $ids);
                }
            )
            ->when(
                $filters['start_from'] ?? null,
                fn(Builder $query, $from) => $query->whereDate('start_date', '>=', $from)
            )
            ->when(
                $filters['start_to'] ?? null,
                fn(Builder $query, $to) => $query->whereDate('start_date', '<=', $to)
            )
            ->when(
                $filters['month'] ?? null,
                fn(Builder $query, $month) => $query->whereMonth('start_date', $month)
            );
    }
}
