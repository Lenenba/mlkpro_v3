<?php

namespace App\Http\Requests\Reservation;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $accountId = (int) ($this->user()?->accountOwnerId() ?? 0);
        if ($this->user()?->isClient()) {
            $customer = $this->user()?->relationLoaded('customerProfile')
                ? $this->user()?->customerProfile
                : $this->user()?->customerProfile()->first();
            $accountId = (int) ($customer?->user_id ?? 0);
        }

        return [
            'team_member_id' => [
                'nullable',
                'integer',
                Rule::exists('team_members', 'id')->where(fn ($query) => $query->where('account_id', $accountId)),
            ],
            'service_id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')->where(fn ($query) => $query
                    ->where('user_id', $accountId)
                    ->where('item_type', 'service')
                    ->where('is_active', true)),
            ],
            'range_start' => ['required', 'date'],
            'range_end' => ['required', 'date', 'after:range_start'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            try {
                $start = Carbon::parse((string) $this->input('range_start'));
                $end = Carbon::parse((string) $this->input('range_end'));
                if ($start->diffInDays($end) > 60) {
                    $validator->errors()->add('range_end', 'Please request a date range of 60 days or less.');
                }
            } catch (\Throwable) {
                // Date format errors are handled by base rules.
            }
        });
    }
}
