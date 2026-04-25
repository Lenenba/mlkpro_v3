<?php

namespace App\Http\Requests\Leads;

class StoreLeadRequest extends LeadWriteRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => $this->customerRule(),
            'assigned_team_member_id' => $this->assigneeRule(),
            'external_customer_id' => ['nullable', 'string', 'max:100'],
            'channel' => ['nullable', 'string', 'max:50'],
            'service_type' => ['nullable', 'string', 'max:255'],
            'urgency' => ['nullable', 'string', 'max:50'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'country' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'street1' => ['nullable', 'string', 'max:255'],
            'street2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'is_serviceable' => ['nullable', 'boolean'],
            'next_follow_up_at' => ['nullable', 'date'],
            'meta' => ['nullable', 'array'],
            'meta.budget' => ['nullable', 'numeric'],
            'meta.request_type' => ['nullable', 'string', 'max:100'],
            'meta.contact_consent' => ['nullable', 'boolean'],
            'meta.marketing_consent' => ['nullable', 'boolean'],
        ];
    }
}
