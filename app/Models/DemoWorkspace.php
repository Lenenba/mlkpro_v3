<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DemoWorkspace extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'owner_user_id',
        'created_by_user_id',
        'demo_workspace_template_id',
        'cloned_from_demo_workspace_id',
        'sent_by_user_id',
        'last_reset_by_user_id',
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
        'suggested_flow',
        'selected_modules',
        'scenario_packs',
        'branding_profile',
        'baseline_snapshot',
        'provisioning_status',
        'provisioning_progress',
        'provisioning_stage',
        'provisioning_error',
        'sales_status',
        'prefill_source',
        'prefill_payload',
        'extra_access_roles',
        'extra_access_credentials',
        'configuration',
        'seed_summary',
        'access_email',
        'access_password',
        'expires_at',
        'provisioned_at',
        'last_seeded_at',
        'sent_at',
        'queued_at',
        'provisioning_started_at',
        'provisioning_finished_at',
        'provisioning_failed_at',
        'purged_at',
        'baseline_created_at',
        'last_reset_at',
    ];

    protected function casts(): array
    {
        return [
            'selected_modules' => 'array',
            'scenario_packs' => 'array',
            'branding_profile' => 'array',
            'baseline_snapshot' => 'array',
            'prefill_payload' => 'array',
            'extra_access_roles' => 'array',
            'extra_access_credentials' => 'array',
            'configuration' => 'array',
            'seed_summary' => 'array',
            'access_password' => 'encrypted',
            'expires_at' => 'datetime',
            'provisioned_at' => 'datetime',
            'last_seeded_at' => 'datetime',
            'sent_at' => 'datetime',
            'queued_at' => 'datetime',
            'provisioning_started_at' => 'datetime',
            'provisioning_finished_at' => 'datetime',
            'provisioning_failed_at' => 'datetime',
            'purged_at' => 'datetime',
            'provisioning_progress' => 'integer',
            'baseline_created_at' => 'datetime',
            'last_reset_at' => 'datetime',
            'team_size' => 'integer',
            'deleted_at' => 'datetime',
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

    public function template(): BelongsTo
    {
        return $this->belongsTo(DemoWorkspaceTemplate::class, 'demo_workspace_template_id');
    }

    public function clonedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'cloned_from_demo_workspace_id');
    }

    public function lastResetBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_reset_by_user_id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function activityLogs(): MorphMany
    {
        return $this->morphMany(ActivityLog::class, 'subject');
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

    public function isExpiringSoon(int $days = 3): bool
    {
        if (! $this->expires_at || $this->isExpired()) {
            return false;
        }

        return $this->expires_at->lte(now()->addDays($days));
    }

    public function lifecycleStatus(int $expiringSoonDays = 3): string
    {
        if ($this->trashed() || $this->purged_at) {
            return 'purged';
        }

        $provisioningStatus = strtolower(trim((string) ($this->provisioning_status ?? 'ready')));

        if (in_array($provisioningStatus, ['draft', 'queued', 'provisioning', 'failed'], true)) {
            return $provisioningStatus;
        }

        if ($this->isExpired()) {
            return 'expired';
        }

        if ($this->isExpiringSoon($expiringSoonDays)) {
            return 'expires_soon';
        }

        if ($this->sent_at) {
            return 'sent';
        }

        return 'ready';
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return $query->withTrashed()->where($field ?? $this->getRouteKeyName(), $value);
    }
}
