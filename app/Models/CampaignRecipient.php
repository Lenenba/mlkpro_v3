<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CampaignRecipient extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_OPENED = 'opened';
    public const STATUS_CLICKED = 'clicked';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'campaign_run_id',
        'campaign_id',
        'user_id',
        'customer_id',
        'channel',
        'destination',
        'destination_hash',
        'dedupe_key',
        'status',
        'provider',
        'provider_message_id',
        'tracking_token',
        'unsubscribe_token',
        'queued_at',
        'sent_at',
        'delivered_at',
        'opened_at',
        'clicked_at',
        'converted_at',
        'failed_at',
        'failure_reason',
        'metadata',
    ];

    protected $casts = [
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
        'converted_at' => 'datetime',
        'failed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(CampaignRun::class, 'campaign_run_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function message(): HasOne
    {
        return $this->hasOne(CampaignMessage::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CampaignEvent::class);
    }

    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public static function destinationHash(?string $destination): ?string
    {
        $value = trim((string) $destination);
        if ($value === '') {
            return null;
        }

        return hash('sha256', strtolower($value));
    }
}
