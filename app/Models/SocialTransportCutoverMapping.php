<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SocialTransportCutoverMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'social_transport_cutover_id',
        'user_id',
        'legacy_connection_id',
        'replacement_connection_id',
        'logical_destination_key',
        'owner_validated_by_user_id',
        'owner_validated_at',
        'owner_evidence_hash',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $mapping): void {
            if ((int) $mapping->social_transport_cutover_id <= 0
                || (int) $mapping->user_id <= 0
                || (int) $mapping->legacy_connection_id <= 0
                || (int) $mapping->replacement_connection_id <= 0
                || (int) $mapping->legacy_connection_id === (int) $mapping->replacement_connection_id
                || preg_match('/\Aldk:v1:[0-9a-f]{64}\z/', (string) $mapping->logical_destination_key) !== 1
                || preg_match('/\A[0-9a-f]{64}\z/', (string) $mapping->owner_evidence_hash) !== 1
                || (($mapping->shadow_validated_at === null)
                    !== ($mapping->shadow_evidence_hash === null))
                || ($mapping->shadow_evidence_hash !== null
                    && preg_match('/\A[0-9a-f]{64}\z/', (string) $mapping->shadow_evidence_hash) !== 1)) {
                throw new LogicException('The Pulse transport mapping is invalid.');
            }

        });

        static::updating(function (): never {
            throw new LogicException('A validated Pulse transport mapping is immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('Validated Pulse transport mappings cannot be deleted.');
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

    public function legacyConnection(): BelongsTo
    {
        return $this->belongsTo(SocialAccountConnection::class, 'legacy_connection_id');
    }

    public function replacementConnection(): BelongsTo
    {
        return $this->belongsTo(SocialAccountConnection::class, 'replacement_connection_id');
    }

    protected function casts(): array
    {
        return [
            'owner_validated_at' => 'datetime',
            'shadow_validated_at' => 'datetime',
        ];
    }
}
