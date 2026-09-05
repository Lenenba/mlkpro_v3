<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyRole extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'slug',
        'description',
        'is_system',
        'is_default',
        'is_editable',
        'is_deletable',
        'is_active',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_default' => 'boolean',
        'is_editable' => 'boolean',
        'is_deletable' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(User::class, 'company_id');
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'company_role_permission')
            ->withTimestamps();
    }

    public function teamMembers(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    public function scopeAvailableForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where(function (Builder $roleQuery) use ($companyId): void {
            $roleQuery->where('company_id', $companyId)
                ->orWhere(function (Builder $systemRoleQuery): void {
                    $systemRoleQuery->whereNull('company_id')
                        ->where('is_system', true);
                });
        });
    }

    public function isSystem(): bool
    {
        return (bool) $this->is_system;
    }

    public function isEditable(): bool
    {
        return (bool) $this->is_editable;
    }

    public function isDeletable(): bool
    {
        return (bool) $this->is_deletable;
    }

    public function isAvailableForCompany(int $companyId): bool
    {
        return (int) $this->company_id === $companyId
            || ($this->company_id === null && $this->isSystem());
    }
}
