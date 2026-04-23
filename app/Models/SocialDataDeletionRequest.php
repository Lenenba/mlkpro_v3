<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialDataDeletionRequest extends Model
{
    use HasFactory;

    public const PROVIDER_FACEBOOK = 'facebook';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'provider',
        'confirmation_code',
        'provider_user_id',
        'user_id',
        'status',
        'delete_local_account',
        'failure_reason',
        'summary',
        'requested_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'delete_local_account' => 'boolean',
            'summary' => 'array',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
