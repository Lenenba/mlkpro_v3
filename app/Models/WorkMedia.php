<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkMedia extends Model
{
    use HasFactory;

    protected $fillable = [
        'work_id',
        'user_id',
        'type',
        'path',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

