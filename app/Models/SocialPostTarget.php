<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SocialPostTarget extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PUBLISHING = 'publishing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'social_post_id',
        'social_account_connection_id',
        'delivery_provider',
        'transport_generation',
        'logical_destination_key',
        'current_revision_id',
        'last_submitted_revision_id',
        'current_editorial_revision',
        'delivery_status',
        'sync_status',
        'payload_hash',
        'provider_post_id',
        'provider_status',
        'submitted_at',
        'remote_scheduled_for',
        'last_synced_at',
        'next_reconcile_at',
        'reconcile_attempts',
        'reconcile_claimed_at',
        'reconcile_claim_expires_at',
        'reconcile_claimed_by',
        'reconcile_claim_token',
        'reconcile_claim_version',
        'provider_error_code',
        'provider_error_message',
        'status',
        'published_at',
        'failed_at',
        'failure_reason',
        'metadata',
    ];

    protected $hidden = [
        'delivery_provider',
        'transport_generation',
        'logical_destination_key',
        'provider_post_id',
        'provider_status',
        'provider_error_code',
        'provider_error_message',
        'reconcile_claimed_by',
        'reconcile_claim_token',
    ];

    protected $casts = [
        'status' => 'string',
        'published_at' => 'datetime',
        'failed_at' => 'datetime',
        'failure_reason' => 'string',
        'metadata' => 'array',
        'current_revision_id' => 'integer',
        'last_submitted_revision_id' => 'integer',
        'current_editorial_revision' => 'integer',
        'submitted_at' => 'datetime',
        'remote_scheduled_for' => 'datetime',
        'last_synced_at' => 'datetime',
        'next_reconcile_at' => 'datetime',
        'reconcile_attempts' => 'integer',
        'reconcile_claimed_at' => 'datetime',
        'reconcile_claim_expires_at' => 'datetime',
        'reconcile_claim_version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $target): void {
            $target->assertCompleteTransportIdentity();
            $target->assertRevisionPointers();
            $target->assertProviderPostIdentity();
        });

        static::creating(function (self $target): void {
            $target->assertTransportIdentityMatchesConnection();
        });

        static::updating(function (self $target): void {
            if ($target->getRawOriginal('provider_post_id') !== null
                && $target->isDirty('provider_post_id')) {
                throw new LogicException(
                    'A social target provider post identity cannot be changed after it is assigned.',
                );
            }

            if ($target->getRawOriginal('logical_destination_key') === null
                && $target->logical_destination_key !== null) {
                $target->assertTransportIdentityMatchesConnection();
            }

            if ($target->getRawOriginal('logical_destination_key') === null) {
                return;
            }

            if ($target->isDirty([
                'social_post_id',
                'social_account_connection_id',
                'delivery_provider',
                'transport_generation',
                'logical_destination_key',
            ])) {
                throw new LogicException(
                    'A social target transport identity cannot be changed after it is assigned.'
                );
            }
        });
    }

    private function assertTransportIdentityMatchesConnection(): void
    {
        $identityIsEmpty = $this->delivery_provider === null
            && $this->transport_generation === null
            && $this->logical_destination_key === null;

        $postOwnerId = SocialPost::query()
            ->whereKey($this->social_post_id)
            ->value('user_id');

        if ($identityIsEmpty && $postOwnerId !== null
            && $this->social_account_connection_id === null) {
            return;
        }

        $connection = SocialAccountConnection::query()
            ->whereKey($this->social_account_connection_id)
            ->first([
                'id',
                'user_id',
                'delivery_provider',
                'transport_generation',
                'logical_destination_key',
            ]);

        if ($postOwnerId === null
            || ! $connection
            || (int) $postOwnerId !== (int) $connection->user_id) {
            throw new LogicException(
                'A social target transport identity must belong to the same tenant as its post.'
            );
        }

        if ($identityIsEmpty) {
            return;
        }

        if ((string) $this->delivery_provider !== (string) $connection->delivery_provider
            || (string) $this->transport_generation !== (string) $connection->transport_generation
            || ! hash_equals(
                (string) $connection->logical_destination_key,
                (string) $this->logical_destination_key,
            )) {
            throw new LogicException(
                'A social target transport identity must exactly match its connection snapshot.'
            );
        }
    }

    public static function allowedStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_SCHEDULED,
            self::STATUS_PUBLISHING,
            self::STATUS_PUBLISHED,
            self::STATUS_FAILED,
            self::STATUS_CANCELED,
        ];
    }

    public function socialPost(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class);
    }

    public function socialAccountConnection(): BelongsTo
    {
        return $this->belongsTo(SocialAccountConnection::class, 'social_account_connection_id');
    }

    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(SocialPostRevision::class, 'current_revision_id');
    }

    public function lastSubmittedRevision(): BelongsTo
    {
        return $this->belongsTo(SocialPostRevision::class, 'last_submitted_revision_id');
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
                'A social target transport identity must be complete and use a canonical logical key.'
            );
        }
    }

    private function assertRevisionPointers(): void
    {
        $foundation = [
            $this->current_revision_id,
            $this->current_editorial_revision,
            $this->delivery_status,
            $this->sync_status,
            $this->payload_hash,
        ];
        $populated = collect($foundation)->filter(fn (mixed $value): bool => $value !== null);

        if ($populated->isEmpty()) {
            if ($this->last_submitted_revision_id !== null) {
                throw new LogicException(
                    'A submitted Pulse revision requires a complete current revision pointer.'
                );
            }

            return;
        }

        if ($populated->count() !== count($foundation)
            || (int) $this->current_editorial_revision <= 0
            || preg_match('/\A[0-9a-f]{64}\z/', (string) $this->payload_hash) !== 1
            || ! in_array((string) $this->delivery_status, [
                SocialPost::DELIVERY_STATUS_NOT_SUBMITTED,
                SocialPost::DELIVERY_STATUS_QUEUED,
                SocialPost::DELIVERY_STATUS_SUBMITTED,
                SocialPost::DELIVERY_STATUS_SCHEDULED,
                SocialPost::DELIVERY_STATUS_REMOTE_APPROVAL_REQUIRED,
                'sending',
                SocialPost::DELIVERY_STATUS_PUBLISHED,
                SocialPost::DELIVERY_STATUS_FAILED,
                SocialPost::DELIVERY_STATUS_UNKNOWN,
                SocialPost::DELIVERY_STATUS_CANCELED,
            ], true)
            || ! in_array((string) $this->sync_status, [
                SocialPost::SYNC_STATUS_PENDING,
                SocialPost::SYNC_STATUS_SYNCED,
                SocialPost::SYNC_STATUS_ERROR,
                SocialPost::SYNC_STATUS_RECONNECT_REQUIRED,
            ], true)) {
            throw new LogicException('A Pulse target revision pointer is incomplete or invalid.');
        }

        $postUserId = SocialPost::query()->whereKey($this->social_post_id)->value('user_id');
        $currentRevision = SocialPostRevision::query()->find($this->current_revision_id);
        if (! $currentRevision
            || $postUserId === null
            || (int) $currentRevision->social_post_id !== (int) $this->social_post_id
            || (int) $currentRevision->user_id !== (int) $postUserId
            || (int) $currentRevision->revision_number !== (int) $this->current_editorial_revision
            || ! hash_equals((string) $currentRevision->payload_hash, (string) $this->payload_hash)) {
            throw new LogicException('A Pulse target must reference the current revision of its post.');
        }

        if ($this->last_submitted_revision_id !== null) {
            $submittedRevisionBelongsToPost = SocialPostRevision::query()
                ->whereKey($this->last_submitted_revision_id)
                ->where('social_post_id', $this->social_post_id)
                ->where('user_id', $postUserId)
                ->whereNotNull('approved_at')
                ->exists();

            if (! $submittedRevisionBelongsToPost) {
                throw new LogicException(
                    'A submitted Pulse revision must be approved and belong to its target post.'
                );
            }
        }
    }

    private function assertProviderPostIdentity(): void
    {
        if ($this->provider_post_id === null) {
            return;
        }

        if (trim((string) $this->provider_post_id) === ''
            || mb_strlen((string) $this->provider_post_id) > 191) {
            throw new LogicException(
                'A social target provider post identity must be non-blank and bounded.',
            );
        }
    }
}
