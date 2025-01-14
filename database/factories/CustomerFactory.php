<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(), // Generates an associated user
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'company_name' => $this->faker->company(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'description' => $this->faker->sentence(),
            'logo' => $this->generateFakeCompanyLogo(),
            'billing_same_as_physical' => $this->faker->boolean(),
            'refer_by' => $this->faker->name(), // Generates a reference name
            'salutation' => $this->faker->randomElement(['Mr', 'Mrs', 'Miss']),
        ];
    }

    /**
     * Generate a fake company logo URL using One API Pro Placeholder Image Generator.
     *
     * @return string
     */
    private function generateFakeCompanyLogo(): string
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
}
