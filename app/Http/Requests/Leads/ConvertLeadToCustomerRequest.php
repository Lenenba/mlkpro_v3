<?php

namespace App\Http\Requests\Leads;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConvertLeadToCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $accountId = (int) ($this->user()?->accountOwnerId() ?? $this->user()?->id ?? 0);

        return [
            'mode' => ['required', 'string', Rule::in(['link_existing', 'create_new'])],
            'customer_id' => [
                'nullable',
                'integer',
                Rule::requiredIf(fn (): bool => $this->input('mode') === 'link_existing'),
                Rule::exists('customers', 'id')->where(fn ($query) => $query->where('user_id', $accountId)),
            ],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'street1' => ['nullable', 'string', 'max:255'],
            'street2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:50'],
            'country' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'mode' => trim((string) ($this->input('mode') ?? '')),
            'contact_name' => $this->normalizeNullableString($this->input('contact_name')),
            'contact_email' => $this->normalizeNullableEmail($this->input('contact_email')),
            'contact_phone' => $this->normalizeNullableString($this->input('contact_phone')),
            'company_name' => $this->normalizeNullableString($this->input('company_name')),
            'street1' => $this->normalizeNullableString($this->input('street1')),
            'street2' => $this->normalizeNullableString($this->input('street2')),
            'city' => $this->normalizeNullableString($this->input('city')),
            'state' => $this->normalizeNullableString($this->input('state')),
            'postal_code' => $this->normalizeNullableString($this->input('postal_code')),
            'country' => $this->normalizeNullableString($this->input('country')),
        ]);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeNullableEmail(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }
}
