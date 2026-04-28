<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PettyCashAttachment extends Model
{
    protected $fillable = [
        'user_id',
        'petty_cash_movement_id',
        'uploaded_by_user_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function movement(): BelongsTo
    {
        return $this->belongsTo(PettyCashMovement::class, 'petty_cash_movement_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
