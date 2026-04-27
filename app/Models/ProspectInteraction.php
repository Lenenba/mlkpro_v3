<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProspectInteraction extends Model
{
    use HasFactory;

    protected $appends = [
        'attachment',
        'next_action',
    ];

    protected $fillable = [
        'request_id',
        'user_id',
        'type',
        'description',
        'source_type',
        'source_id',
        'attachment_name',
        'attachment_path',
        'attachment_mime',
        'attachment_size',
        'next_action_at',
        'next_action_label',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'next_action_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Request::class, 'request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{name: string|null, path: string|null, mime: string|null, size: int|null}|null
     */
    public function getAttachmentAttribute(): ?array
    {
        if (
            $this->attachment_name === null
            && $this->attachment_path === null
            && $this->attachment_mime === null
            && $this->attachment_size === null
        ) {
            return null;
        }

        return [
            'name' => $this->attachment_name,
            'path' => $this->attachment_path,
            'mime' => $this->attachment_mime,
            'size' => $this->attachment_size !== null ? (int) $this->attachment_size : null,
        ];
    }

    /**
     * @return array{at: string|null, label: string|null}|null
     */
    public function getNextActionAttribute(): ?array
    {
        if ($this->next_action_at === null && blank($this->next_action_label)) {
            return null;
        }

        return [
            'at' => $this->next_action_at?->toJSON(),
            'label' => $this->next_action_label,
        ];
    }
}
