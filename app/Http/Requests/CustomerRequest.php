<?php

namespace App\Http\Requests;

use App\Models\User;
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
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $customerId = $this->route('customer') ? $this->route('customer')->id : null;

        return [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            'phone' => 'nullable|string|max:15',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'zip' => 'nullable|string|max:10',
            'company_name' => 'nullable|string|max:255',
            'logo' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,svg',
                'max:2048',
            ],
            'type' => 'nullable|string|max:255',
            'description' => 'nullable|string|min:5|max:255',
        ];
    }

    /**
     * Modify the validated data before returning it.
     *
     * @return array
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower($this->email), // Normalize email to lowercase
        ]);
    }
}
