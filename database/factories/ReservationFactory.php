<?php

namespace Database\Factories;

use App\Models\Reservation;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Reservation>
 */
class ReservationFactory extends Factory
{
    protected $model = Reservation::class;

    public function definition(): array
    {
        $startsAt = $this->faker->dateTimeBetween('now', '+30 days');
        $endsAt = (clone $startsAt)->modify('+1 hour');

        return [
            'account_id' => User::factory(),
            'team_member_id' => function (array $attributes) {
                return TeamMember::factory()->create([
                    'account_id' => $attributes['account_id'],
                ])->id;
            },
            'client_id' => null,
            'client_user_id' => null,
            'service_id' => null,
            'created_by_user_id' => null,
            'status' => $this->faker->randomElement(Reservation::STATUSES),
            'source' => $this->faker->randomElement([
                Reservation::SOURCE_STAFF,
                Reservation::SOURCE_CLIENT,
                Reservation::SOURCE_API,
            ]),
            'timezone' => 'UTC',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'internal_notes' => $this->faker->optional()->sentence(),
            'client_notes' => $this->faker->optional()->sentence(),
            'cancelled_at' => null,
            'cancel_reason' => null,
            'cancelled_by_user_id' => null,
            'rescheduled_from_id' => null,
            'metadata' => [],
        ];
    }
}
