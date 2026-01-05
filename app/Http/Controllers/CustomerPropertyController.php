<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Property;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerPropertyController extends Controller
{
    use AuthorizesRequests;

    public function store(Request $request, Customer $customer)
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'type' => ['required', Rule::in(['physical', 'billing', 'other'])],
            'is_default' => ['sometimes', 'boolean'],
            'street1' => ['nullable', 'string', 'max:255'],
            'street2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:10'],
            'country' => ['nullable', 'string', 'max:255'],
        ]);

        $makeDefault = (bool) ($validated['is_default'] ?? false);
        unset($validated['is_default']);

        $property = null;
        DB::transaction(function () use ($customer, $validated, $makeDefault, &$property) {
            $hasExisting = $customer->properties()->exists();
            $shouldBeDefault = $makeDefault || !$hasExisting;

            if ($shouldBeDefault) {
                $customer->properties()->update(['is_default' => false]);
            }

            $validated['is_default'] = $shouldBeDefault;
            $property = $customer->properties()->create($validated);
        });

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

    public function update(Request $request, Customer $customer, Property $property)
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'type' => ['required', Rule::in(['physical', 'billing', 'other'])],
            'street1' => ['nullable', 'string', 'max:255'],
            'street2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:10'],
            'country' => ['nullable', 'string', 'max:255'],
        ]);

        $property->update($validated);

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

            if (!$wasDefault) {
                return;
            }

            $nextProperty = $customer->properties()->reorder('id')->first();
            if (!$nextProperty) {
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
