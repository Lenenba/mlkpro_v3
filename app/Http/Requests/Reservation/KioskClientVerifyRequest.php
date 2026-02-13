<?php

namespace App\Http\Requests\Reservation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KioskClientVerifyRequest extends FormRequest
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
            'code' => ['required', 'digits:6'],
        ];
    }
}
