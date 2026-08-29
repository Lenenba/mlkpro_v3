<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SocialApprovalRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'social_post_id',
        'requested_by_user_id',
        'resolved_by_user_id',
        'status',
        'note',
        'requested_at',
        'approved_at',
        'rejected_at',
        'metadata',
        'social_post_revision_id',
    ];

    protected $casts = [
        'status' => 'string',
        'note' => 'string',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'metadata' => 'array',
        'social_post_revision_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $request): void {
            if ($request->social_post_revision_id === null) {
                return;
            }

            $revision = SocialPostRevision::query()
                ->whereKey($request->social_post_revision_id)
                ->where('social_post_id', $request->social_post_id)
                ->first(['id', 'user_id']);

            if (! $revision) {
                throw new LogicException('A Pulse approval request must reference a revision of its post.');
            }

            foreach (array_filter([
                $request->requested_by_user_id,
                $request->resolved_by_user_id,
            ]) as $actorId) {
                $actor = User::query()->find($actorId);

                if (! $actor || $actor->accountOwnerId() !== (int) $revision->user_id) {
                    throw new LogicException('A Pulse approval actor must belong to its workspace.');
                }
            }
        });

        static::updating(function (self $request): void {
            if ($request->getRawOriginal('social_post_revision_id') !== null
                && $request->isDirty('social_post_revision_id')
                && (string) $request->getRawOriginal('status') !== self::STATUS_PENDING) {
                throw new LogicException('A Pulse approval request revision cannot be changed.');
            }
        });
    }

    public static function allowedStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
        ];
    }

    public function socialPost(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class);
    }

    public function socialPostRevision(): BelongsTo
    {
        return $this->belongsTo(SocialPostRevision::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
