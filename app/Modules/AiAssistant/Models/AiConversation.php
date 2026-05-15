<?php

namespace App\Modules\AiAssistant\Models;

use App\Models\Customer;
use App\Models\Request as LeadRequest;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiConversation extends Model
{
    use HasFactory;

    public const CHANNEL_WEB_CHAT = 'web_chat';

    public const CHANNEL_PUBLIC_RESERVATION = 'public_reservation';

    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_VOICE = 'voice';

    public const STATUS_OPEN = 'open';

    public const STATUS_WAITING_HUMAN = 'waiting_human';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_ABANDONED = 'abandoned';

    public const INTENT_RESERVATION = 'reservation';

    public const INTENT_RESCHEDULE = 'reschedule_reservation';

    public const INTENT_GENERAL = 'general_question';

    public const INTENT_HUMAN_REVIEW = 'human_review';

    protected $fillable = [
        'tenant_id',
        'public_uuid',
        'channel',
        'status',
        'visitor_name',
        'visitor_email',
        'visitor_phone',
        'client_id',
        'prospect_id',
        'reservation_id',
        'detected_language',
        'intent',
        'confidence_score',
        'summary',
        'metadata',
    ];

    protected $casts = [
        'confidence_score' => 'decimal:2',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $conversation): void {
            if (! $conversation->public_uuid) {
                $conversation->public_uuid = (string) Str::uuid();
            }

            if (! $conversation->status) {
                $conversation->status = self::STATUS_OPEN;
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'client_id');
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(LeadRequest::class, 'prospect_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversation_id')->orderBy('created_at')->orderBy('id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AiAction::class, 'conversation_id')->latest();
    }

    public function pendingActions(): HasMany
    {
        return $this->actions()->where('status', AiAction::STATUS_PENDING);
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * @return array<int, string>
     */
    public static function channels(): array
    {
        return [
            self::CHANNEL_WEB_CHAT,
            self::CHANNEL_PUBLIC_RESERVATION,
            self::CHANNEL_SMS,
            self::CHANNEL_EMAIL,
            self::CHANNEL_WHATSAPP,
            self::CHANNEL_VOICE,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_OPEN,
            self::STATUS_WAITING_HUMAN,
            self::STATUS_RESOLVED,
            self::STATUS_ABANDONED,
        ];
    }
}
