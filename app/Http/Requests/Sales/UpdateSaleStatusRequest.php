<?php

namespace App\Http\Requests\Sales;

use Illuminate\Validation\Rule;

class UpdateSaleStatusRequest extends SaleWriteRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in($this->saleStatuses())],
            'fulfillment_status' => ['nullable', Rule::in($this->fulfillmentStatuses())],
            'scheduled_for' => ['nullable', 'date'],
        ];
    }
}
