<?php

namespace App\Http\Requests\Reservation;

use App\Models\Reservation;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $accountId = (int) ($this->user()?->accountOwnerId() ?? 0);

        return [
            'team_member_id' => [
                'nullable',
                'integer',
                Rule::exists('team_members', 'id')->where(fn ($query) => $query->where('account_id', $accountId)),
            ],
            'client_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where(fn ($query) => $query->where('user_id', $accountId)),
            ],
            'service_id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')->where(fn ($query) => $query
                    ->where('user_id', $accountId)
                    ->where('item_type', 'service')),
            ],
            'status' => ['nullable', Rule::in(Reservation::STATUSES)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
            'party_size' => ['nullable', 'integer', 'min:1', 'max:500'],
            'timezone' => ['nullable', 'timezone'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'client_notes' => ['nullable', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
            'resource_ids' => ['nullable', 'array'],
            'resource_ids.*' => [
                'integer',
                Rule::exists('reservation_resources', 'id')->where(fn ($query) => $query
                    ->where('account_id', $accountId)
                    ->where('is_active', true)),
            ],
            'resource_filters' => ['nullable', 'array'],
            'resource_filters.types' => ['nullable', 'array'],
            'resource_filters.types.*' => ['string', 'max:60'],
            'resource_filters.resource_ids' => ['nullable', 'array'],
            'resource_filters.resource_ids.*' => [
                'integer',
                Rule::exists('reservation_resources', 'id')->where(fn ($query) => $query
                    ->where('account_id', $accountId)
                    ->where('is_active', true)),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $status = (string) ($this->input('status') ?? '');
            if (!in_array($status, [Reservation::STATUS_COMPLETED, Reservation::STATUS_NO_SHOW], true)) {
                return;
            }

            try {
                $timezone = (string) ($this->input('timezone') ?: 'UTC');
                $startsAt = Carbon::parse((string) $this->input('starts_at'), $timezone)->utc();
                if ($startsAt->isFuture()) {
                    $validator->errors()->add('status', 'Completed or no-show reservations must be in the past.');
                }
            } catch (\Throwable) {
                // Date format errors are handled by validation rules.
            }
        });
    }
}
