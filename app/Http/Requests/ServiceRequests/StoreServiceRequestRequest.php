<?php

namespace App\Http\Requests\ServiceRequests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceRequestRequest extends FormRequest
{
    public const RELATION_MODE_NONE = 'none';

    public const RELATION_MODE_EXISTING_CUSTOMER = 'existing_customer';

    public const RELATION_MODE_EXISTING_PROSPECT = 'existing_prospect';

    public const RELATION_MODE_NEW_PROSPECT = 'new_prospect';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ignore_duplicates' => ['nullable', 'boolean'],
            'relation_mode' => ['nullable', 'string', Rule::in([
                self::RELATION_MODE_NONE,
                self::RELATION_MODE_EXISTING_CUSTOMER,
                self::RELATION_MODE_EXISTING_PROSPECT,
                self::RELATION_MODE_NEW_PROSPECT,
            ])],
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where(fn ($query) => $query->where('user_id', $this->accountId())),
            ],
            'prospect_id' => [
                'nullable',
                'integer',
                Rule::exists('requests', 'id')->where(fn ($query) => $query->where('user_id', $this->accountId())),
            ],
            'source' => ['nullable', 'string', 'max:64'],
            'service_type' => ['nullable', 'string', 'max:255'],
            'urgency' => ['nullable', 'string', 'max:50'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'country' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'street1' => ['nullable', 'string', 'max:255'],
            'street2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'is_serviceable' => ['nullable', 'boolean'],
            'meta' => ['nullable', 'array'],
            'meta.budget' => ['nullable', 'numeric'],
            'meta.request_type' => ['nullable', 'string', 'max:100'],
            'meta.contact_consent' => ['nullable', 'boolean'],
            'meta.marketing_consent' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $relationMode = trim((string) $this->input('relation_mode', ''));
        $prepared = [
            'relation_mode' => $relationMode !== '' ? $relationMode : self::RELATION_MODE_NONE,
        ];

        foreach ([
            'customer_id',
            'prospect_id',
            'source',
            'service_type',
            'urgency',
            'title',
            'description',
            'contact_name',
            'contact_email',
            'contact_phone',
            'country',
            'state',
            'city',
            'street1',
            'street2',
            'postal_code',
        ] as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $this->input($field);
            if (is_string($value)) {
                $value = trim($value);
            }

            $prepared[$field] = $value === '' ? null : $value;
        }

        $this->merge($prepared);
    }

    private function accountId(): int
    {
        $user = $this->user();

        return (int) ($user?->accountOwnerId() ?? $user?->id ?? 0);
    }
}
