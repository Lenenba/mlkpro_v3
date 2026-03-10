<?php

namespace App\Http\Requests\Customers;

class UpdateCustomerPropertyRequest extends CustomerPropertyWriteRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->propertyRules(false);
    }
}
