<?php

namespace Database\Factories\Modules\AiAssistant\Models;

use App\Models\User;
use App\Modules\AiAssistant\Models\AiConversation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiConversation>
 */
class AiConversationFactory extends Factory
{
    protected $model = AiConversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => User::factory(),
            'public_uuid' => (string) Str::uuid(),
            'channel' => AiConversation::CHANNEL_WEB_CHAT,
            'status' => AiConversation::STATUS_OPEN,
            'visitor_name' => $this->faker->name(),
            'visitor_email' => $this->faker->safeEmail(),
            'visitor_phone' => $this->faker->e164PhoneNumber(),
            'client_id' => null,
            'prospect_id' => null,
            'reservation_id' => null,
            'detected_language' => 'fr',
            'intent' => null,
            'confidence_score' => null,
            'summary' => null,
            'metadata' => [],
        ];
    }

    public function waitingHuman(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AiConversation::STATUS_WAITING_HUMAN,
        ]);
    }
}
