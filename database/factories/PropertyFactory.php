<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Property>
 */
class PropertyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(), // Génère un client associé si non spécifié
            'type' => $this->faker->randomElement(['physical', 'billing', 'other']), // Type d'adresse
            'country' => $this->faker->country(),
            'street1' => $this->faker->streetAddress(),
            'street2' => $this->faker->optional()->secondaryAddress(), // Génère une adresse secondaire aléatoire ou null
            'city' => $this->faker->city(),
            'state' => $this->faker->state(),
            'zip' => $this->faker->postcode(),
        ];
    }
}
