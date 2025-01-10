<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Role>
 */
class RoleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->randomElement(['superadmin', 'admin', 'employee', 'client']), // Random role name
            'description' => $this->faker->sentence(), // Random description
        ];
    }

    /**
     * Indicate a specific role name.
     *
     * @param string $roleName
     */
    public function withName(string $roleName): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => $roleName,
        ]);
    }

    /**
     * Indicate a specific role description.
     *
     * @param string $roleDescription
     */
    public function withDescription(string $roleDescription): static
    {
        return $this->state(fn (array $attributes) => [
            'description' => $roleDescription,
        ]);
    }
}
