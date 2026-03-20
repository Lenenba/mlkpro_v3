<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MegaMenuColumn extends Model
{
    use HasFactory;

    protected $fillable = [
        'mega_menu_item_id',
        'title',
        'width',
        'css_classes',
        'settings',
        'sort_order',
    ];

    protected $casts = [
        'settings' => 'array',
        'sort_order' => 'integer',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(MegaMenuItem::class, 'mega_menu_item_id');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(MegaMenuBlock::class)->orderBy('sort_order');
    }
}
