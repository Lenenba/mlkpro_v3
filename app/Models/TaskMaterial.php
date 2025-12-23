<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskMaterial extends Model
{
    protected $fillable = [
        'task_id',
        'product_id',
        'source_service_id',
        'label',
        'description',
        'unit',
        'quantity',
        'unit_price',
        'billable',
        'sort_order',
        'stock_moved_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'billable' => 'boolean',
        'sort_order' => 'integer',
        'stock_moved_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function sourceService(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'source_service_id');
    }
}
