<?php

namespace App\Http\Requests\Customers;

class StoreCustomerPropertyRequest extends CustomerPropertyWriteRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->propertyRules(true);
    }
}
