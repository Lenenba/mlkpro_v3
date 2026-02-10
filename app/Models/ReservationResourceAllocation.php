<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationResourceAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'reservation_id',
        'reservation_resource_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(ReservationResource::class, 'reservation_resource_id');
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }
}

