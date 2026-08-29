<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class SocialDeliveryOutbox extends Model
{
    use HasFactory;

    public const OPERATION_CREATE = 'create';

    public const OPERATION_UPDATE = 'update';

    public const OPERATION_CANCEL = 'cancel';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_SUBMITTING = 'submitting';

    public const STATUS_RETRYABLE = 'retryable';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DEAD = 'dead';

    public const RECONCILIATION_RESOLUTION_ERROR = 'error';

    public const RECONCILIATION_RESOLUTION_SENT = 'sent';

    public const RECONCILIATION_SOURCE_STATUS_READ = 'status_read';

    protected $table = 'social_delivery_outbox';

    protected $fillable = [
        'user_id',
        'social_post_target_id',
        'social_post_revision_id',
        'social_provider_connection_id',
        'operation',
        'delivery_provider',
        'transport_generation',
        'logical_destination_key',
        'external_organization_id_snapshot',
        'external_channel_id_snapshot',
        'editorial_revision',
        'recovery_generation',
        'supersedes_outbox_id',
        'idempotency_key',
        'correlation_key',
        'payload_hash',
        'payload',
        'status',
        'attempts',
        'available_at',
        'claimed_at',
        'claim_expires_at',
        'claimed_by',
        'claim_token',
        'claim_version',
        'request_started_at',
        'submitted_at',
        'processed_at',
        'aggregate_repaired_at',
        'provider_post_id',
        'last_error_category',
        'last_error_code',
        'last_error_message',
        'reconciliation_resolved_at',
        'reconciliation_observed_at',
        'reconciliation_resolution',
        'reconciliation_resolution_source',
    ];

    protected $hidden = [
        'payload',
        'claim_token',
    ];

    protected $attributes = [
        'recovery_generation' => 0,
        'status' => self::STATUS_PENDING,
        'attempts' => 0,
        'claim_version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'editorial_revision' => 'integer',
            'recovery_generation' => 'integer',
            'payload' => 'encrypted:array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'claimed_at' => 'datetime',
            'claim_expires_at' => 'datetime',
            'claim_version' => 'integer',
            'request_started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'processed_at' => 'datetime',
            'aggregate_repaired_at' => 'datetime',
            'reconciliation_resolved_at' => 'datetime',
            'reconciliation_observed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $outbox): void {
            $outbox->assertValidIdentity();
            $outbox->assertValidReconciliationResolution();
        });

        static::updating(function (self $outbox): void {
            if ($outbox->isDirty($outbox->immutableAttributes())) {
                throw new LogicException('A Pulse delivery outbox identity cannot be changed.');
            }

            if ($outbox->getRawOriginal('reconciliation_resolved_at') !== null
                && $outbox->isDirty([
                    'reconciliation_resolved_at',
                    'reconciliation_observed_at',
                    'reconciliation_resolution',
                    'reconciliation_resolution_source',
                ])) {
                throw new LogicException(
                    'A Pulse delivery reconciliation resolution cannot be changed after it is recorded.',
                );
            }
        });
    }

    public static function allowedOperations(): array
    {
        return [
            self::OPERATION_CREATE,
            self::OPERATION_UPDATE,
            self::OPERATION_CANCEL,
        ];
    }

    public static function allowedStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_CLAIMED,
            self::STATUS_SUBMITTING,
            self::STATUS_RETRYABLE,
            self::STATUS_SUSPENDED,
            self::STATUS_UNKNOWN,
            self::STATUS_COMPLETED,
            self::STATUS_DEAD,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function socialPostTarget(): BelongsTo
    {
        return $this->belongsTo(SocialPostTarget::class);
    }

    public function socialPostRevision(): BelongsTo
    {
        return $this->belongsTo(SocialPostRevision::class);
    }

    public function socialProviderConnection(): BelongsTo
    {
        return $this->belongsTo(
            SocialAccountConnection::class,
            'social_provider_connection_id'
        );
    }

    public function supersedesOutbox(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_outbox_id');
    }

    public function supersededByOutboxes(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_outbox_id');
    }

    private function assertValidIdentity(): void
    {
        $usesBufferTransport = (string) $this->delivery_provider
                === SocialAccountConnection::DELIVERY_PROVIDER_BUFFER
            || (string) $this->transport_generation
                === SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1;

        if ((int) $this->user_id <= 0
            || (int) $this->social_post_target_id <= 0
            || (int) $this->social_post_revision_id <= 0
            || (int) $this->social_provider_connection_id <= 0
            || ! in_array((string) $this->operation, self::allowedOperations(), true)
            || ! in_array((string) $this->status, self::allowedStatuses(), true)
            || trim((string) $this->delivery_provider) === ''
            || trim((string) $this->transport_generation) === ''
            || mb_strlen((string) $this->delivery_provider) > 32
            || mb_strlen((string) $this->transport_generation) > 32
            || preg_match('/\Aldk:v1:[0-9a-f]{64}\z/', (string) $this->logical_destination_key) !== 1
            || (int) $this->editorial_revision <= 0
            || (int) $this->recovery_generation < 0
            || ($this->supersedes_outbox_id !== null && (int) $this->supersedes_outbox_id <= 0)
            || preg_match('/\A[0-9a-f]{64}\z/', (string) $this->idempotency_key) !== 1
            || ($this->correlation_key !== null
                && (trim((string) $this->correlation_key) === ''
                    || mb_strlen((string) $this->correlation_key) > 64))
            || preg_match('/\A[0-9a-f]{64}\z/', (string) $this->payload_hash) !== 1
            || ! is_array($this->payload)
            || $this->available_at === null
            || (int) $this->attempts < 0
            || (int) $this->claim_version < 0
            || $this->isInvalidNullableSnapshot($this->external_organization_id_snapshot)
            || $this->isInvalidNullableSnapshot($this->external_channel_id_snapshot)
            || ($usesBufferTransport
                && ((string) $this->delivery_provider
                        !== SocialAccountConnection::DELIVERY_PROVIDER_BUFFER
                    || (string) $this->transport_generation
                        !== SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1
                    || $this->external_organization_id_snapshot === null
                    || $this->external_channel_id_snapshot === null))) {
            throw new LogicException('A Pulse delivery outbox identity is incomplete or invalid.');
        }
    }

    /**
     * @return array<int, string>
     */
    private function immutableAttributes(): array
    {
        return [
            'user_id',
            'social_post_target_id',
            'social_post_revision_id',
            'social_provider_connection_id',
            'operation',
            'delivery_provider',
            'transport_generation',
            'logical_destination_key',
            'external_organization_id_snapshot',
            'external_channel_id_snapshot',
            'editorial_revision',
            'recovery_generation',
            'supersedes_outbox_id',
            'idempotency_key',
            'correlation_key',
            'payload_hash',
            'payload',
        ];
    }

    private function isInvalidNullableSnapshot(mixed $value): bool
    {
        return $value !== null
            && (trim((string) $value) === '' || mb_strlen((string) $value) > 191);
    }

    private function assertValidReconciliationResolution(): void
    {
        $resolution = [
            $this->reconciliation_resolved_at,
            $this->reconciliation_observed_at,
            $this->reconciliation_resolution,
            $this->reconciliation_resolution_source,
        ];
        $populated = collect($resolution)->filter(fn (mixed $value): bool => $value !== null);

        if ($populated->isEmpty()) {
            return;
        }

        if ($populated->count() !== count($resolution)
            || ! in_array((string) $this->reconciliation_resolution, [
                self::RECONCILIATION_RESOLUTION_SENT,
                self::RECONCILIATION_RESOLUTION_ERROR,
            ], true)
            || (string) $this->reconciliation_resolution_source
                !== self::RECONCILIATION_SOURCE_STATUS_READ) {
            throw new LogicException(
                'A Pulse delivery reconciliation resolution is incomplete or invalid.',
            );
        }
    }
}
