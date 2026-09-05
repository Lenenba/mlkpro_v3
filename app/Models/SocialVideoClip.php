<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SocialVideoClip extends Model
{
    /** @use HasFactory<\Database\Factories\SocialVideoClipFactory> */
    use HasFactory;

    protected $fillable = [
        'social_video_project_id', 'position', 'start_ms', 'end_ms', 'format',
        'framing', 'focal_x', 'focal_y', 'status', 'path', 'error_code',
        'publication_ids',
    ];

    protected $attributes = ['status' => 'pending', 'focal_x' => 50, 'focal_y' => 50];

    protected $casts = [
        'position' => 'integer', 'start_ms' => 'integer', 'end_ms' => 'integer',
        'focal_x' => 'integer', 'focal_y' => 'integer',
        'publication_ids' => 'array',
    ];

    public function project(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SocialVideoProject::class, 'social_video_project_id');
    }
}
