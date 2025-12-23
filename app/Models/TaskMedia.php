<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TaskMedia extends Model
{
    /** @use HasFactory<\Database\Factories\TaskMediaFactory> */
    use HasFactory;

    protected $fillable = [
        'task_id',
        'user_id',
        'type',
        'media_type',
        'path',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
