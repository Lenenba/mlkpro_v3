<?php

namespace App\Models;

use App\Enums\CampaignChannel as CampaignChannelEnum;
use App\Enums\CampaignLanguageMode;
use App\Enums\CampaignOfferMode;
use App\Enums\CampaignType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use HasFactory, SoftDeletes;

    public const CHANNEL_EMAIL = CampaignChannelEnum::EMAIL->value;
    public const CHANNEL_SMS = CampaignChannelEnum::SMS->value;
    public const CHANNEL_IN_APP = CampaignChannelEnum::IN_APP->value;
    public const CHANNEL_WHATSAPP = CampaignChannelEnum::WHATSAPP->value;

    public const TYPE_NEW_OFFER = CampaignType::NEW_OFFER->value;
    public const TYPE_BACK_AVAILABLE = CampaignType::BACK_AVAILABLE->value;
    public const TYPE_PROMOTION = CampaignType::PROMOTION->value;
    public const TYPE_CROSS_SELL = CampaignType::CROSS_SELL->value;
    public const TYPE_WINBACK = CampaignType::WINBACK->value;
    public const TYPE_ANNOUNCEMENT = CampaignType::ANNOUNCEMENT->value;

    public const TYPE_NEW_PRODUCT = self::TYPE_NEW_OFFER;
    public const TYPE_BACK_IN_STOCK = self::TYPE_BACK_AVAILABLE;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';

    public const SCHEDULE_MANUAL = 'manual';
    public const SCHEDULE_SCHEDULED = 'scheduled';
    public const SCHEDULE_AUTOMATION = 'automation';

    public const OFFER_MODE_PRODUCTS = CampaignOfferMode::PRODUCTS->value;
    public const OFFER_MODE_SERVICES = CampaignOfferMode::SERVICES->value;
    public const OFFER_MODE_MIXED = CampaignOfferMode::MIXED->value;

    public const LANGUAGE_MODE_PREFERRED = CampaignLanguageMode::PREFERRED->value;
    public const LANGUAGE_MODE_FR = CampaignLanguageMode::FR->value;
    public const LANGUAGE_MODE_EN = CampaignLanguageMode::EN->value;
    public const LANGUAGE_MODE_BOTH = CampaignLanguageMode::BOTH->value;

    public const DIRECTION_CUSTOMER_MARKETING = 'customer_marketing';
    public const DIRECTION_PROSPECTING_OUTBOUND = 'prospecting_outbound';
    public const DIRECTION_LEAD_GENERATION_INBOUND = 'lead_generation_inbound';

    protected $fillable = [
        'user_id',
        'created_by_user_id',
        'updated_by_user_id',
        'audience_segment_id',
        'name',
        'campaign_type',
        'campaign_direction',
        'prospecting_enabled',
        'offer_mode',
        'language_mode',
        'type',
        'status',
        'schedule_type',
        'scheduled_at',
        'started_at',
        'completed_at',
        'locale',
        'cta_url',
        'is_marketing',
        'last_run_at',
        'settings',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_run_at' => 'datetime',
        'is_marketing' => 'boolean',
        'prospecting_enabled' => 'boolean',
        'settings' => 'array',
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

    public function audienceSegment(): BelongsTo
    {
        return $this->belongsTo(AudienceSegment::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'campaign_product')
            ->withPivot('metadata')
            ->withTimestamps();
    }

    public function offers(): HasMany
    {
        return $this->hasMany(CampaignOffer::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(CampaignChannel::class);
    }

    public function audience(): HasOne
    {
        return $this->hasOne(CampaignAudience::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(CampaignRun::class);
    }

    public function recipients()
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CampaignEvent::class);
    }

    public function automationRules(): HasMany
    {
        return $this->hasMany(CampaignAutomationRule::class);
    }

    public function prospectBatches(): HasMany
    {
        return $this->hasMany(CampaignProspectBatch::class);
    }

    public function prospects(): HasMany
    {
        return $this->hasMany(CampaignProspect::class);
    }

    public function prospectActivities(): HasMany
    {
        return $this->hasMany(CampaignProspectActivity::class);
    }

    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public static function allowedTypes(): array
    {
        return CampaignType::values();
    }

    public static function allowedChannels(): array
    {
        return CampaignChannelEnum::values();
    }

    public static function allowedOfferModes(): array
    {
        return CampaignOfferMode::values();
    }

    public static function allowedLanguageModes(): array
    {
        return CampaignLanguageMode::values();
    }

    public static function allowedDirections(): array
    {
        return [
            self::DIRECTION_CUSTOMER_MARKETING,
            self::DIRECTION_PROSPECTING_OUTBOUND,
            self::DIRECTION_LEAD_GENERATION_INBOUND,
        ];
    }

    public function resolvedCampaignType(): string
    {
        return (string) ($this->campaign_type ?: $this->type ?: self::TYPE_PROMOTION);
    }

    public function resolvedCampaignDirection(): string
    {
        return (string) ($this->campaign_direction ?: self::DIRECTION_CUSTOMER_MARKETING);
    }

    public function usesProspecting(): bool
    {
        return (bool) $this->prospecting_enabled;
    }
}
