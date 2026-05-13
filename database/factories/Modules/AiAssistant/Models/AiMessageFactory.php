<?php

namespace Database\Factories\Modules\AiAssistant\Models;

use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Models\AiMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiMessage>
 */
class AiMessageFactory extends Factory
{
    protected $model = AiMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => AiConversation::factory(),
            'sender_type' => AiMessage::SENDER_USER,
            'content' => $this->faker->sentence(),
            'payload' => [],
        ];
    }

    public function forConversation(AiConversation $conversation): static
    {
        return $this->state(fn (array $attributes): array => [
            'conversation_id' => (int) $conversation->id,
        ]);
    }

    public function assistant(): static
    {
        return $this->state(fn (array $attributes): array => [
            'sender_type' => AiMessage::SENDER_ASSISTANT,
        ]);
    }
}
