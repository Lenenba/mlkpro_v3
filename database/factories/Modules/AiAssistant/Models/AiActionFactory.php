<?php

namespace Database\Factories\Modules\AiAssistant\Models;

use App\Models\User;
use App\Modules\AiAssistant\Models\AiAction;
use App\Modules\AiAssistant\Models\AiConversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiAction>
 */
class AiActionFactory extends Factory
{
    protected $model = AiAction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => User::factory(),
            'conversation_id' => AiConversation::factory(),
            'action_type' => AiAction::TYPE_REQUEST_HUMAN_REVIEW,
            'status' => AiAction::STATUS_PENDING,
            'input_payload' => [
                'reason' => 'factory',
            ],
            'output_payload' => null,
            'error_message' => null,
            'executed_at' => null,
        ];
    }

    public function forConversation(AiConversation $conversation): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => (int) $conversation->tenant_id,
            'conversation_id' => (int) $conversation->id,
        ]);
    }

    public function executed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AiAction::STATUS_EXECUTED,
            'executed_at' => now(),
        ]);
    }
}
