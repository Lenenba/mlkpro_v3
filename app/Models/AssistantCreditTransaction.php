<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantCreditTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'credits',
        'source',
        'stripe_session_id',
        'stripe_payment_intent_id',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
