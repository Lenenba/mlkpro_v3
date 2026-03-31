<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemoWorkspaceTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'created_by_user_id',
        'name',
        'description',
        'company_type',
        'company_sector',
        'seed_profile',
        'team_size',
        'locale',
        'timezone',
        'expiration_days',
        'selected_modules',
        'scenario_packs',
        'branding_profile',
        'suggested_flow',
        'is_active',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'selected_modules' => 'array',
            'scenario_packs' => 'array',
            'branding_profile' => 'array',
            'team_size' => 'integer',
            'expiration_days' => 'integer',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(DemoWorkspace::class, 'demo_workspace_template_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
