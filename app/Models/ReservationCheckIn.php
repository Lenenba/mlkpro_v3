<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationCheckIn extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'reservation_queue_item_id',
        'reservation_id',
        'client_user_id',
        'checked_in_by_user_id',
        'channel',
        'checked_in_at',
        'grace_deadline_at',
        'metadata',
    ];

    protected $casts = [
        'checked_in_at' => 'datetime',
        'grace_deadline_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_id');
    }

    public function queueItem(): BelongsTo
    {
        return $this->belongsTo(ReservationQueueItem::class, 'reservation_queue_item_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by_user_id');
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }
}

