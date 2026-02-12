<?php

namespace App\Http\Requests\Reservation;

use App\Models\Reservation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KioskCheckInRequest extends FormRequest
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
            'verification_code' => ['nullable', 'digits:6'],
            'reservation_id' => [
                'nullable',
                'integer',
                Rule::exists('reservations', 'id')->where(fn ($query) => $query
                    ->where('account_id', $accountId)
                    ->whereIn('status', Reservation::ACTIVE_STATUSES)),
            ],
        ];
    }

    private function accountId(): int
    {
        return max(0, (int) $this->query('account'));
    }
}
