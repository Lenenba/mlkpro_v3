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
        $customer = $this->route('customer');
        $customerId = $customer ? $customer->id : null;
        $portalUserId = $customer ? $customer->portal_user_id : null;
        $portalAccess = $this->has('portal_access')
            ? filter_var($this->input('portal_access'), FILTER_VALIDATE_BOOLEAN)
            : (bool) ($customer?->portal_access ?? true);
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
            'portal_access' => 'nullable|boolean',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => $emailRules,
            'phone' => 'nullable|string|max:25',
            'company_name' => 'nullable|string|max:255',
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
                'required',
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
        $this->merge([
            'email' => strtolower($this->email),
        ]);
    }
}
