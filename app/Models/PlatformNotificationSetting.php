<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformNotificationSetting extends Model
{
    /** @use HasFactory<\Database\Factories\PlatformNotificationSettingFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'channels',
        'categories',
        'rules',
        'digest_frequency',
        'quiet_hours_start',
        'quiet_hours_end',
    ];

    protected $casts = [
        'channels' => 'array',
        'categories' => 'array',
        'rules' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
