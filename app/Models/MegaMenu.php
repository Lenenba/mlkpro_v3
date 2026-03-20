<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MegaMenu extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'status',
        'display_location',
        'custom_zone',
        'description',
        'css_classes',
        'ordering',
        'settings',
        'created_by',
        'updated_by',
        'published_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'ordering' => 'integer',
        'published_at' => 'datetime',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MegaMenuItem::class)
            ->whereNull('parent_id')
            ->orderBy('sort_order');
    }

    public function allItems(): HasMany
    {
        return $this->hasMany(MegaMenuItem::class)->orderBy('sort_order');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
