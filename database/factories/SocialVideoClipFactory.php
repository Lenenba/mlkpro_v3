<?php

namespace Database\Factories;

use App\Models\SocialVideoClip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialVideoClip>
 */
class SocialVideoClipFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'social_video_project_id' => \App\Models\SocialVideoProject::factory(),
            'position' => 1,
            'start_ms' => 0,
            'end_ms' => 30000,
            'format' => 'portrait',
            'framing' => 'crop',
        ];
    }
}
