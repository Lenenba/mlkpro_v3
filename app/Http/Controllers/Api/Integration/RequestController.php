<?php

namespace App\Http\Controllers\Api\Integration;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Request as LeadRequest;
use App\Services\CompanyFeatureService;
use App\Services\UsageLimitService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RequestController extends Controller
{
    public function store(Request $request)
    {
        $this->ensureAbility($request, 'requests:write');

        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        if (!app(CompanyFeatureService::class)->hasFeature($user, 'requests')) {
            abort(403);
        }

        $validated = $request->validate([
            'external_customer_id' => 'nullable|string|max:100',
            'channel' => 'nullable|string|max:50',
            'service_type' => 'nullable|string|max:255',
            'urgency' => 'nullable|string|max:50',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:5000',
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
            'next_follow_up_at' => 'nullable|date',
            'meta' => 'nullable|array',
            'meta.budget' => 'nullable|numeric',
        ]);

        if (
            empty($validated['contact_name'])
            && empty($validated['contact_email'])
            && empty($validated['contact_phone'])
            && empty($validated['title'])
            && empty($validated['service_type'])
        ) {
            throw ValidationException::withMessages([
                'contact_name' => ['Lead data is incomplete.'],
            ]);
        }

        app(UsageLimitService::class)->enforceLimit($user, 'requests');

        $accountId = $user->accountOwnerId();
        $channel = $this->normalizeChannel($validated['channel'] ?? null) ?? 'api';
        $urgency = $this->normalizeUrgency($validated['urgency'] ?? null);

        $customerId = $this->resolveCustomerId(
            $accountId,
            $validated['contact_email'] ?? null,
            $validated['contact_phone'] ?? null
        );

        $title = $validated['title']
            ?? $validated['service_type']
            ?? $validated['contact_name'];

        $lead = LeadRequest::create([
            ...$validated,
            'user_id' => $accountId,
            'customer_id' => $customerId,
            'channel' => $channel,
            'urgency' => $urgency,
            'title' => $title,
            'status' => LeadRequest::STATUS_NEW,
            'status_updated_at' => now(),
        ]);

        ActivityLog::record($user, $lead, 'created', [
            'channel' => $channel,
        ], 'API lead created');

        return response()->json([
            'message' => 'Lead created.',
            'request' => $lead,
        ], 201);
    }

    private function ensureAbility(Request $request, string $ability): void
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $token = $user->currentAccessToken();
        if ($token && !$user->tokenCan($ability)) {
            abort(403);
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

    private function normalizeChannel(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $normalized = strtolower(trim($value));
        $map = [
            'web' => 'web_form',
            'website' => 'web_form',
            'form' => 'web_form',
            'phone' => 'phone',
            'call' => 'phone',
            'email' => 'email',
            'mail' => 'email',
            'whatsapp' => 'whatsapp',
            'wa' => 'whatsapp',
            'sms' => 'sms',
            'text' => 'sms',
            'qr' => 'qr',
            'api' => 'api',
            'webhook' => 'api',
            'import' => 'import',
            'csv' => 'import',
            'referral' => 'referral',
            'ads' => 'ads',
            'portal' => 'portal',
        ];

        return $map[$normalized] ?? ($normalized !== '' ? $normalized : null);
    }

    private function normalizeUrgency(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $normalized = strtolower(trim($value));
        $map = [
            'urgent' => 'urgent',
            'high' => 'high',
            'haute' => 'high',
            'medium' => 'medium',
            'moyenne' => 'medium',
            'low' => 'low',
            'basse' => 'low',
        ];

        return $map[$normalized] ?? null;
    }
}
