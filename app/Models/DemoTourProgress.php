<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoTourProgress extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'step_key',
        'status',
        'completed_at',
        'metadata_json',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'metadata_json' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
