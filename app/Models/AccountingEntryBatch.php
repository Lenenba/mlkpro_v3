<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingEntryBatch extends Model
{
    use HasFactory;

    public const STATUS_GENERATED = 'generated';

    public const STATUS_REVIEW_REQUIRED = 'review_required';

    protected $fillable = [
        'user_id',
        'source_type',
        'source_id',
        'source_event_key',
        'source_reference',
        'entry_date',
        'generated_at',
        'status',
        'meta',
    ];

    protected $casts = [
        'source_id' => 'integer',
        'entry_date' => 'date',
        'generated_at' => 'datetime',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(AccountingEntry::class, 'batch_id');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
