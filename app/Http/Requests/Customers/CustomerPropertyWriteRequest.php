<?php

namespace App\Http\Requests\Customers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class CustomerPropertyWriteRequest extends FormRequest
{
    protected function propertyRules(bool $includeDefault): array
    {
        $rules = [
            'type' => ['required', Rule::in(['physical', 'billing', 'other'])],
            'street1' => ['nullable', 'string', 'max:255'],
            'street2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:10'],
            'country' => ['nullable', 'string', 'max:255'],
        ];

        if ($includeDefault) {
            $rules['is_default'] = ['sometimes', 'boolean'];
        }

        return $rules;
    }
}
