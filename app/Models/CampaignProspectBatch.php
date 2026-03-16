<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignProspectBatch extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ANALYZED = 'analyzed';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'campaign_id',
        'user_id',
        'approved_by_user_id',
        'source_type',
        'source_reference',
        'batch_number',
        'input_count',
        'accepted_count',
        'rejected_count',
        'duplicate_count',
        'blocked_count',
        'scored_count',
        'contacted_count',
        'replied_count',
        'lead_count',
        'customer_count',
        'status',
        'analysis_summary',
        'approved_at',
    ];

    protected $casts = [
        'analysis_summary' => 'array',
        'approved_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function prospects(): HasMany
    {
        return $this->hasMany(CampaignProspect::class, 'campaign_prospect_batch_id');
    }
}
