<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class RequestController extends Controller
{
    /**
     * Store a new lead request.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        $validated = $request->validate([
            'customer_id' => ['nullable', Rule::exists('customers', 'id')],
            'external_customer_id' => 'nullable|string|max:100',
            'channel' => 'nullable|string|max:50',
            'service_type' => 'nullable|string|max:255',
            'urgency' => 'nullable|string|max:50',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'country' => 'nullable|string|max:120',
            'state' => 'nullable|string|max:120',
            'city' => 'nullable|string|max:120',
            'street1' => 'nullable|string|max:255',
            'street2' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:30',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'is_serviceable' => 'nullable|boolean',
            'meta' => 'nullable|array',
        ]);

        $customerId = $validated['customer_id'] ?? null;
        if ($customerId) {
            Customer::byUser($accountId)->findOrFail($customerId);
        }

        LeadRequest::create([
            ...$validated,
            'user_id' => $accountId,
            'status' => LeadRequest::STATUS_NEW,
        ]);

        return redirect()->back()->with('success', 'Request created successfully.');
    }

    /**
     * Convert a request into a draft quote.
     */
    public function convert(Request $request, LeadRequest $lead): RedirectResponse
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if ($lead->user_id !== $accountId) {
            abort(403);
        }

        $validated = $request->validate([
            'customer_id' => ['required', Rule::exists('customers', 'id')],
            'property_id' => ['nullable', Rule::exists('properties', 'id')],
            'job_title' => 'nullable|string|max:255',
        ]);

        $customer = Customer::byUser($accountId)->findOrFail($validated['customer_id']);
        $propertyId = $validated['property_id'] ?? null;
        if ($propertyId && !$customer->properties()->whereKey($propertyId)->exists()) {
            return redirect()->back()->withErrors(['property_id' => 'Invalid property for this customer.']);
        }
        $jobTitle = $validated['job_title'] ?? $lead->title ?? $lead->service_type ?? 'New Quote';

        $quote = Quote::create([
            'user_id' => $accountId,
            'customer_id' => $customer->id,
            'property_id' => $propertyId,
            'job_title' => $jobTitle,
            'status' => 'draft',
            'request_id' => $lead->id,
            'notes' => $lead->description,
        ]);

        $lead->update([
            'status' => LeadRequest::STATUS_CONVERTED,
            'converted_at' => now(),
        ]);

        return redirect()->route('customer.quote.edit', $quote)->with('success', 'Request converted to quote.');
    }
}
