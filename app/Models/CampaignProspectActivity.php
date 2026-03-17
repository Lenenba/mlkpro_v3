<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignProspectActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_prospect_id',
        'campaign_id',
        'campaign_run_id',
        'campaign_recipient_id',
        'user_id',
        'actor_user_id',
        'activity_type',
        'channel',
        'summary',
        'payload',
        'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(CampaignProspect::class, 'campaign_prospect_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CampaignRun::class, 'campaign_run_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class, 'campaign_recipient_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
