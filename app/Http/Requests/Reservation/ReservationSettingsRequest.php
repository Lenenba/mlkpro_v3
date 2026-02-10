<?php

namespace App\Http\Requests\Reservation;

use App\Models\AvailabilityException;
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

            'notification_settings' => ['nullable', 'array'],
            'notification_settings.enabled' => ['nullable', 'boolean'],
            'notification_settings.email' => ['nullable', 'boolean'],
            'notification_settings.in_app' => ['nullable', 'boolean'],
            'notification_settings.notify_on_created' => ['nullable', 'boolean'],
            'notification_settings.notify_on_rescheduled' => ['nullable', 'boolean'],
            'notification_settings.notify_on_cancelled' => ['nullable', 'boolean'],
            'notification_settings.notify_on_completed' => ['nullable', 'boolean'],
            'notification_settings.notify_on_reminder' => ['nullable', 'boolean'],
            'notification_settings.notify_on_review_submitted' => ['nullable', 'boolean'],
            'notification_settings.review_request_on_completed' => ['nullable', 'boolean'],
            'notification_settings.reminder_hours' => ['nullable', 'array'],
            'notification_settings.reminder_hours.*' => ['integer', 'min:1', 'max:168'],
        ];
    }
}
