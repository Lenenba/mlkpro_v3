<?php

namespace Database\Factories;

use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TeamMember>
 */
class TeamMemberFactory extends Factory
{
    protected $model = TeamMember::class;

    public function definition(): array
    {
        return [
            'account_id' => User::factory(),
            'user_id' => User::factory(),
            'role' => $this->faker->randomElement(['admin', 'member', 'sales_manager']),
            'title' => $this->faker->jobTitle(),
            'phone' => $this->faker->phoneNumber(),
            'permissions' => ['jobs.view', 'jobs.edit', 'tasks.view', 'tasks.edit'],
            'planning_rules' => null,
            'is_active' => true,
        ];
    }
}

