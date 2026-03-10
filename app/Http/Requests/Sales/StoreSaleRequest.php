<?php

namespace App\Http\Requests\Sales;

use Illuminate\Validation\Rule;

class StoreSaleRequest extends SaleWriteRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge([
            'customer_id' => $this->customerRule(),
            'status' => ['required', Rule::in($this->saleStatuses())],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'pay_with_stripe' => ['nullable', 'boolean'],
            'fulfillment_status' => ['nullable', Rule::in($this->fulfillmentStatuses())],
            'notes' => ['nullable', 'string', 'max:2000'],
            'scheduled_for' => ['nullable', 'date'],
            'loyalty_points_redeem' => ['nullable', 'integer', 'min:0'],
        ], $this->itemRules());
    }
}
