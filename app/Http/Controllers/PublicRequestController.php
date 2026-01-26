<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Request as LeadRequest;
use App\Models\User;
use App\Services\CompanyFeatureService;
use App\Services\UsageLimitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PublicRequestController extends Controller
{
    public function show(Request $request, User $user): Response
    {
        $this->assertLeadIntakeEnabled($user);

        return Inertia::render('Public/RequestForm', [
            'company' => [
                'id' => $user->id,
                'name' => $user->company_name ?: $user->name,
                'logo_url' => $user->company_logo_url,
            ],
            'submit_url' => URL::signedRoute('public.requests.store', ['user' => $user->id]),
        ]);
    }

    public function store(Request $request, User $user)
    {
        $this->assertLeadIntakeEnabled($user);

        $validated = $request->validate([
            'contact_name' => 'required|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'title' => 'nullable|string|max:255',
            'service_type' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'urgency' => 'nullable|string|max:50',
            'budget' => 'nullable|numeric',
            'street1' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:120',
            'state' => 'nullable|string|max:120',
            'postal_code' => 'nullable|string|max:30',
            'country' => 'nullable|string|max:120',
        ]);

        if (empty($validated['contact_email']) && empty($validated['contact_phone'])) {
            throw ValidationException::withMessages([
                'contact_email' => ['Email or phone is required.'],
            ]);
        }

        app(UsageLimitService::class)->enforceLimit($user, 'requests');

        $title = $validated['title']
            ?? $validated['service_type']
            ?? $validated['contact_name'];

        $customerId = $this->resolveCustomerId(
            $user->id,
            $validated['contact_email'] ?? null,
            $validated['contact_phone'] ?? null
        );

        $meta = [];
        if (isset($validated['budget'])) {
            $meta['budget'] = (float) $validated['budget'];
        }

        $lead = LeadRequest::create([
            'user_id' => $user->id,
            'customer_id' => $customerId,
            'channel' => 'web_form',
            'status' => LeadRequest::STATUS_NEW,
            'status_updated_at' => now(),
            'title' => $title,
            'service_type' => $validated['service_type'] ?? null,
            'description' => $validated['description'] ?? null,
            'contact_name' => $validated['contact_name'],
            'contact_email' => $validated['contact_email'] ?? null,
            'contact_phone' => $validated['contact_phone'] ?? null,
            'urgency' => $validated['urgency'] ?? null,
            'street1' => $validated['street1'] ?? null,
            'city' => $validated['city'] ?? null,
            'state' => $validated['state'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'country' => $validated['country'] ?? null,
            'meta' => $meta ?: null,
        ]);

        ActivityLog::record(null, $lead, 'created', [
            'channel' => 'web_form',
        ], 'Public lead created');

        return redirect()->back()->with('success', 'Request submitted successfully.');
    }

    private function assertLeadIntakeEnabled(User $user): void
    {
        if ($user->isSuspended()) {
            abort(404);
        }

        $hasFeature = app(CompanyFeatureService::class)->hasFeature($user, 'requests');
        if (!$hasFeature) {
            abort(404);
        }
    }

    private function resolveCustomerId(int $accountId, ?string $email, ?string $phone): ?int
    {
        $query = Customer::query()->byUser($accountId);

        if ($email) {
            $customer = (clone $query)->where('email', $email)->first();
            if ($customer) {
                return $customer->id;
            }
        }

        if ($phone) {
            $customer = (clone $query)->where('phone', $phone)->first();
            if ($customer) {
                return $customer->id;
            }
        }

        return null;
    }
}
