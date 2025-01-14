<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuoteFactory extends Factory
{
    protected $model = \App\Models\Quote::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'user_id' => User::factory(), // Utilisateur par défaut
            'customer_id' => Customer::factory(), // Génère un client associé
            'property_id' => Property::factory(), // Génère une propriété associée
            'total' => $this->faker->randomFloat(2, 100, 10000), // Total aléatoire entre 100 et 10 000
            'initial_deposit' => $this->faker->randomFloat(2, 50, 500), // Dépôt initial aléatoire entre 50 et 500
            'is_fixed' => $this->faker->boolean(), // Devis fixe ou non
            'notes' => $this->faker->sentence(), // Notes aléatoires
        ];
    }
}
