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
            'business_preset' => 'service_general',
            'buffer_minutes' => 10,
            'slot_interval_minutes' => 30,
            'min_notice_minutes' => 0,
            'max_advance_days' => 90,
            'cancellation_cutoff_hours' => 12,
            'allow_client_cancel' => true,
            'allow_client_reschedule' => true,
            'late_release_minutes' => 0,
            'waitlist_enabled' => false,
            'queue_mode_enabled' => false,
            'queue_dispatch_mode' => 'fifo_with_appointment_priority',
            'queue_grace_minutes' => 5,
            'queue_pre_call_threshold' => 2,
            'queue_no_show_on_grace_expiry' => false,
            'deposit_required' => false,
            'deposit_amount' => 0,
            'no_show_fee_enabled' => false,
            'no_show_fee_amount' => 0,
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
