<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoWorkspace extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_user_id',
        'created_by_user_id',
        'prospect_name',
        'prospect_email',
        'prospect_company',
        'company_name',
        'company_type',
        'company_sector',
        'seed_profile',
        'team_size',
        'locale',
        'timezone',
        'desired_outcome',
        'internal_notes',
        'selected_modules',
        'configuration',
        'seed_summary',
        'access_email',
        'access_password',
        'expires_at',
        'provisioned_at',
        'last_seeded_at',
    ];

    protected function casts(): array
    {
        return [
            'selected_modules' => 'array',
            'configuration' => 'array',
            'seed_summary' => 'array',
            'access_password' => 'encrypted',
            'expires_at' => 'datetime',
            'provisioned_at' => 'datetime',
            'last_seeded_at' => 'datetime',
            'team_size' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $builder) {
            $builder->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    public function isExpired(): bool
    {
        return (bool) ($this->expires_at && $this->expires_at->lte(now()));
    }
}
