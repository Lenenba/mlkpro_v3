<?php

namespace Database\Factories;

use App\Models\ProductWork;
use App\Models\Work;
use App\Models\Product;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductWorkFactory extends Factory
{
    protected $model = ProductWork::class;

    public function definition(): array
    {
        return [
            'work_id' => Work::factory(),
            'product_id' => Product::factory(),
            'quote_id' => $this->faker->optional()->randomDigitNotNull(),
            'quantity' => $this->faker->numberBetween(1, 10),
            'price' => $this->faker->randomFloat(2, 5, 500),
            'description' => $this->faker->sentence(),
            'total' => function (array $attributes) {
                return $attributes['quantity'] * $attributes['price'];
            },
        ];
    }
}
