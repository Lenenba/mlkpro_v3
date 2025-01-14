<?php

namespace Database\Factories;

use App\Models\Tax;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuoteTaxFactory extends Factory
{
    protected $model = \App\Models\QuoteTax::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        $rate = $this->faker->randomFloat(2, 1, 15); // Taux aléatoire entre 1% et 15%
        $subtotal = $this->faker->randomFloat(2, 100, 10000); // Sous-total aléatoire entre 100 et 10 000

        return [
            'quote_id' => Quote::factory(), // Associer un devis
            'tax_id' => Tax::factory(), // Associer une taxe par défaut
            'rate' => $rate, // Copier le taux de la taxe
            'amount' => $subtotal * ($rate / 100), // Calculer le montant de la taxe
        ];
    }
}
