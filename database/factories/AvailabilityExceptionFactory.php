<?php

namespace Database\Factories;

use App\Models\AvailabilityException;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AvailabilityException>
 */
class AvailabilityExceptionFactory extends Factory
{
    protected $model = AvailabilityException::class;

    public function definition(): array
    {
        return [
            'account_id' => User::factory(),
            'team_member_id' => function (array $attributes) {
                return TeamMember::factory()->create([
                    'account_id' => $attributes['account_id'],
                ])->id;
            },
            'date' => $this->faker->dateTimeBetween('now', '+30 days')->format('Y-m-d'),
            'start_time' => null,
            'end_time' => null,
            'type' => AvailabilityException::TYPE_CLOSED,
            'reason' => $this->faker->optional()->sentence(4),
        ];
    }
}

