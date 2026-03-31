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
        'plan_file_sha256',
        'confidence_score',
        'ai_status',
        'ai_model',
        'ai_cache_key',
        'ai_cache_hit',
        'ai_cache_source',
        'ai_usage',
        'ai_attempts',
        'ai_estimated_cost_usd',
        'ai_extraction_raw',
        'ai_extraction_normalized',
        'ai_reviewed_payload',
        'ai_review_required',
        'ai_retry_count',
        'ai_last_requested_at',
        'ai_escalated_at',
        'ai_failed_at',
        'ai_error_message',
        'metrics',
        'analysis',
        'variants',
        'quotes_generated',
        'error_message',
        'analyzed_at',
    ];

    protected $casts = [
        'confidence_score' => 'integer',
        'ai_cache_hit' => 'boolean',
        'ai_usage' => 'array',
        'ai_attempts' => 'array',
        'ai_estimated_cost_usd' => 'decimal:6',
        'ai_extraction_raw' => 'array',
        'ai_extraction_normalized' => 'array',
        'ai_reviewed_payload' => 'array',
        'ai_review_required' => 'boolean',
        'ai_retry_count' => 'integer',
        'ai_last_requested_at' => 'datetime',
        'ai_escalated_at' => 'datetime',
        'ai_failed_at' => 'datetime',
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
        if (! $this->plan_file_path) {
            return null;
        }

        if (str_starts_with($this->plan_file_path, 'http://') || str_starts_with($this->plan_file_path, 'https://')) {
            return $this->plan_file_path;
        }

        return Storage::disk('public')->url($this->plan_file_path);
    }
}
