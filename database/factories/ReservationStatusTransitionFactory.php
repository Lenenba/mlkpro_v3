<?php

namespace Database\Factories;

use App\Models\Reservation;
use App\Models\ReservationStatusTransition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReservationStatusTransition>
 */
class ReservationStatusTransitionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => User::factory(),
            'reservation_id' => function (array $attributes): int {
                return Reservation::factory()->create([
                    'account_id' => $attributes['account_id'],
                    'status' => Reservation::STATUS_CONFIRMED,
                ])->id;
            },
            'event_type' => ReservationStatusTransition::EVENT_STATUS_CHANGED,
            'from_status' => Reservation::STATUS_PENDING,
            'to_status' => Reservation::STATUS_CONFIRMED,
            'actor_type' => ReservationStatusTransition::ACTOR_SYSTEM,
            'actor_user_id' => null,
            'source' => Reservation::STATUS_CHANGE_SOURCE_SCHEDULED_RECONCILIATION,
            'reason_code' => 'factory_transition',
            'reason' => null,
            'status_version' => 1,
            'schedule_version' => 1,
            'idempotency_key' => hash('sha256', (string) Str::uuid()),
            'metadata' => null,
            'occurred_at' => now('UTC'),
        ];
    }
}
