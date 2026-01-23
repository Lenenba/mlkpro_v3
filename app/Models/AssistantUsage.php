<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantUsage extends Model
{
    protected $fillable = [
        'user_id',
        'request_count',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'billed_units',
        'model',
        'provider',
        'stripe_item_id',
        'stripe_usage_id',
        'stripe_synced_at',
    ];

    protected $casts = [
        'stripe_synced_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
