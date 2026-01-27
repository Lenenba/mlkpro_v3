<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class PlatformAsset extends Model
{
    protected $fillable = [
        'name',
        'path',
        'mime',
        'size',
        'tags',
        'alt',
        'uploaded_by',
    ];

    protected $casts = [
        'tags' => 'array',
        'size' => 'integer',
    ];

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
