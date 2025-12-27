<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformNotification extends Model
{
    protected $fillable = [
        'user_id',
        'category',
        'title',
        'intro',
        'details',
        'action_url',
        'action_label',
        'severity',
        'digest_frequency',
        'reference',
        'sent_at',
    ];

    protected $casts = [
        'details' => 'array',
        'sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
