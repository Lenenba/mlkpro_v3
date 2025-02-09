<?php

namespace Database\Factories;

use App\Models\WorkRating;
use App\Models\Work;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkRatingFactory extends Factory
{
    protected $model = WorkRating::class;

    public function definition(): array
    {
        return [
            'work_id' => Work::factory(),
            'user_id' => User::factory(),
            'rating' => $this->faker->numberBetween(1, 5),
            'feedback' => $this->faker->optional()->paragraph(),
        ];
    }
}
