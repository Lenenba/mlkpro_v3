<?php

namespace App\Models;

use App\Enums\CurrencyCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InvoiceItem extends Model
{
    /** @use HasFactory<\Database\Factories\InvoiceItemFactory> */
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'task_id',
        'work_id',
        'assigned_team_member_id',
        'title',
        'description',
        'scheduled_date',
        'start_time',
        'end_time',
        'assignee_name',
        'task_status',
        'quantity',
        'unit_price',
        'currency_code',
        'total',
        'meta',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'meta' => 'array',
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'currency_code' => 'string',
        'total' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $item) {
            if ($item->currency_code || ! $item->invoice_id) {
                $item->currency_code = $item->currency_code ?: CurrencyCode::default()->value;
                return;
            }

            $item->currency_code = Invoice::query()
                ->whereKey($item->invoice_id)
                ->value('currency_code') ?: CurrencyCode::default()->value;
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class, 'assigned_team_member_id');
    }
}
