<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class LeadMedia extends Model
{
    use HasFactory;

    protected $appends = [
        'url',
    ];

    protected $fillable = [
        'request_id',
        'user_id',
        'path',
        'original_name',
        'mime',
        'size',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Request::class, 'request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getUrlAttribute(): ?string
    {
        if (!$this->path) {
            return null;
        }

        if (str_starts_with($this->path, 'http://') || str_starts_with($this->path, 'https://')) {
            return $this->path;
        }

        return Storage::disk('public')->url($this->path);
    }
}
