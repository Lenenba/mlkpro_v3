<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MegaMenuBlock extends Model
{
    use HasFactory;

    protected $fillable = [
        'mega_menu_column_id',
        'type',
        'title',
        'css_classes',
        'payload',
        'settings',
        'sort_order',
    ];

    protected $casts = [
        'payload' => 'array',
        'settings' => 'array',
        'sort_order' => 'integer',
    ];

    public function column(): BelongsTo
    {
        return $this->belongsTo(MegaMenuColumn::class, 'mega_menu_column_id');
    }
}
