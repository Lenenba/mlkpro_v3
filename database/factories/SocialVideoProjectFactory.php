<?php

namespace Database\Factories;

use App\Models\SocialVideoProject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialVideoProject>
 */
class SocialVideoProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => \App\Models\User::factory(),
            'name' => fake()->sentence(3),
            'source_path' => 'social/videos/'.fake()->uuid().'/source.mp4',
            'size' => 1024,
            'duration_ms' => 125000,
            'width' => 1920,
            'height' => 1080,
            'status' => 'ready',
        ];
    }
}
