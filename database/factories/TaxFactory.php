<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class TaxFactory extends Factory
{
    protected $model = \App\Models\Tax::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'name' => $this->faker->unique()->randomElement(['TPS', 'TVQ', 'GST', 'PST']), // Nom aléatoire pour une taxe
            'rate' => $this->faker->randomFloat(2, 1, 15), // Taux aléatoire entre 1% et 15%
        ];
    }
}
