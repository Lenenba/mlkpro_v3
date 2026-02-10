<?php

namespace App\Http\Requests\Reservation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientTicketRequest extends FormRequest
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
            'estimated_duration_minutes' => ['nullable', 'integer', 'min:5', 'max:240'],
            'party_size' => ['nullable', 'integer', 'min:1', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'source' => ['nullable', 'string', 'max:30'],
        ];
    }
}

