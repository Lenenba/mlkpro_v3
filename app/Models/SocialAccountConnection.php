<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialAccountConnection extends Model
{
    use HasFactory;

    public const PLATFORM_FACEBOOK = 'facebook';

    public const PLATFORM_INSTAGRAM = 'instagram';

    public const PLATFORM_LINKEDIN = 'linkedin';

    public const PLATFORM_X = 'x';

    public const AUTH_METHOD_OAUTH = 'oauth';

    public const AUTH_METHOD_MANUAL = 'manual';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_ERROR = 'error';

    public const STATUS_RECONNECT_REQUIRED = 'reconnect_required';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_DISCONNECTED = 'disconnected';

    protected $fillable = [
        'user_id',
        'platform',
        'label',
        'display_name',
        'account_handle',
        'external_account_id',
        'auth_method',
        'credentials',
        'permissions',
        'status',
        'is_active',
        'connected_at',
        'last_synced_at',
        'token_expires_at',
        'oauth_state',
        'oauth_state_expires_at',
        'last_error',
        'metadata',
    ];

    protected $hidden = [
        'credentials',
        'oauth_state',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'permissions' => 'array',
            'metadata' => 'array',
            'is_active' => 'boolean',
            'connected_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'token_expires_at' => 'datetime',
            'oauth_state_expires_at' => 'datetime',
        ];
    }

    public static function allowedPlatforms(): array
    {
        return [
            self::PLATFORM_FACEBOOK,
            self::PLATFORM_INSTAGRAM,
            self::PLATFORM_LINKEDIN,
            self::PLATFORM_X,
        ];
    }

    public static function allowedAuthMethods(): array
    {
        return [
            self::AUTH_METHOD_OAUTH,
            self::AUTH_METHOD_MANUAL,
        ];
    }

    public static function allowedStatuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_PENDING,
            self::STATUS_CONNECTED,
            self::STATUS_ERROR,
            self::STATUS_RECONNECT_REQUIRED,
            self::STATUS_EXPIRED,
            self::STATUS_DISCONNECTED,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function socialPostTargets(): HasMany
    {
        return $this->hasMany(SocialPostTarget::class, 'social_account_connection_id');
    }

    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeConnected(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('status', self::STATUS_CONNECTED);
    }
}
