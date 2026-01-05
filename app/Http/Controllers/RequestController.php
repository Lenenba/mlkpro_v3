<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Services\UsageLimitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class RequestController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (!$user || $user->id !== $accountId) {
            abort(403);
        }

        $filters = $request->only([
            'search',
            'status',
            'customer_id',
        ]);

        $baseQuery = LeadRequest::query()
            ->where('user_id', $accountId)
            ->when(
                $filters['search'] ?? null,
                function ($query, $search) {
                    $query->where(function ($sub) use ($search) {
                        $sub->where('title', 'like', '%' . $search . '%')
                            ->orWhere('service_type', 'like', '%' . $search . '%')
                            ->orWhere('description', 'like', '%' . $search . '%')
                            ->orWhere('contact_name', 'like', '%' . $search . '%')
                            ->orWhere('contact_email', 'like', '%' . $search . '%')
                            ->orWhere('contact_phone', 'like', '%' . $search . '%')
                            ->orWhere('external_customer_id', 'like', '%' . $search . '%');
                    });
                }
            )
            ->when(
                $filters['status'] ?? null,
                function ($query, $status) {
                    $allowed = [LeadRequest::STATUS_NEW, LeadRequest::STATUS_CONVERTED];
                    if (!in_array($status, $allowed, true)) {
                        return;
                    }
                    $query->where('status', $status);
                }
            )
            ->when(
                $filters['customer_id'] ?? null,
                fn($query, $customerId) => $query->where('customer_id', $customerId)
            );

        $requests = (clone $baseQuery)
            ->with([
                'customer:id,company_name,first_name,last_name,email,phone',
                'quote:id,number,status,customer_id,request_id',
            ])
            ->latest()
            ->simplePaginate(15)
            ->withQueryString();

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'new' => (clone $baseQuery)->where('status', LeadRequest::STATUS_NEW)->count(),
            'converted' => (clone $baseQuery)->where('status', LeadRequest::STATUS_CONVERTED)->count(),
            'unassigned' => (clone $baseQuery)->whereNull('customer_id')->count(),
        ];

        $customers = Customer::byUser($accountId)
            ->with(['properties' => function ($query) {
                $query->orderByDesc('is_default')->orderBy('id');
            }])
            ->orderBy('company_name')
            ->orderBy('last_name')
            ->get(['id', 'company_name', 'first_name', 'last_name', 'email', 'phone'])
            ->map(function ($customer) {
                return [
                    'id' => $customer->id,
                    'company_name' => $customer->company_name,
                    'first_name' => $customer->first_name,
                    'last_name' => $customer->last_name,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                    'properties' => $customer->properties->map(function ($property) {
                        return [
                            'id' => $property->id,
                            'type' => $property->type,
                            'is_default' => (bool) $property->is_default,
                            'street1' => $property->street1,
                            'street2' => $property->street2,
                            'city' => $property->city,
                            'state' => $property->state,
                            'zip' => $property->zip,
                            'country' => $property->country,
                        ];
                    })->values(),
                ];
            })
            ->values();

        $statuses = [
            ['id' => LeadRequest::STATUS_NEW, 'name' => 'New'],
            ['id' => LeadRequest::STATUS_CONVERTED, 'name' => 'Converted'],
        ];

        return $this->inertiaOrJson('Request/Index', [
            'requests' => $requests,
            'filters' => $filters,
            'stats' => $stats,
            'customers' => $customers,
            'statuses' => $statuses,
        ]);
    }

    /**
     * Store a new lead request.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if ($user) {
            app(UsageLimitService::class)->enforceLimit($user, 'requests');
        }

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

        $lead = LeadRequest::create([
            ...$validated,
            'user_id' => $accountId,
            'status' => LeadRequest::STATUS_NEW,
        ]);

        ActivityLog::record($user, $lead, 'created', [
            'customer_id' => $customerId,
            'title' => $lead->title,
            'service_type' => $lead->service_type,
        ], 'Request created');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Request created successfully.',
                'request' => $lead,
            ], 201);
        }

        return redirect()->back()->with('success', 'Request created successfully.');
    }

    /**
     * Convert a request into a draft quote.
     */
    public function convert(Request $request, LeadRequest $lead)
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
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Validation error.',
                    'errors' => [
                        'property_id' => ['Invalid property for this customer.'],
                    ],
                ], 422);
            }

            return redirect()->back()->withErrors(['property_id' => 'Invalid property for this customer.']);
        }
        $jobTitle = $validated['job_title'] ?? $lead->title ?? $lead->service_type ?? 'New Quote';

        if ($user) {
            app(UsageLimitService::class)->enforceLimit($user, 'quotes');
        }

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

        ActivityLog::record($user, $lead, 'converted', [
            'quote_id' => $quote->id,
            'customer_id' => $quote->customer_id,
        ], 'Request converted to quote');

        ActivityLog::record($user, $quote, 'created', [
            'request_id' => $lead->id,
            'customer_id' => $quote->customer_id,
        ], 'Quote created from request');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Request converted to quote.',
                'quote' => $quote,
                'request' => $lead->fresh(),
            ]);
        }

        return redirect()->route('customer.quote.edit', $quote)->with('success', 'Request converted to quote.');
    }

    public function destroy(Request $request, LeadRequest $lead)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (!$user || $user->id !== $accountId) {
            abort(403);
        }

        if ($lead->user_id !== $accountId) {
            abort(403);
        }

        ActivityLog::record($user, $lead, 'deleted', [
            'status' => $lead->status,
        ], 'Request deleted');

        $lead->delete();

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Request deleted.',
            ]);
        }

        return redirect()->back()->with('success', 'Request deleted.');
    }
}
