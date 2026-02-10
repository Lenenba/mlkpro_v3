<?php

namespace App\Http\Requests\Reservation;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ClientWaitlistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $customer = $this->user()?->relationLoaded('customerProfile')
            ? $this->user()?->customerProfile
            : $this->user()?->customerProfile()->first();
        $accountId = (int) ($customer?->user_id ?? 0);

        return [
            'service_id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')->where(fn ($query) => $query
                    ->where('user_id', $accountId)
                    ->where('item_type', 'service')
                    ->where('is_active', true)),
            ],
            'team_member_id' => [
                'nullable',
                'integer',
                Rule::exists('team_members', 'id')->where(fn ($query) => $query
                    ->where('account_id', $accountId)
                    ->where('is_active', true)),
            ],
            'requested_start_at' => ['required', 'date'],
            'requested_end_at' => ['required', 'date', 'after:requested_start_at'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
            'party_size' => ['nullable', 'integer', 'min:1', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
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
            try {
                $start = Carbon::parse((string) $this->input('requested_start_at'));
                $end = Carbon::parse((string) $this->input('requested_end_at'));
                if ($start->diffInDays($end) > 60) {
                    $validator->errors()->add('requested_end_at', 'Please request a date range of 60 days or less.');
                }
            } catch (\Throwable) {
                // Date format errors are handled by validation rules.
            }
        });
    }
}

