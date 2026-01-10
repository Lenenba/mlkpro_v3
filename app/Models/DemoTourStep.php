<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DemoTourStep extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'title',
        'description',
        'route_name',
        'selector',
        'placement',
        'order_index',
        'payload_json',
        'is_required',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'is_required' => 'boolean',
        'order_index' => 'integer',
    ];
}
