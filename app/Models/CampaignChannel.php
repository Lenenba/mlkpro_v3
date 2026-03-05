<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignChannel extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'message_template_id',
        'channel',
        'is_enabled',
        'subject_template',
        'title_template',
        'body_template',
        'content_override',
        'metadata',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'content_override' => 'array',
        'metadata' => 'array',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }
}
