<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SavedSegment extends Model
{
    use HasFactory;

    public const MODULE_REQUEST = 'request';

    public const MODULE_CUSTOMER = 'customer';

    public const MODULE_QUOTE = 'quote';

    protected $fillable = [
        'user_id',
        'created_by_user_id',
        'updated_by_user_id',
        'module',
        'name',
        'description',
        'filters',
        'sort',
        'search_term',
        'is_shared',
        'cached_count',
        'last_resolved_at',
    ];

    protected $casts = [
        'module' => 'string',
        'filters' => 'array',
        'sort' => 'array',
        'search_term' => 'string',
        'is_shared' => 'boolean',
        'cached_count' => 'integer',
        'last_resolved_at' => 'datetime',
    ];

    public static function allowedModules(): array
    {
        return [
            self::MODULE_REQUEST,
            self::MODULE_CUSTOMER,
            self::MODULE_QUOTE,
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

    public function playbooks(): HasMany
    {
        return $this->hasMany(Playbook::class);
    }

    public function playbookRuns(): HasMany
    {
        return $this->hasMany(PlaybookRun::class);
    }

    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
