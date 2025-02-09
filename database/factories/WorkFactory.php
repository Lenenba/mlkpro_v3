<?php

namespace Database\Factories;

use App\Models\Work;
use App\Models\User;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Arr;

class WorkFactory extends Factory
{
    protected $model = Work::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(), // Génère un utilisateur aléatoire
            'customer_id' => Customer::factory(), // Génère un client aléatoire
            'job_title' => $this->faker->sentence(3),
            'instructions' => $this->faker->paragraph(),
            'start_date' => $this->faker->date(),
            'end_date' => $this->faker->optional()->date(),
            'start_time' => $this->faker->time(),
            'end_time' => $this->faker->optional()->time(),
            'is_all_day' => $this->faker->boolean(),
            'later' => $this->faker->boolean(),
            'ends' => Arr::random(['Never', 'After', 'On']),
            'frequencyNumber' => $this->faker->numberBetween(1, 10),
            'frequency' => Arr::random(['Daily', 'Weekly', 'Monthly']),
            'totalVisits' => $this->faker->numberBetween(1, 50),
            'repeatsOn' => json_encode(Arr::random(['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'], rand(1, 3))),
            'type' => $this->faker->word(),
            'category' => $this->faker->word(),
            'is_completed' => $this->faker->boolean(),
            'subtotal' => $this->faker->randomFloat(2, 10, 1000),
            'total' => $this->faker->randomFloat(2, 10, 2000),
        ];
    }
}
