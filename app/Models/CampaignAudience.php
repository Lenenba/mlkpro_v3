<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignAudience extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'smart_filters',
        'exclusion_filters',
        'manual_customer_ids',
        'include_mailing_list_ids',
        'exclude_mailing_list_ids',
        'source_logic',
        'source_summary',
        'manual_contacts',
        'estimated_counts',
        'resolved_at',
    ];

    protected $casts = [
        'smart_filters' => 'array',
        'exclusion_filters' => 'array',
        'manual_customer_ids' => 'array',
        'include_mailing_list_ids' => 'array',
        'exclude_mailing_list_ids' => 'array',
        'source_logic' => 'string',
        'source_summary' => 'array',
        'manual_contacts' => 'array',
        'estimated_counts' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
