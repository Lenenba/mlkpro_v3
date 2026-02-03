<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ShiftTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'created_by_user_id',
        'position_title',
        'start_time',
        'end_time',
        'break_minutes',
        'breaks',
        'days_of_week',
        'is_active',
    ];

    protected $casts = [
        'breaks' => 'array',
        'days_of_week' => 'array',
        'is_active' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
