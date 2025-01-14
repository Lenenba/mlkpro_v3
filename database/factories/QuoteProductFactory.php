<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuoteProductFactory extends Factory
{
    protected $model = \App\Models\QuoteProduct::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        $price = $this->faker->randomFloat(2, 10, 1000); // Prix aléatoire entre 10 et 1000
        $quantity = $this->faker->numberBetween(1, 10); // Quantité aléatoire entre 1 et 10

        return [
            'quote_id' => Quote::factory(), // Génère un devis associé
            'product_id' => Product::factory(), // Génère un produit associé
            'quantity' => $quantity,
            'price' => $price,
            'description' => $this->faker->sentence(), // Description aléatoire
            'total' => $price * $quantity, // Calcul du total
        ];
    }
}
