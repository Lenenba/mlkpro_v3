<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

class UpsertCustomerPropertyAction
{
    public function execute(Customer $customer, array $validated, ?Property $property = null): Property
    {
        if ($property) {
            $property->update($validated);

            return $property->fresh();
        }

        $makeDefault = (bool) ($validated['is_default'] ?? false);
        unset($validated['is_default']);

        $createdProperty = null;
        DB::transaction(function () use ($customer, $validated, $makeDefault, &$createdProperty) {
            $hasExisting = $customer->properties()->exists();
            $shouldBeDefault = $makeDefault || ! $hasExisting;

            if ($shouldBeDefault) {
                $customer->properties()->update(['is_default' => false]);
            }

            $validated['is_default'] = $shouldBeDefault;
            $createdProperty = $customer->properties()->create($validated);
        });

        return $createdProperty;
    }
}
