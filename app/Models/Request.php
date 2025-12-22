<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Request extends Model
{
    use HasFactory;

    public const STATUS_NEW = 'REQ_NEW';
    public const STATUS_CONVERTED = 'REQ_CONVERTED';

    protected $fillable = [
        'user_id',
        'customer_id',
        'external_customer_id',
        'channel',
        'status',
        'service_type',
        'urgency',
        'title',
        'description',
        'contact_name',
        'contact_email',
        'contact_phone',
        'country',
        'state',
        'city',
        'street1',
        'street2',
        'postal_code',
        'lat',
        'lng',
        'is_serviceable',
        'converted_at',
        'meta',
    ];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'is_serviceable' => 'boolean',
        'converted_at' => 'datetime',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function quote(): HasOne
    {
        return $this->hasOne(Quote::class);
    }

    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}

