<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(), // Fake name
            'email' => $this->faker->unique()->safeEmail(), // Unique email
            'email_verified_at' => now(),
            'password' => 'password', // Default password
            'remember_token' => Str::random(10),
            'role_id' => Role::query()->firstOrCreate(
                ['name' => 'client'],
                ['description' => 'Default client role']
            )->id,
            'profile_picture' => null,
            'phone_number' => $this->faker->e164PhoneNumber(), // Random phone number
            'company_name' => $this->faker->company(),
            'company_logo' => null,
            'company_description' => $this->faker->sentence(12),
            'company_country' => $this->faker->country(),
            'company_province' => $this->faker->state(),
            'company_city' => $this->faker->city(),
            'company_type' => $this->faker->randomElement(['services', 'products']),
            'onboarding_completed_at' => now(),
            'payment_methods' => ['cash', 'card'],
        ];
    }

    /**
     * Assign a specific role to the user.
     *
     * @param int $roleId
     */
    public function withRole(int $roleId): static
    {
        return $this->state(fn (array $attributes) => [
            'role_id' => $roleId,
        ]);
    }

    /**
     * Indicate that the user's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
