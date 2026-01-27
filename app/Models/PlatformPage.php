<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformPage extends Model
{
    protected $fillable = [
        'slug',
        'title',
        'is_active',
        'content',
        'updated_by',
        'published_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'content' => 'array',
        'published_at' => 'datetime',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
