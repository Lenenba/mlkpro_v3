<?php

namespace Database\Factories;

use App\Models\TeamMember;
use App\Models\WeeklyAvailability;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WeeklyAvailability>
 */
class WeeklyAvailabilityFactory extends Factory
{
    protected $model = WeeklyAvailability::class;

    public function definition(): array
    {
        return [
            'account_id' => User::factory(),
            'team_member_id' => function (array $attributes) {
                return TeamMember::factory()->create([
                    'account_id' => $attributes['account_id'],
                ])->id;
            },
            'day_of_week' => $this->faker->numberBetween(1, 5),
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'is_active' => true,
        ];
    }
}

