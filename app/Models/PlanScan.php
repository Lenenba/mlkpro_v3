<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PlanScan extends Model
{
    protected $fillable = [
        'user_id',
        'customer_id',
        'property_id',
        'job_title',
        'trade_type',
        'status',
        'plan_file_path',
        'plan_file_name',
        'confidence_score',
        'metrics',
        'analysis',
        'variants',
        'quotes_generated',
        'error_message',
        'analyzed_at',
    ];

    protected $casts = [
        'confidence_score' => 'integer',
        'metrics' => 'array',
        'analysis' => 'array',
        'variants' => 'array',
        'quotes_generated' => 'integer',
        'analyzed_at' => 'datetime',
    ];

    protected $appends = [
        'plan_file_url',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function getPlanFileUrlAttribute(): ?string
    {
        if (!$this->plan_file_path) {
            return null;
        }

        if (str_starts_with($this->plan_file_path, 'http://') || str_starts_with($this->plan_file_path, 'https://')) {
            return $this->plan_file_path;
        }

        return Storage::disk('public')->url($this->plan_file_path);
    }
}
