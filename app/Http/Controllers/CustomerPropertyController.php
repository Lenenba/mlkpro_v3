<?php

namespace App\Http\Controllers;

use App\Actions\Customers\UpsertCustomerPropertyAction;
use App\Http\Requests\Customers\StoreCustomerPropertyRequest;
use App\Http\Requests\Customers\UpdateCustomerPropertyRequest;
use App\Models\Customer;
use App\Models\Property;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerPropertyController extends Controller
{
    use AuthorizesRequests;

    public function store(StoreCustomerPropertyRequest $request, Customer $customer, UpsertCustomerPropertyAction $upsertCustomerProperty)
    {
        $this->authorize('update', $customer);

        $property = $upsertCustomerProperty->execute($customer, $request->validated());

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Property added successfully.',
                'property' => $property,
            ], 201);
        }

        return redirect()
            ->route('customer.show', $customer)
            ->with('success', 'Property added successfully.');
    }

    public function update(UpdateCustomerPropertyRequest $request, Customer $customer, Property $property, UpsertCustomerPropertyAction $upsertCustomerProperty)
    {
        $this->authorize('update', $customer);

        $property = $upsertCustomerProperty->execute($customer, $request->validated(), $property);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Property updated successfully.',
                'property' => $property->fresh(),
            ]);
        }

        return redirect()
            ->route('customer.show', $customer)
            ->with('success', 'Property updated successfully.');
    }

    public function destroy(Request $request, Customer $customer, Property $property)
    {
        $this->authorize('update', $customer);

        DB::transaction(function () use ($customer, $property) {
            $wasDefault = (bool) $property->is_default;
            $property->delete();

            if (! $wasDefault) {
                return;
            }

            $nextProperty = $customer->properties()->reorder('id')->first();
            if (! $nextProperty) {
                return;
            }

            $customer->properties()->update(['is_default' => false]);
            $nextProperty->update(['is_default' => true]);
        });

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Property deleted successfully.',
            ]);
        }

        return redirect()
            ->route('customer.show', $customer)
            ->with('success', 'Property deleted successfully.');
    }

    public function setDefault(Request $request, Customer $customer, Property $property)
    {
        $this->authorize('update', $customer);

        DB::transaction(function () use ($customer, $property) {
            $customer->properties()->update(['is_default' => false]);
            $property->update(['is_default' => true]);
        });

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Default property updated successfully.',
                'property' => $property->fresh(),
            ]);
        }

        return redirect()
            ->route('customer.show', $customer)
            ->with('success', 'Default property updated successfully.');
    }
}
