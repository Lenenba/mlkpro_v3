<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MegaMenuItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'mega_menu_id',
        'parent_id',
        'label',
        'description',
        'link_type',
        'link_value',
        'link_target',
        'panel_type',
        'icon',
        'badge_text',
        'badge_variant',
        'is_visible',
        'css_classes',
        'settings',
        'sort_order',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'settings' => 'array',
        'sort_order' => 'integer',
    ];

    public function menu(): BelongsTo
    {
        return $this->belongsTo(MegaMenu::class, 'mega_menu_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function columns(): HasMany
    {
        return $this->hasMany(MegaMenuColumn::class)->orderBy('sort_order');
    }
}
