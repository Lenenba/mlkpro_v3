<?php

namespace App\Models;

use App\Enums\PromotionDiscountType;
use App\Enums\PromotionStatus;
use App\Enums\PromotionTargetType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promotion extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'created_by_user_id',
        'name',
        'code',
        'target_type',
        'target_id',
        'discount_type',
        'discount_value',
        'start_date',
        'end_date',
        'status',
        'usage_limit',
        'minimum_order_amount',
        'rules',
    ];

    protected $casts = [
        'target_type' => PromotionTargetType::class,
        'discount_type' => PromotionDiscountType::class,
        'discount_value' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'status' => PromotionStatus::class,
        'usage_limit' => 'integer',
        'minimum_order_amount' => 'decimal:2',
        'rules' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(PromotionUsage::class);
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('user_id', $accountId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', PromotionStatus::ACTIVE->value);
    }

    public function scopeAvailableOn(Builder $query, \DateTimeInterface|string|null $date = null): Builder
    {
        $resolved = $date ? Carbon::parse($date)->toDateString() : now()->toDateString();

        return $query
            ->whereDate('start_date', '<=', $resolved)
            ->whereDate('end_date', '>=', $resolved);
    }
}
