<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

class ReservationPresetResolver
{
    public const PRESET_SERVICE_GENERAL = 'service_general';
    public const PRESET_SALON = 'salon';
    public const PRESET_RESTAURANT = 'restaurant';

    public const PRESETS = [
        self::PRESET_SERVICE_GENERAL,
        self::PRESET_SALON,
        self::PRESET_RESTAURANT,
    ];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(string $preset): array
    {
        return match (self::normalizePreset($preset)) {
            self::PRESET_SALON => [
                'business_preset' => self::PRESET_SALON,
                'buffer_minutes' => 10,
                'slot_interval_minutes' => 15,
                'min_notice_minutes' => 60,
                'max_advance_days' => 60,
                'cancellation_cutoff_hours' => 24,
                'allow_client_cancel' => true,
                'allow_client_reschedule' => true,
                'late_release_minutes' => 10,
                'waitlist_enabled' => true,
                'queue_mode_enabled' => true,
                'queue_assignment_mode' => 'per_staff',
                'queue_dispatch_mode' => 'fifo_with_appointment_priority',
                'queue_grace_minutes' => 5,
                'queue_pre_call_threshold' => 2,
                'queue_no_show_on_grace_expiry' => true,
                'deposit_required' => true,
                'deposit_amount' => 20,
                'no_show_fee_enabled' => true,
                'no_show_fee_amount' => 15,
            ],
            self::PRESET_RESTAURANT => [
                'business_preset' => self::PRESET_RESTAURANT,
                'buffer_minutes' => 15,
                'slot_interval_minutes' => 15,
                'min_notice_minutes' => 30,
                'max_advance_days' => 30,
                'cancellation_cutoff_hours' => 6,
                'allow_client_cancel' => true,
                'allow_client_reschedule' => true,
                'late_release_minutes' => 15,
                'waitlist_enabled' => true,
                'queue_mode_enabled' => false,
                'queue_assignment_mode' => 'global_pull',
                'queue_dispatch_mode' => 'fifo_with_appointment_priority',
                'queue_grace_minutes' => 10,
                'queue_pre_call_threshold' => 2,
                'queue_no_show_on_grace_expiry' => true,
                'deposit_required' => true,
                'deposit_amount' => 25,
                'no_show_fee_enabled' => true,
                'no_show_fee_amount' => 25,
            ],
            default => [
                'business_preset' => self::PRESET_SERVICE_GENERAL,
                'buffer_minutes' => 0,
                'slot_interval_minutes' => 30,
                'min_notice_minutes' => 0,
                'max_advance_days' => 90,
                'cancellation_cutoff_hours' => 12,
                'allow_client_cancel' => true,
                'allow_client_reschedule' => true,
                'late_release_minutes' => 0,
                'waitlist_enabled' => false,
                'queue_mode_enabled' => false,
                'queue_assignment_mode' => 'per_staff',
                'queue_dispatch_mode' => 'fifo_with_appointment_priority',
                'queue_grace_minutes' => 5,
                'queue_pre_call_threshold' => 2,
                'queue_no_show_on_grace_expiry' => false,
                'deposit_required' => false,
                'deposit_amount' => 0,
                'no_show_fee_enabled' => false,
                'no_show_fee_amount' => 0,
            ],
        };
    }

    public static function normalizePreset(?string $value): string
    {
        $normalized = Str::of((string) $value)->lower()->trim()->replace(' ', '_')->toString();

        if (in_array($normalized, self::PRESETS, true)) {
            return $normalized;
        }

        return self::PRESET_SERVICE_GENERAL;
    }

    public static function isSalonPreset(?string $value): bool
    {
        return self::normalizePreset($value) === self::PRESET_SALON;
    }

    public static function queueFeaturesEnabled(?string $preset): bool
    {
        return self::isSalonPreset($preset);
    }

    public static function presetFromSector(?string $sector): string
    {
        $normalized = Str::of((string) $sector)->lower()->trim()->replace(' ', '_')->toString();

        return match ($normalized) {
            self::PRESET_SALON => self::PRESET_SALON,
            self::PRESET_RESTAURANT => self::PRESET_RESTAURANT,
            default => self::PRESET_SERVICE_GENERAL,
        };
    }

    public static function resolveForAccount(?User $account, ?string $storedPreset = null): string
    {
        if (!empty($storedPreset)) {
            return self::normalizePreset($storedPreset);
        }

        return self::presetFromSector($account?->company_sector);
    }
}
