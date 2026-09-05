<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class SocialPost extends Model
{
    use HasFactory;

    public const DELIVERY_STATUS_CANCELED = 'canceled';

    public const DELIVERY_STATUS_FAILED = 'failed';

    public const DELIVERY_STATUS_NOT_SUBMITTED = 'not_submitted';

    public const DELIVERY_STATUS_PARTIAL_FAILED = 'partial_failed';

    public const DELIVERY_STATUS_PUBLISHED = 'published';

    public const DELIVERY_STATUS_PUBLISHING = 'publishing';

    public const DELIVERY_STATUS_QUEUED = 'queued';

    public const DELIVERY_STATUS_REMOTE_APPROVAL_REQUIRED = 'remote_approval_required';

    public const DELIVERY_STATUS_SCHEDULED = 'scheduled';

    public const DELIVERY_STATUS_SUBMITTED = 'submitted';

    public const DELIVERY_STATUS_UNKNOWN = 'unknown';

    public const EDITORIAL_STATUS_APPROVED = 'approved';

    public const EDITORIAL_STATUS_ARCHIVED = 'archived';

    public const EDITORIAL_STATUS_DRAFT = 'draft';

    public const EDITORIAL_STATUS_PENDING_APPROVAL = 'pending_approval';

    public const EDITORIAL_STATUS_REJECTED = 'rejected';

    public const STATUS_SOURCE_DERIVED = 'derived';

    public const STATUS_SOURCE_EXPLICIT = 'explicit';

    public const STATUS_SOURCE_LEGACY_INFERRED = 'legacy_inferred';

    public const SYNC_STATUS_ERROR = 'error';

    public const SYNC_STATUS_PENDING = 'pending';

    public const SYNC_STATUS_RECONNECT_REQUIRED = 'reconnect_required';

    public const SYNC_STATUS_SYNCED = 'synced';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_PUBLISHING = 'publishing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_PARTIAL_FAILED = 'partial_failed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'created_by_user_id',
        'updated_by_user_id',
        'source_type',
        'source_id',
        'social_automation_rule_id',
        'content_payload',
        'media_payload',
        'link_url',
        'status',
        'scheduled_for',
        'published_at',
        'failed_at',
        'failure_reason',
        'metadata',
        'editorial_status',
        'delivery_status',
        'sync_status',
        'current_editorial_revision',
        'approved_revision_id',
        'scheduled_timezone',
        'scheduled_local_time',
        'payload_hash',
        'delivery_aggregated_at',
        'editorial_status_source',
        'delivery_status_source',
        'sync_status_source',
    ];

    protected $casts = [
        'source_id' => 'integer',
        'social_automation_rule_id' => 'integer',
        'content_payload' => 'array',
        'media_payload' => 'array',
        'link_url' => 'string',
        'status' => 'string',
        'scheduled_for' => 'datetime',
        'published_at' => 'datetime',
        'failed_at' => 'datetime',
        'failure_reason' => 'string',
        'metadata' => 'array',
        'current_editorial_revision' => 'integer',
        'approved_revision_id' => 'integer',
        'scheduled_local_time' => 'datetime',
        'delivery_aggregated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $post): void {
            $post->assertEditorialFoundation();
        });
    }

    public static function allowedStatuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_SCHEDULED,
            self::STATUS_PENDING_APPROVAL,
            self::STATUS_PUBLISHING,
            self::STATUS_PUBLISHED,
            self::STATUS_PARTIAL_FAILED,
            self::STATUS_FAILED,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function automationRule(): BelongsTo
    {
        return $this->belongsTo(SocialAutomationRule::class, 'social_automation_rule_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(SocialPostTarget::class)->orderBy('id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(SocialPostRevision::class)->orderBy('revision_number');
    }

    public function approvedRevision(): BelongsTo
    {
        return $this->belongsTo(SocialPostRevision::class, 'approved_revision_id');
    }

    public function approvalRequests(): HasMany
    {
        return $this->hasMany(SocialApprovalRequest::class)->latest('id');
    }

    public function latestApprovalRequest(): HasOne
    {
        return $this->hasOne(SocialApprovalRequest::class)->latestOfMany();
    }

    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    private function assertEditorialFoundation(): void
    {
        $foundation = [
            $this->editorial_status,
            $this->delivery_status,
            $this->sync_status,
            $this->current_editorial_revision,
            $this->scheduled_timezone,
            $this->payload_hash,
            $this->delivery_aggregated_at,
            $this->editorial_status_source,
            $this->delivery_status_source,
            $this->sync_status_source,
        ];
        $populated = collect($foundation)->filter(fn (mixed $value): bool => $value !== null);

        if ($populated->isEmpty()) {
            if ($this->approved_revision_id !== null) {
                throw new LogicException(
                    'An approved Pulse revision requires a complete editorial foundation.'
                );
            }

            return;
        }

        if ($populated->count() !== count($foundation)
            || (int) $this->current_editorial_revision <= 0
            || ! in_array((string) $this->editorial_status, [
                self::EDITORIAL_STATUS_DRAFT,
                self::EDITORIAL_STATUS_PENDING_APPROVAL,
                self::EDITORIAL_STATUS_APPROVED,
                self::EDITORIAL_STATUS_REJECTED,
                self::EDITORIAL_STATUS_ARCHIVED,
            ], true)
            || ! in_array((string) $this->delivery_status, [
                self::DELIVERY_STATUS_NOT_SUBMITTED,
                self::DELIVERY_STATUS_QUEUED,
                self::DELIVERY_STATUS_SUBMITTED,
                self::DELIVERY_STATUS_SCHEDULED,
                self::DELIVERY_STATUS_REMOTE_APPROVAL_REQUIRED,
                self::DELIVERY_STATUS_PUBLISHING,
                self::DELIVERY_STATUS_PUBLISHED,
                self::DELIVERY_STATUS_PARTIAL_FAILED,
                self::DELIVERY_STATUS_FAILED,
                self::DELIVERY_STATUS_UNKNOWN,
                self::DELIVERY_STATUS_CANCELED,
            ], true)
            || ! in_array((string) $this->sync_status, [
                self::SYNC_STATUS_PENDING,
                self::SYNC_STATUS_SYNCED,
                self::SYNC_STATUS_ERROR,
                self::SYNC_STATUS_RECONNECT_REQUIRED,
            ], true)
            || ! in_array((string) $this->scheduled_timezone, timezone_identifiers_list(), true)
            || preg_match('/\A[0-9a-f]{64}\z/', (string) $this->payload_hash) !== 1
            || ! in_array((string) $this->editorial_status_source, [
                self::STATUS_SOURCE_DERIVED,
                self::STATUS_SOURCE_EXPLICIT,
                self::STATUS_SOURCE_LEGACY_INFERRED,
            ], true)
            || ! in_array((string) $this->delivery_status_source, [
                self::STATUS_SOURCE_DERIVED,
                self::STATUS_SOURCE_EXPLICIT,
                self::STATUS_SOURCE_LEGACY_INFERRED,
            ], true)
            || ! in_array((string) $this->sync_status_source, [
                self::STATUS_SOURCE_DERIVED,
                self::STATUS_SOURCE_EXPLICIT,
                self::STATUS_SOURCE_LEGACY_INFERRED,
            ], true)) {
            throw new LogicException('A Pulse post editorial foundation is incomplete or invalid.');
        }

        if ($this->exists) {
            $hasCurrentRevision = SocialPostRevision::query()
                ->where('social_post_id', $this->id)
                ->where('user_id', $this->user_id)
                ->where('revision_number', $this->current_editorial_revision)
                ->where('payload_hash', $this->payload_hash)
                ->exists();

            if (! $hasCurrentRevision) {
                throw new LogicException('A Pulse post must reference its current immutable revision.');
            }
        }

        if ($this->approved_revision_id !== null) {
            $approvedRevision = SocialPostRevision::query()
                ->whereKey($this->approved_revision_id)
                ->where('social_post_id', $this->id)
                ->where('user_id', $this->user_id)
                ->where('revision_number', $this->current_editorial_revision)
                ->where('payload_hash', $this->payload_hash)
                ->whereNotNull('approved_at')
                ->exists();

            if (! $approvedRevision) {
                throw new LogicException('A Pulse post approved revision must be approved and belong to the post.');
            }
        }
    }
}
