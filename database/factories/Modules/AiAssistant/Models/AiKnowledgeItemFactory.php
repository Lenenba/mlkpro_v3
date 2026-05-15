<?php

namespace Database\Factories\Modules\AiAssistant\Models;

use App\Models\User;
use App\Modules\AiAssistant\Models\AiKnowledgeItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiKnowledgeItem>
 */
class AiKnowledgeItemFactory extends Factory
{
    protected $model = AiKnowledgeItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => User::factory(),
            'title' => $this->faker->sentence(4),
            'content' => $this->faker->paragraph(),
            'category' => 'faq',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
