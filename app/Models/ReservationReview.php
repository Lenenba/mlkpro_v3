<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'reservation_id',
        'account_id',
        'client_id',
        'client_user_id',
        'rating',
        'feedback',
        'reviewed_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'client_id');
    }

    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }
}
