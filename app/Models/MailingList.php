<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailingList extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'created_by_user_id',
        'updated_by_user_id',
        'name',
        'description',
        'tags',
    ];

    protected $casts = [
        'tags' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function listCustomers(): HasMany
    {
        return $this->hasMany(MailingListCustomer::class);
    }

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'mailing_list_customers')
            ->withPivot(['added_by_user_id', 'added_at'])
            ->withTimestamps();
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('user_id', $accountId);
    }
}

