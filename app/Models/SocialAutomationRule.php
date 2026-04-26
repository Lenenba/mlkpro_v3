<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SocialAutomationRule extends Model
{
    use HasFactory;

    public const FREQUENCY_HOURLY = 'hourly';

    public const FREQUENCY_DAILY = 'daily';

    public const FREQUENCY_EVERY_TWO_DAYS = 'every_two_days';

    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_MONTHLY = 'monthly';

    public const APPROVAL_REQUIRED = 'required';

    public const APPROVAL_AUTO_PUBLISH = 'auto_publish';

    public const AI_TONE_PROFESSIONAL = 'professional';

    public const AI_TONE_WARM = 'warm';

    public const AI_TONE_PREMIUM = 'premium';

    public const AI_TONE_DIRECT = 'direct';

    public const AI_TONE_PROMOTIONAL = 'promotional';

    public const AI_GOAL_SELL = 'sell';

    public const AI_GOAL_INFORM = 'inform';

    public const AI_GOAL_BOOK = 'book';

    public const AI_GOAL_ANNOUNCE = 'announce';

    public const AI_GOAL_REENGAGE = 'reengage';

    public const AI_IMAGE_MODE_NEVER = 'never';

    public const AI_IMAGE_MODE_IF_MISSING = 'if_missing';

    public const AI_IMAGE_MODE_ALWAYS = 'always';

    public const AI_IMAGE_FORMAT_AUTO = 'auto';

    public const AI_IMAGE_FORMAT_SQUARE = 'square';

    public const AI_IMAGE_FORMAT_PORTRAIT = 'portrait';

    public const AI_IMAGE_FORMAT_LANDSCAPE = 'landscape';

    protected $fillable = [
        'user_id',
        'created_by_user_id',
        'updated_by_user_id',
        'name',
        'description',
        'is_active',
        'frequency_type',
        'frequency_interval',
        'scheduled_time',
        'timezone',
        'approval_mode',
        'language',
        'content_sources',
        'target_connection_ids',
        'max_posts_per_day',
        'min_hours_between_similar_posts',
        'last_generated_at',
        'next_generation_at',
        'last_error',
        'metadata',
    ];

    protected $casts = [
        'description' => 'string',
        'is_active' => 'boolean',
        'frequency_type' => 'string',
        'frequency_interval' => 'integer',
        'scheduled_time' => 'string',
        'timezone' => 'string',
        'approval_mode' => 'string',
        'language' => 'string',
        'content_sources' => 'array',
        'target_connection_ids' => 'array',
        'max_posts_per_day' => 'integer',
        'min_hours_between_similar_posts' => 'integer',
        'last_generated_at' => 'datetime',
        'next_generation_at' => 'datetime',
        'last_error' => 'string',
        'metadata' => 'array',
    ];

    public static function allowedFrequencyTypes(): array
    {
        return [
            self::FREQUENCY_HOURLY,
            self::FREQUENCY_DAILY,
            self::FREQUENCY_EVERY_TWO_DAYS,
            self::FREQUENCY_WEEKLY,
            self::FREQUENCY_MONTHLY,
        ];
    }

    public static function allowedApprovalModes(): array
    {
        return [
            self::APPROVAL_REQUIRED,
            self::APPROVAL_AUTO_PUBLISH,
        ];
    }

    public static function allowedAiTones(): array
    {
        return [
            self::AI_TONE_PROFESSIONAL,
            self::AI_TONE_WARM,
            self::AI_TONE_PREMIUM,
            self::AI_TONE_DIRECT,
            self::AI_TONE_PROMOTIONAL,
        ];
    }

    public static function allowedAiGoals(): array
    {
        return [
            self::AI_GOAL_SELL,
            self::AI_GOAL_INFORM,
            self::AI_GOAL_BOOK,
            self::AI_GOAL_ANNOUNCE,
            self::AI_GOAL_REENGAGE,
        ];
    }

    public static function allowedAiImageModes(): array
    {
        return [
            self::AI_IMAGE_MODE_NEVER,
            self::AI_IMAGE_MODE_IF_MISSING,
            self::AI_IMAGE_MODE_ALWAYS,
        ];
    }

    public static function allowedAiImageFormats(): array
    {
        return [
            self::AI_IMAGE_FORMAT_AUTO,
            self::AI_IMAGE_FORMAT_SQUARE,
            self::AI_IMAGE_FORMAT_PORTRAIT,
            self::AI_IMAGE_FORMAT_LANDSCAPE,
        ];
    }

    public static function defaultGenerationSettings(): array
    {
        return [
            'text_ai_enabled' => false,
            'image_ai_enabled' => false,
            'creative_prompt' => '',
            'image_prompt' => '',
            'tone' => self::AI_TONE_PROFESSIONAL,
            'goal' => self::AI_GOAL_INFORM,
            'image_mode' => self::AI_IMAGE_MODE_IF_MISSING,
            'image_format' => self::AI_IMAGE_FORMAT_SQUARE,
            'variant_count' => 3,
        ];
    }

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

    public function generatedPosts(): HasMany
    {
        return $this->hasMany(SocialPost::class, 'social_automation_rule_id')->orderByDesc('id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SocialAutomationRun::class, 'social_automation_rule_id')->latest('started_at');
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(SocialAutomationRun::class, 'social_automation_rule_id')->latestOfMany('started_at');
    }

    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeDue(Builder $query, ?string $dateTime = null): Builder
    {
        $resolved = $dateTime ?? now()->toDateTimeString();

        return $query->where(function (Builder $builder) use ($resolved): void {
            $builder
                ->whereNull('next_generation_at')
                ->orWhere('next_generation_at', '<=', $resolved);
        });
    }
}
