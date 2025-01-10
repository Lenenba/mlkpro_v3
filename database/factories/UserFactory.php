<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(), // Fake name
            'email' => $this->faker->unique()->safeEmail(), // Unique email
            'email_verified_at' => now(),
            'password' => 'password', // Default password
            'remember_token' => Str::random(10),
            'role_id' => 4, // Default role (client)
            'profile_picture' => $this->getRandomUnsplashPhoto(), // Photo from Unsplash
            'phone_number' => $this->faker->e164PhoneNumber(), // Random phone number
        ];
    }

    /**
     * Get a random photo URL from Unsplash.
     *
     * @return string|null
     */
    private function getRandomUnsplashPhoto(): ?string
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Client-ID ' . env('UNSPLASH_ACCESS_KEY'),
            ])->get('https://api.unsplash.com/photos/random', [
                'query' => 'person',
                'orientation' => 'squarish',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['urls']['regular'] ?? null; // Use the 'regular' size
            }

            Log::error('Unsplash API error: ' . $response->body());
        } catch (\Exception $e) {
            Log::error('Unsplash API exception: ' . $e->getMessage());
        }

        return null; // Default to null if an error occurs
    }

    /**
     * Assign a specific role to the user.
     *
     * @param int $roleId
     */
    public function withRole(int $roleId): static
    {
        return $this->state(fn (array $attributes) => [
            'role_id' => $roleId,
        ]);
    }
}
