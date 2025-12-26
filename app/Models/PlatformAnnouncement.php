<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class PlatformAnnouncement extends Model
{
    public const STATUSES = ['draft', 'active'];
    public const AUDIENCES = ['all', 'tenants', 'new_tenants'];
    public const PLACEMENTS = ['internal', 'quick_actions'];
    public const DISPLAY_STYLES = ['standard', 'media_only'];
    public const MEDIA_TYPES = ['none', 'image', 'video'];

    protected $fillable = [
        'title',
        'body',
        'status',
        'audience',
        'placement',
        'display_style',
        'background_color',
        'new_tenant_days',
        'media_type',
        'media_url',
        'media_path',
        'link_label',
        'link_url',
        'priority',
        'starts_at',
        'ends_at',
        'created_by',
    ];

    protected $casts = [
        'priority' => 'integer',
        'new_tenant_days' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'platform_announcement_tenants', 'announcement_id', 'tenant_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function getMediaUrlAttribute(): ?string
    {
        $url = $this->attributes['media_url'] ?? null;
        if ($url) {
            return $url;
        }

        $path = $this->attributes['media_path'] ?? null;
        if (!$path) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
