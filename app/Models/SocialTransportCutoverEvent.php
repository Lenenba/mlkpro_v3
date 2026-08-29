<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SocialTransportCutoverEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'social_transport_cutover_id',
        'user_id',
        'sequence',
        'from_state',
        'to_state',
        'actor_user_id',
        'reason',
        'evidence_hash',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            if ((int) $event->social_transport_cutover_id <= 0
                || (int) $event->user_id <= 0
                || (int) $event->sequence <= 0
                || ! in_array((string) $event->from_state, SocialTransportCutover::allowedStates(), true)
                || ! in_array((string) $event->to_state, SocialTransportCutover::allowedStates(), true)
                || (int) $event->actor_user_id <= 0
                || preg_match('/\A[a-z][a-z0-9_]{1,63}\z/', (string) $event->reason) !== 1
                || preg_match('/\A[0-9a-f]{64}\z/', (string) $event->evidence_hash) !== 1) {
                throw new LogicException('The Pulse transport cutover event is invalid.');
            }
        });

        static::updating(function (): never {
            throw new LogicException('Pulse transport cutover events are immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('Pulse transport cutover events cannot be deleted.');
        });
    }

    public function cutover(): BelongsTo
    {
        return $this->belongsTo(SocialTransportCutover::class, 'social_transport_cutover_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
