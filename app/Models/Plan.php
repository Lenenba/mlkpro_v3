<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
        'contact_only',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'contact_only' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class)->orderBy('currency_code');
    }
}
