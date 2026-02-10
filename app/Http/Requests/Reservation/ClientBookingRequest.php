<?php

namespace App\Http\Requests\Reservation;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ClientBookingRequest extends FormRequest
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
            'team_member_id' => [
                'required',
                'integer',
                Rule::exists('team_members', 'id')->where(function ($query) use ($accountId) {
                    $query->where('account_id', $accountId)
                        ->where('is_active', true);
                }),
            ],
            'service_id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')->where(function ($query) use ($accountId) {
                    $query->where('user_id', $accountId)
                        ->where('item_type', 'service')
                        ->where('is_active', true);
                }),
            ],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
            'timezone' => ['nullable', 'timezone'],
            'client_notes' => ['nullable', 'string', 'max:5000'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            try {
                $timezone = (string) ($this->input('timezone') ?: 'UTC');
                $startsAt = Carbon::parse((string) $this->input('starts_at'), $timezone)->utc();
                if ($startsAt->lt(now('UTC')->addMinutes(5))) {
                    $validator->errors()->add('starts_at', 'Please select a future time slot.');
                }
            } catch (\Throwable) {
                // Date format errors are handled by base rules.
            }
        });
    }
}
