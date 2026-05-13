<?php

namespace App\Modules\AiAssistant\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAssistantSetting extends Model
{
    use HasFactory;

    public const TONE_PROFESSIONAL = 'professional';

    public const TONE_WARM = 'warm';

    public const TONE_FRIENDLY = 'friendly';

    public const TONE_PREMIUM = 'premium';

    public const TONE_DIRECT = 'direct';

    public const LANGUAGE_FR = 'fr';

    public const LANGUAGE_EN = 'en';

    protected $fillable = [
        'tenant_id',
        'assistant_name',
        'enabled',
        'default_language',
        'supported_languages',
        'tone',
        'greeting_message',
        'fallback_message',
        'allow_create_prospect',
        'allow_create_client',
        'allow_create_reservation',
        'allow_reschedule_reservation',
        'allow_create_task',
        'require_human_validation',
        'business_context',
        'service_area_rules',
        'working_hours_rules',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'supported_languages' => 'array',
        'allow_create_prospect' => 'boolean',
        'allow_create_client' => 'boolean',
        'allow_create_reservation' => 'boolean',
        'allow_reschedule_reservation' => 'boolean',
        'allow_create_task' => 'boolean',
        'require_human_validation' => 'boolean',
        'service_area_rules' => 'array',
        'working_hours_rules' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * @return array<int, string>
     */
    public static function tones(): array
    {
        return [
            self::TONE_PROFESSIONAL,
            self::TONE_WARM,
            self::TONE_FRIENDLY,
            self::TONE_PREMIUM,
            self::TONE_DIRECT,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function languages(): array
    {
        return [
            self::LANGUAGE_FR,
            self::LANGUAGE_EN,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultsFor(User $tenant): array
    {
        $businessName = $tenant->company_name ?: $tenant->name ?: 'Malikia Pro';

        return [
            'tenant_id' => (int) $tenant->id,
            'assistant_name' => 'Malikia AI Assistant',
            'enabled' => false,
            'default_language' => self::LANGUAGE_FR,
            'supported_languages' => [self::LANGUAGE_FR, self::LANGUAGE_EN],
            'tone' => self::TONE_WARM,
            'greeting_message' => "Bonjour, je suis l'assistant virtuel de {$businessName}. Comment puis-je vous aider?",
            'fallback_message' => "Je vais transmettre votre demande a l'equipe pour verification.",
            'allow_create_prospect' => true,
            'allow_create_client' => false,
            'allow_create_reservation' => true,
            'allow_reschedule_reservation' => false,
            'allow_create_task' => false,
            'require_human_validation' => true,
            'business_context' => $tenant->company_description,
            'service_area_rules' => null,
            'working_hours_rules' => null,
        ];
    }

    public static function firstOrCreateForTenant(User $tenant): self
    {
        $defaults = self::defaultsFor($tenant);

        return self::query()->firstOrCreate(
            ['tenant_id' => (int) $tenant->id],
            $defaults
        );
    }
}
