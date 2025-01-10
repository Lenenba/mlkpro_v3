<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Http;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Générer un ID aléatoire entre 1 et 100
        $randomId = rand(1, 100);

        // Récupérer un produit avec cet ID depuis DummyJSON
        $dummyResponse = Http::get("https://dummyjson.com/products/{$randomId}")->json();

        return [
            'name' => $dummyResponse['title'], // Nom du produit
            'price' => $dummyResponse['price'], // Prix du produit
            'description' => $dummyResponse['description'], // Description du produit
            'category_id' => ProductCategory::factory(), // Crée une catégorie associée
            'user_id' => User::factory(), // Associe un utilisateur
            'image' => $dummyResponse['thumbnail'], // Image du produit
            'stock' => $dummyResponse['stock'], // Stock réaliste fourni par l'API
            'minimum_stock' => $this->faker->numberBetween(1, 10), // Stock minimum aléatoire
        ];
    }
}
