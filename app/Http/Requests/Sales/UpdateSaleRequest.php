<?php

namespace App\Http\Requests\Sales;

use Illuminate\Validation\Rule;

class UpdateSaleRequest extends SaleWriteRequest
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
            'fulfillment_status' => ['nullable', Rule::in($this->fulfillmentStatuses())],
            'notes' => ['nullable', 'string', 'max:2000'],
            'promotion_code' => ['nullable', 'string', 'max:50'],
            'scheduled_for' => ['nullable', 'date'],
        ], $this->itemRules());
    }
}
