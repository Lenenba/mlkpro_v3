<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class CustomerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $customerId = $this->route('customer') ? $this->route('customer')->id : null;

        return [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('customers')->ignore($customerId),
            ],
            'phone' => 'nullable|string|max:15',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'zip' => 'nullable|string|max:10',
            'company_name' => 'nullable|string|max:255',
            'description' => 'nullable|string|min:5|max:255',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,svg|max:2048',
            'billing_same_as_physical' => 'nullable|boolean',
            'refer_by' => 'nullable|string|max:255',
            'salutation' => [
                'required',
                Rule::in(['Mr', 'Mrs', 'Miss']),
            ],
            'properties' => 'nullable|array',
            'properties.type' => 'nullable|string|max:255',
            'properties.street1' => 'nullable|string|max:255',
            'properties.street2' => 'nullable|string|max:255',
            'properties.country' => 'nullable|string|max:255',
            'properties.address' => 'nullable|string|max:255',
            'properties.city' => 'nullable|string|max:255',
            'properties.state' => 'nullable|string|max:255',
            'properties.zip' => 'nullable|string|max:10',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower($this->email),
        ]);
    }
}
