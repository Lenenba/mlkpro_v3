<?php

namespace App\Http\Requests\Reservation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KioskWalkInTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $accountId = $this->accountId();

        return [
            'account' => ['required', 'integer', Rule::exists('users', 'id')],
            'phone' => ['required', 'string', 'max:40'],
            'guest_name' => ['nullable', 'string', 'max:120'],
            'verification_code' => ['nullable', 'digits:6'],
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
        ];
    }

    private function accountId(): int
    {
        return max(0, (int) $this->query('account'));
    }
}
