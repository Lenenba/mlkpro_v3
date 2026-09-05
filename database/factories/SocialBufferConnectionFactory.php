<?php

namespace Database\Factories;

use App\Models\SocialBufferConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialBufferConnection>
 */
class SocialBufferConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'buffer_account_id' => 'buffer-account-'.$this->faker->unique()->numerify('#####'),
            'buffer_account_name' => $this->faker->company(),
            'access_token' => 'buffer-access-token-'.$this->faker->uuid(),
            'refresh_token' => 'buffer-refresh-token-'.$this->faker->uuid(),
            'token_type' => 'Bearer',
            'scopes' => ['account:read', 'offline_access'],
            'token_expires_at' => now()->addHour(),
            'connected_at' => now(),
            'last_refreshed_at' => null,
            'oauth_state' => null,
            'oauth_code_verifier' => null,
            'oauth_state_expires_at' => null,
            'last_error' => null,
        ];
    }
}
