<?php

namespace App\Models;

use App\Services\Social\SocialLogicalDestinationKeyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use LogicException;

class SocialAccountConnection extends Model
{
    use HasFactory;

    public const PLATFORM_FACEBOOK = 'facebook';

    public const PLATFORM_INSTAGRAM = 'instagram';

    public const PLATFORM_LINKEDIN = 'linkedin';

    public const PLATFORM_X = 'x';

    public const AUTH_METHOD_OAUTH = 'oauth';

    public const AUTH_METHOD_MANUAL = 'manual';

    public const DELIVERY_PROVIDER_DIRECT = 'direct';

    public const DELIVERY_PROVIDER_BUFFER = 'buffer';

    public const TRANSPORT_GENERATION_DIRECT_V1 = 'direct_v1';

    public const TRANSPORT_GENERATION_BUFFER_V1 = 'buffer_v1';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_AUTHORIZING = 'authorizing';

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
        'delivery_provider',
        'transport_generation',
        'logical_destination_key',
        'auth_method',
        'credentials',
        'permissions',
        'status',
        'is_active',
        'connected_at',
        'last_synced_at',
        'token_expires_at',
        'oauth_state',
        'oauth_code_verifier',
        'oauth_state_expires_at',
        'last_error',
        'metadata',
    ];

    protected $hidden = [
        'credentials',
        'oauth_state',
        'oauth_code_verifier',
        'delivery_provider',
        'transport_generation',
        'logical_destination_key',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'permissions' => 'array',
            'metadata' => 'array',
            'is_active' => 'boolean',
            'oauth_code_verifier' => 'encrypted',
            'connected_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'token_expires_at' => 'datetime',
            'oauth_state_expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $connection): void {
            if ($connection->exists
                && $connection->getRawOriginal('logical_destination_key') !== null
                && $connection->isDirty([
                    'user_id',
                    'platform',
                    'external_account_id',
                    'delivery_provider',
                    'transport_generation',
                    'logical_destination_key',
                ])) {
                throw new LogicException(
                    'A social connection transport identity cannot be changed after it is assigned.'
                );
            }

            $connection->assertCompleteTransportIdentity();
        });
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

    public static function allowedStatuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_PENDING,
            self::STATUS_AUTHORIZING,
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

    private function assertCompleteTransportIdentity(): void
    {
        $identity = [
            $this->delivery_provider,
            $this->transport_generation,
            $this->logical_destination_key,
        ];
        $populated = collect($identity)->filter(fn (mixed $value): bool => $value !== null);

        if ($populated->isEmpty()) {
            return;
        }

        if ($populated->count() !== count($identity)
            || trim((string) $this->delivery_provider) === ''
            || trim((string) $this->transport_generation) === ''
            || mb_strlen((string) $this->delivery_provider) > 32
            || mb_strlen((string) $this->transport_generation) > 32
            || preg_match('/\Aldk:v1:[0-9a-f]{64}\z/', (string) $this->logical_destination_key) !== 1) {
            throw new LogicException(
                'A social connection transport identity must be complete and use a canonical logical key.'
            );
        }

        $usesDirectIdentity = (string) $this->delivery_provider === self::DELIVERY_PROVIDER_DIRECT
            || (string) $this->transport_generation === self::TRANSPORT_GENERATION_DIRECT_V1;

        if (! $usesDirectIdentity) {
            return;
        }

        if ((string) $this->delivery_provider !== self::DELIVERY_PROVIDER_DIRECT
            || (string) $this->transport_generation !== self::TRANSPORT_GENERATION_DIRECT_V1) {
            throw new LogicException(
                'A direct social connection must use the direct_v1 transport generation.'
            );
        }

        try {
            $expectedLogicalDestinationKey = app(SocialLogicalDestinationKeyService::class)
                ->deriveForLegacyConnection(
                    (string) $this->user_id,
                    (string) $this->platform,
                    (string) $this->external_account_id,
                );
        } catch (InvalidArgumentException) {
            throw new LogicException(
                'A direct social connection must use a derivable native destination identity.'
            );
        }

        if (! hash_equals($expectedLogicalDestinationKey, (string) $this->logical_destination_key)) {
            throw new LogicException(
                'A direct social connection must use its canonical logical destination key.'
            );
        }
    }
}
