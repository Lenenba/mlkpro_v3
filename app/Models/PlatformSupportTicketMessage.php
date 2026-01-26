<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformSupportTicketMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'body',
        'is_internal',
        'meta',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
        'meta' => 'array',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(PlatformSupportTicket::class, 'ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
