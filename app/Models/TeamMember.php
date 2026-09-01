<?php

namespace App\Models;

use App\Services\Rbac\PermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TeamMember extends Model
{
    /** @use HasFactory<\Database\Factories\TeamMemberFactory> */
    use HasFactory;

    protected $fillable = [
        'account_id',
        'user_id',
        'role',
        'company_role_id',
        'title',
        'phone',
        'permissions',
        'planning_rules',
        'is_active',
    ];

    protected $casts = [
        'permissions' => 'array',
        'planning_rules' => 'array',
        'is_active' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function companyRole(): BelongsTo
    {
        return $this->belongsTo(CompanyRole::class);
    }

    public function works(): BelongsToMany
    {
        return $this->belongsToMany(Work::class, 'work_team_members')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function weeklyAvailabilities(): HasMany
    {
        return $this->hasMany(WeeklyAvailability::class);
    }

    public function availabilityExceptions(): HasMany
    {
        return $this->hasMany(AvailabilityException::class);
    }

    public function reservationSetting(): HasOne
    {
        return $this->hasOne(ReservationSetting::class);
    }

    public function reservationResources(): HasMany
    {
        return $this->hasMany(ReservationResource::class);
    }

    public function reservationWaitlists(): HasMany
    {
        return $this->hasMany(ReservationWaitlist::class);
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = $this->resolvedPermissions();

        foreach (app(PermissionCatalog::class)->candidates($permission) as $candidate) {
            if (in_array($candidate, $permissions, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    public function resolvedPermissions(): array
    {
        if ($this->company_role_id) {
            $role = $this->relationLoaded('companyRole')
                ? $this->companyRole
                : $this->companyRole()->with('permissions')->first();

            if (! $role || ! $role->is_active) {
                return [];
            }

            $role->loadMissing('permissions');
            $permissions = $role->permissions->pluck('slug')->all();
        } else {
            $permissions = is_array($this->permissions) ? $this->permissions : [];
        }

        return array_values(array_unique(array_filter(
            $permissions,
            fn (mixed $permission): bool => is_string($permission) && $permission !== ''
        )));
    }
}
