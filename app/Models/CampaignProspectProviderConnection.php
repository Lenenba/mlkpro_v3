<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignProspectProviderConnection extends Model
{
    use HasFactory;

    public const PROVIDER_APOLLO = 'apollo';
    public const PROVIDER_LUSHA = 'lusha';
    public const PROVIDER_UPLEAD = 'uplead';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_RATE_LIMITED = 'rate_limited';
    public const STATUS_DISCONNECTED = 'disconnected';

    protected $fillable = [
        'user_id',
        'provider_key',
        'label',
        'credentials',
        'status',
        'is_active',
        'last_validated_at',
        'last_error',
        'metadata',
    ];

    protected $hidden = [
        'credentials',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'metadata' => 'array',
            'last_validated_at' => 'datetime',
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
            self::STATUS_DRAFT,
            self::STATUS_CONNECTED,
            self::STATUS_INVALID,
            self::STATUS_EXPIRED,
            self::STATUS_RATE_LIMITED,
            self::STATUS_DISCONNECTED,
        ];
    }
}
