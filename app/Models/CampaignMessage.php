<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_recipient_id',
        'campaign_run_id',
        'channel',
        'subject_rendered',
        'title_rendered',
        'body_rendered',
        'cta_url',
        'tracked_cta_url',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class, 'campaign_recipient_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CampaignRun::class, 'campaign_run_id');
    }
}
