<?php

namespace App\Models;

use App\Enums\CurrencyCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PettyCashAccount extends Model
{
    protected $fillable = [
        'user_id',
        'responsible_user_id',
        'name',
        'currency_code',
        'opening_balance',
        'current_balance',
        'low_balance_threshold',
        'receipt_required_above',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'low_balance_threshold' => 'decimal:2',
        'receipt_required_above' => 'decimal:2',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (PettyCashAccount $account) {
            if (! $account->currency_code) {
                $account->currency_code = CurrencyCode::default()->value;
            }

            if (! $account->name) {
                $account->name = 'Petite caisse';
            }
        });
    }

    public function scopeByAccount($query, int $accountId)
    {
        return $query->where('user_id', $accountId);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(PettyCashMovement::class);
    }

    public function closures(): HasMany
    {
        return $this->hasMany(PettyCashClosure::class);
    }
}
