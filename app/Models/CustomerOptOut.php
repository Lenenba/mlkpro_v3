<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerOptOut extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'customer_id',
        'channel',
        'destination',
        'destination_hash',
        'reason',
        'source',
        'opted_out_at',
        'metadata',
    ];

    protected $casts = [
        'opted_out_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
