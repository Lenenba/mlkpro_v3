<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductCategory>
 */
class ProductCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Liste de catégories réalistes
        $categories = [
            'Electronics',
            'Fashion',
            'Home Appliances',
            'Books',
            'Toys',
            'Sports Equipment',
            'Health & Beauty',
            'Automotive',
            'Groceries',
            'Furniture',
            'Jewelry',
            'Movies',
            'Music',
            'Tools',
            'Garden',
            'Baby',
            'Pet Supplies',
            'Office Supplies',
            'Industrial',
            'Software',
            'Hardware',
            'Mobile Phones',
            'Computers',
            'Cameras',
            'Video Games',
            'Watches',
            'Shoes',
            'Clothing',
            'Accessories',
            'Food',
            'Drinks',
        ];

        return [
            'name' => $this->faker->unique()->randomElement($categories), // Catégorie réaliste unique
        ];
    }
}
