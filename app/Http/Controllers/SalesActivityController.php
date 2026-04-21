<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Services\CRM\SalesActivityLogger;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class SalesActivityController extends Controller
{
    use AuthorizesRequests;

    public function storeForRequest(HttpRequest $request, LeadRequest $lead, SalesActivityLogger $logger)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (! $user || $user->id !== $accountId) {
            abort(403);
        }

        if ((int) $lead->user_id !== (int) $accountId) {
            abort(404);
        }

        $activity = $logger->record($user, $lead, $this->validatedPayload($request));

        return $this->salesActivityResponse($request, $activity);
    }

    public function storeForCustomer(HttpRequest $request, Customer $customer, SalesActivityLogger $logger)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $this->authorize('view', $customer);

        $activity = $logger->record($user, $customer, $this->validatedPayload($request));

        return $this->salesActivityResponse($request, $activity);
    }

    public function storeForQuote(HttpRequest $request, Quote $quote, SalesActivityLogger $logger)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $this->authorize('show', $quote);

        $activity = $logger->record($user, $quote, $this->validatedPayload($request));

        return $this->salesActivityResponse($request, $activity);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(HttpRequest $request): array
    {
        return $request->validate([
            'action' => ['nullable', 'string', Rule::in(\App\Support\CRM\SalesActivityTaxonomy::manualActions())],
            'quick_action' => ['nullable', 'string', Rule::in(array_keys(\App\Support\CRM\SalesActivityTaxonomy::quickActions()))],
            'description' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'],
            'due_at' => ['nullable', 'date'],
            'occurred_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ]);
    }

    private function salesActivityResponse(HttpRequest $request, \App\Models\ActivityLog $activity)
    {
        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Sales activity logged.',
                'activity' => $activity,
            ], 201);
        }

        return redirect()->back()->with('success', 'Sales activity logged.');
    }
}
