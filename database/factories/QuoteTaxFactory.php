<?php

namespace Database\Factories;

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
        $rate = $this->faker->randomElement([5, 9.975, 15]); // Taxes canadiennes : TPS (5%), TVQ (9.975%), ou combiné (15%)
        $subtotal = $this->faker->randomFloat(2, 100, 10000); // Sous-total aléatoire

        return [
            'quote_id' => Quote::factory(), // Génère un devis associé
            'name' => $this->faker->randomElement(['TPS', 'TVQ', 'TPS/TVQ']), // Nom de la taxe
            'rate' => $rate, // Taux de taxe
            'amount' => $subtotal * ($rate / 100), // Montant calculé
        ];
    }
}
