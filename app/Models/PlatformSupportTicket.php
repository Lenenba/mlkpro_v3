<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformSupportTicket extends Model
{
    /** @use HasFactory<\Database\Factories\PlatformSupportTicketFactory> */
    use HasFactory;

    protected $fillable = [
        'account_id',
        'created_by_user_id',
        'title',
        'description',
        'status',
        'priority',
        'sla_due_at',
        'tags',
    ];

    protected $casts = [
        'tags' => 'array',
        'sla_due_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
