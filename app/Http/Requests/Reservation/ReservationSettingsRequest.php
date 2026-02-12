<?php

namespace App\Http\Requests\Reservation;

use App\Models\AvailabilityException;
use App\Support\ReservationPresetResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReservationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $accountId = (int) ($this->user()?->accountOwnerId() ?? 0);

        return [
            'account_settings' => ['nullable', 'array'],
            'account_settings.buffer_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'account_settings.slot_interval_minutes' => ['nullable', 'integer', 'min:5', 'max:120'],
            'account_settings.min_notice_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'account_settings.max_advance_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'account_settings.cancellation_cutoff_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
            'account_settings.allow_client_cancel' => ['nullable', 'boolean'],
            'account_settings.allow_client_reschedule' => ['nullable', 'boolean'],
            'account_settings.business_preset' => ['nullable', 'string', Rule::in(ReservationPresetResolver::PRESETS)],
            'account_settings.late_release_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'account_settings.waitlist_enabled' => ['nullable', 'boolean'],
            'account_settings.queue_mode_enabled' => ['nullable', 'boolean'],
            'account_settings.queue_assignment_mode' => ['nullable', 'string', Rule::in(['per_staff', 'global_pull'])],
            'account_settings.queue_dispatch_mode' => ['nullable', 'string', Rule::in(['fifo', 'fifo_with_appointment_priority', 'skill_based'])],
            'account_settings.queue_grace_minutes' => ['nullable', 'integer', 'min:1', 'max:60'],
            'account_settings.queue_pre_call_threshold' => ['nullable', 'integer', 'min:1', 'max:20'],
            'account_settings.queue_no_show_on_grace_expiry' => ['nullable', 'boolean'],
            'account_settings.deposit_required' => ['nullable', 'boolean'],
            'account_settings.deposit_amount' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'account_settings.no_show_fee_enabled' => ['nullable', 'boolean'],
            'account_settings.no_show_fee_amount' => ['nullable', 'numeric', 'min:0', 'max:10000'],

            'team_settings' => ['nullable', 'array'],
            'team_settings.*.team_member_id' => [
                'required',
                'integer',
                Rule::exists('team_members', 'id')->where(fn ($query) => $query->where('account_id', $accountId)),
            ],
            'team_settings.*.buffer_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'team_settings.*.slot_interval_minutes' => ['nullable', 'integer', 'min:5', 'max:120'],
            'team_settings.*.min_notice_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'team_settings.*.max_advance_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'team_settings.*.cancellation_cutoff_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
            'team_settings.*.allow_client_cancel' => ['nullable', 'boolean'],
            'team_settings.*.allow_client_reschedule' => ['nullable', 'boolean'],

            'weekly_availabilities' => ['nullable', 'array'],
            'weekly_availabilities.*.team_member_id' => [
                'required',
                'integer',
                Rule::exists('team_members', 'id')->where(fn ($query) => $query->where('account_id', $accountId)),
            ],
            'weekly_availabilities.*.day_of_week' => ['required', 'integer', 'min:0', 'max:6'],
            'weekly_availabilities.*.start_time' => ['required', 'date_format:H:i'],
            'weekly_availabilities.*.end_time' => ['required', 'date_format:H:i'],
            'weekly_availabilities.*.is_active' => ['nullable', 'boolean'],

            'exceptions' => ['nullable', 'array'],
            'exceptions.*.id' => [
                'nullable',
                'integer',
                Rule::exists('availability_exceptions', 'id')->where(fn ($query) => $query->where('account_id', $accountId)),
            ],
            'exceptions.*.team_member_id' => [
                'nullable',
                'integer',
                Rule::exists('team_members', 'id')->where(fn ($query) => $query->where('account_id', $accountId)),
            ],
            'exceptions.*.date' => ['required', 'date'],
            'exceptions.*.start_time' => ['nullable', 'date_format:H:i'],
            'exceptions.*.end_time' => ['nullable', 'date_format:H:i'],
            'exceptions.*.type' => ['required', Rule::in(AvailabilityException::TYPES)],
            'exceptions.*.reason' => ['nullable', 'string', 'max:255'],

            'resources' => ['nullable', 'array'],
            'resources.*.id' => [
                'nullable',
                'integer',
                Rule::exists('reservation_resources', 'id')->where(fn ($query) => $query->where('account_id', $accountId)),
            ],
            'resources.*.team_member_id' => [
                'nullable',
                'integer',
                Rule::exists('team_members', 'id')->where(fn ($query) => $query->where('account_id', $accountId)),
            ],
            'resources.*.name' => ['required', 'string', 'max:120'],
            'resources.*.type' => ['nullable', 'string', 'max:60'],
            'resources.*.capacity' => ['nullable', 'integer', 'min:1', 'max:500'],
            'resources.*.is_active' => ['nullable', 'boolean'],
            'resources.*.metadata' => ['nullable', 'array'],

            'notification_settings' => ['nullable', 'array'],
            'notification_settings.enabled' => ['nullable', 'boolean'],
            'notification_settings.email' => ['nullable', 'boolean'],
            'notification_settings.in_app' => ['nullable', 'boolean'],
            'notification_settings.sms' => ['nullable', 'boolean'],
            'notification_settings.notify_on_created' => ['nullable', 'boolean'],
            'notification_settings.notify_on_rescheduled' => ['nullable', 'boolean'],
            'notification_settings.notify_on_cancelled' => ['nullable', 'boolean'],
            'notification_settings.notify_on_completed' => ['nullable', 'boolean'],
            'notification_settings.notify_on_reminder' => ['nullable', 'boolean'],
            'notification_settings.notify_on_review_submitted' => ['nullable', 'boolean'],
            'notification_settings.review_request_on_completed' => ['nullable', 'boolean'],
            'notification_settings.notify_on_queue_pre_call' => ['nullable', 'boolean'],
            'notification_settings.notify_on_queue_called' => ['nullable', 'boolean'],
            'notification_settings.notify_on_queue_grace_expired' => ['nullable', 'boolean'],
            'notification_settings.reminder_hours' => ['nullable', 'array'],
            'notification_settings.reminder_hours.*' => ['integer', 'min:1', 'max:168'],
        ];
    }
}
