<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SocialVideoProject extends Model
{
    /** @use HasFactory<\Database\Factories\SocialVideoProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'created_by_user_id', 'name', 'source_path', 'preview_path',
        'size', 'duration_ms', 'width', 'height', 'status', 'error_code', 'settings',
        'intelligence_status', 'intelligence_error_code', 'intelligence_run_id', 'intelligence',
    ];

    protected $attributes = ['status' => 'pending', 'intelligence_status' => 'idle'];

    protected $casts = [
        'size' => 'integer', 'duration_ms' => 'integer', 'width' => 'integer',
        'height' => 'integer', 'settings' => 'array',
        'intelligence' => 'array',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clips(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SocialVideoClip::class)->orderBy('position');
    }
}
