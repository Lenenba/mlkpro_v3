<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyProgram extends Model
{
    public const ROUND_FLOOR = 'floor';
    public const ROUND_ROUND = 'round';
    public const ROUND_CEIL = 'ceil';

    protected $fillable = [
        'user_id',
        'is_enabled',
        'points_per_currency_unit',
        'minimum_spend',
        'rounding_mode',
        'points_label',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'points_per_currency_unit' => 'decimal:4',
        'minimum_spend' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

