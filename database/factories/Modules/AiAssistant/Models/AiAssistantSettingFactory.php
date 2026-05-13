<?php

namespace Database\Factories\Modules\AiAssistant\Models;

use App\Models\User;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiAssistantSetting>
 */
class AiAssistantSettingFactory extends Factory
{
    protected $model = AiAssistantSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => User::factory(),
            'assistant_name' => 'Malikia AI Assistant',
            'enabled' => true,
            'default_language' => AiAssistantSetting::LANGUAGE_FR,
            'supported_languages' => [
                AiAssistantSetting::LANGUAGE_FR,
                AiAssistantSetting::LANGUAGE_EN,
            ],
            'tone' => AiAssistantSetting::TONE_WARM,
            'greeting_message' => 'Bonjour, comment puis-je vous aider?',
            'fallback_message' => "Je vais transmettre votre demande a l'equipe.",
            'allow_create_prospect' => true,
            'allow_create_client' => false,
            'allow_create_reservation' => true,
            'allow_reschedule_reservation' => false,
            'allow_create_task' => false,
            'require_human_validation' => true,
            'business_context' => $this->faker->sentence(12),
            'service_area_rules' => null,
            'working_hours_rules' => null,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'enabled' => false,
        ]);
    }
}
