<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignProspectProviderConnection extends Model
{
    use HasFactory;

    public const AUTH_METHOD_API_KEY = 'api_key';

    public const AUTH_METHOD_OAUTH = 'oauth';

    public const PROVIDER_APOLLO = 'apollo';

    public const PROVIDER_LUSHA = 'lusha';

    public const PROVIDER_UPLEAD = 'uplead';

    public const STATUS_PENDING = 'pending';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_ERROR = 'error';

    public const STATUS_RECONNECT_REQUIRED = 'reconnect_required';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_RATE_LIMITED = 'rate_limited';

    public const STATUS_DISCONNECTED = 'disconnected';

    protected $fillable = [
        'user_id',
        'provider_key',
        'label',
        'auth_method',
        'credentials',
        'status',
        'is_active',
        'last_validated_at',
        'connected_at',
        'last_refreshed_at',
        'token_expires_at',
        'last_error',
        'oauth_state',
        'oauth_state_expires_at',
        'external_account_id',
        'external_account_label',
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
            'metadata' => 'array',
            'last_validated_at' => 'datetime',
            'connected_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
            'token_expires_at' => 'datetime',
            'oauth_state_expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function allowedProviders(): array
    {
        return [
            self::PROVIDER_APOLLO,
            self::PROVIDER_LUSHA,
            self::PROVIDER_UPLEAD,
        ];
    }

    public static function allowedStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_DRAFT,
            self::STATUS_CONNECTED,
            self::STATUS_ERROR,
            self::STATUS_RECONNECT_REQUIRED,
            self::STATUS_INVALID,
            self::STATUS_EXPIRED,
            self::STATUS_RATE_LIMITED,
            self::STATUS_DISCONNECTED,
        ];
    }

    public static function allowedAuthMethods(): array
    {
        return [
            self::AUTH_METHOD_API_KEY,
            self::AUTH_METHOD_OAUTH,
        ];
    }
}
