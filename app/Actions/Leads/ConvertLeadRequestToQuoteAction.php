<?php

namespace App\Actions\Leads;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\User;
use App\Services\ProspectStatusHistoryService;
use App\Services\UsageLimitService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConvertLeadRequestToQuoteAction
{
    public function execute(LeadRequest $lead, array $validated, User $actor): array
    {
        $accountId = (int) ($actor->accountOwnerId() ?? $actor->id);
        $createCustomer = (bool) ($validated['create_customer'] ?? false);
        $customerId = $validated['customer_id'] ?? $lead->customer_id;
        $propertyId = $validated['property_id'] ?? null;
        $quote = null;
        $previousStatus = $lead->status;

        DB::transaction(function () use (&$customerId, &$propertyId, &$quote, $accountId, $createCustomer, $lead, $validated, $actor, $previousStatus) {
            if ($createCustomer || ! $customerId) {
                [$customerId, $propertyId] = $this->createCustomerFromLead($lead, $validated, $accountId);
            }

            if (! $customerId) {
                throw ValidationException::withMessages([
                    'customer_id' => 'Customer is required.',
                ]);
            }

            $customer = Customer::byUser($accountId)->findOrFail((int) $customerId);
            if ($propertyId && ! $customer->properties()->whereKey($propertyId)->exists()) {
                throw ValidationException::withMessages([
                    'property_id' => 'Invalid property for this customer.',
                ]);
            }

            app(UsageLimitService::class)->enforceLimit($actor, 'quotes');

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
                'customer_id' => $customer->id,
                'status' => LeadRequest::STATUS_QUALIFIED,
                'status_updated_at' => now(),
                'converted_at' => now(),
                'last_activity_at' => now(),
            ]);

            ActivityLog::record($actor, $lead, 'converted', [
                'quote_id' => $quote->id,
                'customer_id' => $quote->customer_id,
            ], 'Prospect converted to quote');

            ActivityLog::record($actor, $quote, 'created', [
                'request_id' => $lead->id,
                'customer_id' => $quote->customer_id,
            ], 'Quote created from prospect');

            app(ProspectStatusHistoryService::class)->record($lead, $actor, [
                'from_status' => $previousStatus,
                'to_status' => $lead->status,
                'comment' => 'Quote draft created from prospect.',
                'metadata' => [
                    'source' => 'quote_conversion',
                    'quote_id' => $quote->id,
                ],
            ]);
        });

        return [
            'quote' => $quote?->fresh(),
            'lead' => $lead->fresh(),
        ];
    }

    private function createCustomerFromLead(LeadRequest $lead, array $validated, int $accountId): array
    {
        $contactName = trim((string) ($validated['contact_name'] ?? $lead->contact_name ?? ''));
        $contactEmail = $validated['contact_email'] ?? $lead->contact_email;
        $contactPhone = $validated['contact_phone'] ?? $lead->contact_phone;
        $customerName = trim((string) ($validated['customer_name'] ?? ''));

        if ($customerName === '') {
            $customerName = trim((string) ($lead->title ?? $lead->service_type ?? ''));
        }

        if ($customerName === '' && $contactName !== '') {
            $customerName = $contactName;
        }

        $firstName = null;
        $lastName = null;
        if ($contactName !== '') {
            $parts = preg_split('/\s+/', $contactName, 2);
            $firstName = $parts[0] ?? null;
            $lastName = $parts[1] ?? null;
        }

        $customer = Customer::create([
            'user_id' => $accountId,
            'company_name' => $customerName !== '' ? $customerName : null,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $contactEmail,
            'phone' => $contactPhone,
            'description' => $lead->description,
        ]);

        $propertyId = $validated['property_id'] ?? null;
        if (! $propertyId && $lead->city) {
            $property = $customer->properties()->create([
                'type' => 'physical',
                'is_default' => true,
                'street1' => $lead->street1,
                'street2' => $lead->street2,
                'city' => $lead->city,
                'state' => $lead->state,
                'zip' => $lead->postal_code,
                'country' => $lead->country,
            ]);

            $propertyId = $property->id;
        }

        return [$customer->id, $propertyId];
    }
}
