<?php

namespace App\Http\Requests\Reservation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KioskTicketTrackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account' => ['required', 'integer', Rule::exists('users', 'id')],
            'phone' => ['required', 'string', 'max:40'],
            'queue_number' => ['nullable', 'string', 'max:40'],
            'ticket_id' => ['nullable', 'integer'],
        ];
    }
}
