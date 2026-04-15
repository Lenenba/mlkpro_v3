<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'source_domain',
        'source_key',
        'debit_account_id',
        'credit_account_id',
        'tax_account_id',
        'is_system',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'debit_account_id' => 'integer',
        'credit_account_id' => 'integer',
        'tax_account_id' => 'integer',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'debit_account_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'credit_account_id');
    }

    public function taxAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'tax_account_id');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
