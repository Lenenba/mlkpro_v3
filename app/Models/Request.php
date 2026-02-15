<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Request extends Model
{
    use HasFactory;

    public const STATUS_NEW = 'REQ_NEW';
    public const STATUS_CALL_REQUESTED = 'REQ_CALL_REQUESTED';
    public const STATUS_CONTACTED = 'REQ_CONTACTED';
    public const STATUS_QUALIFIED = 'REQ_QUALIFIED';
    public const STATUS_QUOTE_SENT = 'REQ_QUOTE_SENT';
    public const STATUS_WON = 'REQ_WON';
    public const STATUS_LOST = 'REQ_LOST';
    public const STATUS_CONVERTED = 'REQ_CONVERTED';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_CALL_REQUESTED,
        self::STATUS_CONTACTED,
        self::STATUS_QUALIFIED,
        self::STATUS_QUOTE_SENT,
        self::STATUS_WON,
        self::STATUS_LOST,
    ];

    protected $fillable = [
        'user_id',
        'customer_id',
        'assigned_team_member_id',
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
        'status_updated_at',
        'next_follow_up_at',
        'lost_reason',
        'meta',
    ];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'is_serviceable' => 'boolean',
        'converted_at' => 'datetime',
        'status_updated_at' => 'datetime',
        'next_follow_up_at' => 'datetime',
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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class, 'assigned_team_member_id');
    }

    public function quote(): HasOne
    {
        return $this->hasOne(Quote::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(LeadNote::class, 'request_id')->latest('created_at');
    }

    public function media(): HasMany
    {
        return $this->hasMany(LeadMedia::class, 'request_id')->latest('created_at');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'request_id')->latest('created_at');
    }

    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
