<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class SocialPostRevision extends Model
{
    use HasFactory;

    public const APPROVAL_TYPE_EXPLICIT = 'explicit';

    public const APPROVAL_TYPE_AUTOPILOT_POLICY = 'autopilot_policy';

    public const APPROVAL_TYPE_DIRECT_IMPLICIT = 'direct_implicit';

    public const APPROVAL_TYPE_LEGACY_INFERRED = 'legacy_inferred';

    public const ORIGIN_AUTOMATION = 'automation';

    public const ORIGIN_COMPOSER = 'composer';

    public const ORIGIN_LEGACY_BACKFILL_V1 = 'legacy_backfill_v1';

    protected $fillable = [
        'user_id',
        'social_post_id',
        'revision_number',
        'base_content',
        'source_snapshot',
        'media_snapshot',
        'scheduled_for',
        'scheduled_timezone',
        'scheduled_local_time',
        'payload_hash',
        'created_by_user_id',
        'approved_by_user_id',
        'approved_at',
        'origin',
        'approval_provenance',
    ];

    protected $casts = [
        'revision_number' => 'integer',
        'base_content' => 'array',
        'source_snapshot' => 'array',
        'media_snapshot' => 'array',
        'scheduled_for' => 'datetime',
        'scheduled_local_time' => 'datetime',
        'approved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $revision): void {
            $revision->assertCanonicalSnapshot();
            $revision->assertPostTenant();
        });

        static::updating(function (self $revision): void {
            if ($revision->isDirty([
                'user_id',
                'social_post_id',
                'revision_number',
                'base_content',
                'source_snapshot',
                'media_snapshot',
                'scheduled_for',
                'scheduled_timezone',
                'scheduled_local_time',
                'payload_hash',
                'created_by_user_id',
                'origin',
            ])) {
                throw new LogicException('A Pulse editorial revision snapshot is immutable.');
            }

            if ($revision->getRawOriginal('approved_at') !== null
                && $revision->isDirty(['approved_by_user_id', 'approved_at', 'approval_provenance'])) {
                throw new LogicException('An approved Pulse editorial revision cannot be approved again.');
            }

            if ($revision->getRawOriginal('approved_at') === null
                && $revision->approved_at !== null
                && $revision->approved_by_user_id === null) {
                throw new LogicException('An explicit Pulse approval must record its actor.');
            }
        });

        static::deleting(function (self $revision): void {
            if ($revision->approved_at !== null
                || $revision->approvalRequests()->exists()
                || $revision->currentTargets()->exists()
                || $revision->submittedTargets()->exists()) {
                throw new LogicException('A referenced Pulse editorial revision cannot be deleted.');
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function socialPost(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function approvalRequests(): HasMany
    {
        return $this->hasMany(SocialApprovalRequest::class);
    }

    public function currentTargets(): HasMany
    {
        return $this->hasMany(SocialPostTarget::class, 'current_revision_id');
    }

    public function submittedTargets(): HasMany
    {
        return $this->hasMany(SocialPostTarget::class, 'last_submitted_revision_id');
    }

    private function assertCanonicalSnapshot(): void
    {
        $allowedOrigins = [
            self::ORIGIN_AUTOMATION,
            self::ORIGIN_COMPOSER,
            self::ORIGIN_LEGACY_BACKFILL_V1,
        ];
        $allowedApprovalTypes = [
            self::APPROVAL_TYPE_EXPLICIT,
            self::APPROVAL_TYPE_AUTOPILOT_POLICY,
            self::APPROVAL_TYPE_DIRECT_IMPLICIT,
            self::APPROVAL_TYPE_LEGACY_INFERRED,
        ];

        if ((int) $this->user_id <= 0
            || (int) $this->social_post_id <= 0
            || (int) $this->revision_number <= 0
            || ! is_array($this->base_content)
            || trim((string) $this->scheduled_timezone) === ''
            || ! in_array((string) $this->scheduled_timezone, timezone_identifiers_list(), true)
            || preg_match('/\A[0-9a-f]{64}\z/', (string) $this->payload_hash) !== 1
            || ! in_array((string) $this->origin, $allowedOrigins, true)
            || ($this->approval_provenance !== null
                && ! in_array((string) $this->approval_provenance, $allowedApprovalTypes, true))
            || ($this->approved_at === null) !== ($this->approval_provenance === null)
            || ($this->approved_at === null && $this->approved_by_user_id !== null)
            || ($this->approved_at !== null
                && $this->approval_provenance !== self::APPROVAL_TYPE_LEGACY_INFERRED
                && $this->approved_by_user_id === null)) {
            throw new LogicException('A Pulse editorial revision snapshot is incomplete or invalid.');
        }
    }

    private function assertPostTenant(): void
    {
        $post = SocialPost::query()->find($this->social_post_id);

        if (! $post || (int) $post->user_id !== (int) $this->user_id) {
            throw new LogicException('A Pulse editorial revision must belong to its post workspace.');
        }

        foreach (array_filter([
            $this->created_by_user_id,
            $this->approved_by_user_id,
        ]) as $actorId) {
            $actor = User::query()->find($actorId);

            if (! $actor || $actor->accountOwnerId() !== (int) $this->user_id) {
                throw new LogicException('A Pulse editorial revision actor must belong to its workspace.');
            }
        }
    }
}
