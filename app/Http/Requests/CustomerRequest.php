<?php

namespace App\Http\Requests;

use App\Enums\CustomerClientType;
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
        $customer = $this->route('customer');
        $customerId = $customer ? $customer->id : null;
        $portalUserId = $customer ? $customer->portal_user_id : null;
        $portalAccess = $this->has('portal_access')
            ? filter_var($this->input('portal_access'), FILTER_VALIDATE_BOOLEAN)
            : (bool) ($customer?->portal_access ?? true);
        $clientType = CustomerClientType::infer(
            $this->input('client_type'),
            (string) ($this->input('company_name') ?? $customer?->company_name ?? '')
        );
        $emailRules = [
            'required',
            'string',
            'email',
            'max:255',
            Rule::unique('customers')->ignore($customerId),
        ];
        if ($portalAccess) {
            $emailRules[] = Rule::unique('users', 'email')->ignore($portalUserId);
        }
        return [
            'client_type' => ['nullable', 'string', Rule::in(CustomerClientType::values())],
            'portal_access' => 'nullable|boolean',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => $emailRules,
            'phone' => 'nullable|string|max:25',
            'company_name' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(fn (): bool => $clientType === CustomerClientType::COMPANY),
            ],
            'registration_number' => 'nullable|string|max:255',
            'industry' => 'nullable|string|max:255',
            'description' => 'nullable|string|min:5|max:255',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,svg|max:2048',
            'logo_icon' => [
                'nullable',
                'string',
                'max:255',
                Rule::in(config('icon_presets.company_icons', [])),
            ],
            'header_image' => 'nullable|image|mimes:jpg,jpeg,png,svg|max:2048',
            'billing_same_as_physical' => 'nullable|boolean',
            'billing_mode' => [
                'nullable',
                'string',
                Rule::in(['per_task', 'per_segment', 'end_of_job', 'deferred']),
            ],
            'billing_cycle' => [
                'nullable',
                'string',
                Rule::in(['weekly', 'biweekly', 'monthly', 'every_n_tasks']),
            ],
            'billing_grouping' => [
                'nullable',
                'string',
                Rule::in(['single', 'periodic']),
            ],
            'billing_delay_days' => 'nullable|integer|min:0|max:365',
            'billing_date_rule' => 'nullable|string|max:50',
            'discount_rate' => 'nullable|numeric|min:0|max:100',
            'auto_accept_quotes' => 'nullable|boolean',
            'auto_validate_jobs' => 'nullable|boolean',
            'auto_validate_tasks' => 'nullable|boolean',
            'auto_validate_invoices' => 'nullable|boolean',
            'refer_by' => 'nullable|string|max:255',
            'salutation' => [
                'nullable',
                Rule::in(['Mr', 'Mrs', 'Miss']),
            ],
            'properties' => 'nullable|array',
            'properties.type' => 'nullable|string|max:255',
            'properties.street1' => 'nullable|string|max:255',
            'properties.street2' => 'nullable|string|max:255',
            'properties.country' => 'nullable|string|max:255',
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
        $customer = $this->route('customer');
        $email = $this->input('email');
        $companyName = trim((string) ($this->input('company_name') ?? ''));
        $salutation = trim((string) ($this->input('salutation') ?? ''))
            ?: (string) ($customer?->salutation ?? 'Mr');

        $this->merge([
            'client_type' => CustomerClientType::infer(
                $this->input('client_type'),
                $companyName !== '' ? $companyName : ($customer?->company_name ?? null)
            )->value,
            'company_name' => $companyName !== '' ? $companyName : null,
            'registration_number' => trim((string) ($this->input('registration_number') ?? '')) ?: null,
            'industry' => trim((string) ($this->input('industry') ?? '')) ?: null,
            'salutation' => $salutation,
            'email' => is_string($email) ? strtolower($email) : $email,
        ]);
    }
}
