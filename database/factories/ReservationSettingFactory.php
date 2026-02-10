<?php

namespace Database\Factories;

use App\Models\ReservationSetting;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ReservationSetting>
 */
class ReservationSettingFactory extends Factory
{
    protected $model = ReservationSetting::class;

    public function definition(): array
    {
        return [
            'account_id' => User::factory(),
            'team_member_id' => null,
            'buffer_minutes' => 10,
            'slot_interval_minutes' => 30,
            'min_notice_minutes' => 0,
            'max_advance_days' => 90,
            'cancellation_cutoff_hours' => 12,
            'allow_client_cancel' => true,
            'allow_client_reschedule' => true,
        ];
    }

    public function forTeamMember(?TeamMember $teamMember = null): static
    {
        return $this->state(function (array $attributes) use ($teamMember) {
            $resolved = $teamMember ?: TeamMember::factory()->create([
                'account_id' => $attributes['account_id'],
            ]);

            return [
                'team_member_id' => $resolved->id,
            ];
        });
    }
}

