<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkChecklistItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'work_id',
        'quote_id',
        'quote_product_id',
        'title',
        'description',
        'status',
        'sort_order',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function quoteProduct(): BelongsTo
    {
        return $this->belongsTo(QuoteProduct::class);
    }
}

