<?php

namespace App\Http\Requests\Leads;

use Illuminate\Foundation\Http\FormRequest;

class ImportLeadRequestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ignore_duplicates' => ['nullable', 'boolean'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10000'],
            'mapping' => ['nullable', 'array'],
        ];
    }
}
