<?php

namespace App\Services\Prospects;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\User;
use App\Services\ProspectStatusHistoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProspectConversionService
{
    public function __construct(
        private readonly ProspectStatusHistoryService $statusHistoryService,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @param  array{matched_customer_ids?: array<int, int|string>}  $context
     * @return array{customer: Customer, lead: LeadRequest, quote_ids: array<int, int>, mode: string, created: bool}
     */
    public function execute(LeadRequest $lead, array $validated, User $actor, array $context = []): array
    {
        $accountId = (int) ($actor->accountOwnerId() ?? $actor->id);
        $this->assertLeadCanConvert($lead, $accountId);

        $mode = (string) ($validated['mode'] ?? '');
        $matchedCustomerIds = collect($context['matched_customer_ids'] ?? [])
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $created = $mode === 'create_new';

        return DB::transaction(function () use ($accountId, $actor, $created, $lead, $matchedCustomerIds, $mode, $validated) {
            $timestamp = now();
            $customer = $created
                ? $this->createCustomerFromLead($lead, $accountId, $validated)
                : $this->resolveExistingCustomer($accountId, (int) ($validated['customer_id'] ?? 0));

            $customer->loadMissing('defaultProperty');
            $quoteIds = $this->attachLeadQuotesToCustomer($lead, $customer, $accountId);
            $previousStatus = $lead->status;

            $lead->forceFill([
                'customer_id' => $customer->id,
                'status' => LeadRequest::STATUS_CONVERTED,
                'status_updated_at' => $timestamp,
                'last_activity_at' => $timestamp,
                'converted_at' => $timestamp,
                'meta' => $lead->mergeCustomerConversionMeta([
                    'converted_at' => $timestamp->toISOString(),
                    'converted_by_user_id' => $actor->id,
                    'mode' => $mode,
                    'customer_id' => $customer->id,
                    'matched_customer_ids' => $matchedCustomerIds,
                    'quote_ids' => $quoteIds,
                ]),
            ])->save();

            $this->statusHistoryService->record($lead, $actor, [
                'from_status' => $previousStatus,
                'to_status' => LeadRequest::STATUS_CONVERTED,
                'metadata' => [
                    'source' => 'customer_conversion',
                    'mode' => $mode,
                    'customer_id' => $customer->id,
                    'quote_ids' => $quoteIds,
                    'created_customer' => $created,
                ],
            ]);

            ActivityLog::record($actor, $lead, 'converted_to_customer', [
                'mode' => $mode,
                'customer_id' => $customer->id,
                'matched_customer_ids' => $matchedCustomerIds,
                'quote_ids' => $quoteIds,
                'created_customer' => $created,
            ], 'Prospect converted to customer');

            return [
                'customer' => $customer->fresh(['defaultProperty:id,customer_id,street1,street2,city,state,zip,country,is_default']),
                'lead' => $lead->fresh([
                    'customer:id,company_name,first_name,last_name,email,phone',
                    'quote:id,number,status,customer_id,request_id,prospect_id,property_id',
                ]),
                'quote_ids' => $quoteIds,
                'mode' => $mode,
                'created' => $created,
            ];
        });
    }

    private function assertLeadCanConvert(LeadRequest $lead, int $accountId): void
    {
        if ((int) $lead->user_id !== $accountId) {
            throw ValidationException::withMessages([
                'lead' => ['This prospect does not belong to the current account.'],
            ]);
        }

        if ($lead->customer_id) {
            throw ValidationException::withMessages([
                'lead' => ['This prospect is already linked to a customer.'],
            ]);
        }
    }

    private function resolveExistingCustomer(int $accountId, int $customerId): Customer
    {
        $customer = Customer::query()
            ->where('user_id', $accountId)
            ->with(['defaultProperty:id,customer_id,street1,street2,city,state,zip,country,is_default'])
            ->find($customerId);

        if (! $customer) {
            throw ValidationException::withMessages([
                'customer_id' => ['The selected customer is invalid.'],
            ]);
        }

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function createCustomerFromLead(LeadRequest $lead, int $accountId, array $validated): Customer
    {
        $contactName = trim((string) ($validated['contact_name'] ?? $lead->contact_name ?? ''));
        $contactEmail = $this->normalizeEmail($validated['contact_email'] ?? $lead->contact_email);
        $contactPhone = trim((string) ($validated['contact_phone'] ?? $lead->contact_phone ?? '')) ?: null;
        $companyName = trim((string) ($validated['company_name'] ?? $lead->companyName() ?? '')) ?: null;

        if ($contactName === '') {
            throw ValidationException::withMessages([
                'contact_name' => ['A contact name is required to create a customer.'],
            ]);
        }

        if (! $contactEmail) {
            throw ValidationException::withMessages([
                'contact_email' => ['An email address is required to create a customer.'],
            ]);
        }

        $existingEmailCustomer = Customer::query()
            ->whereRaw('LOWER(email) = ?', [$contactEmail])
            ->first();

        if ($existingEmailCustomer) {
            throw ValidationException::withMessages([
                'contact_email' => ['A customer already exists with this email. Link the existing customer instead.'],
            ]);
        }

        [$firstName, $lastName] = $this->splitContactName($contactName, $companyName);

        $customer = Customer::query()->create([
            'user_id' => $accountId,
            'portal_access' => false,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'company_name' => $companyName,
            'email' => $contactEmail,
            'phone' => $contactPhone,
            'salutation' => 'Mr',
        ]);

        $city = trim((string) ($validated['city'] ?? $lead->city ?? '')) ?: null;
        if ($city) {
            $customer->properties()->create([
                'type' => 'physical',
                'is_default' => true,
                'street1' => trim((string) ($validated['street1'] ?? $lead->street1 ?? '')) ?: null,
                'street2' => trim((string) ($validated['street2'] ?? $lead->street2 ?? '')) ?: null,
                'city' => $city,
                'state' => trim((string) ($validated['state'] ?? $lead->state ?? '')) ?: null,
                'zip' => trim((string) ($validated['postal_code'] ?? $lead->postal_code ?? '')) ?: null,
                'country' => trim((string) ($validated['country'] ?? $lead->country ?? '')) ?: null,
            ]);
        }

        return $customer->fresh(['defaultProperty:id,customer_id,street1,street2,city,state,zip,country,is_default']);
    }

    /**
     * @return array<int, int>
     */
    private function attachLeadQuotesToCustomer(LeadRequest $lead, Customer $customer, int $accountId): array
    {
        $defaultPropertyId = $customer->defaultProperty?->id;
        $quotes = Quote::query()
            ->where('user_id', $accountId)
            ->where(function ($query) use ($lead) {
                $query->where('prospect_id', $lead->id)
                    ->orWhere('request_id', $lead->id);
            })
            ->get();

        $updatedQuoteIds = [];

        foreach ($quotes as $quote) {
            $updates = [];

            if (! $quote->customer_id) {
                $updates['customer_id'] = $customer->id;
            }

            if (! $quote->property_id && $defaultPropertyId) {
                $updates['property_id'] = $defaultPropertyId;
            }

            if ($updates === []) {
                continue;
            }

            $quote->forceFill($updates)->save();
            $updatedQuoteIds[] = $quote->id;
        }

        return $updatedQuoteIds;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitContactName(string $contactName, ?string $fallbackLastName = null): array
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($contactName)) ?: []));
        $firstName = $parts[0] ?? null;
        $lastName = count($parts) > 1
            ? implode(' ', array_slice($parts, 1))
            : ($fallbackLastName ?: 'Prospect');

        if (! $firstName) {
            throw ValidationException::withMessages([
                'contact_name' => ['A contact name is required to create a customer.'],
            ]);
        }

        return [$firstName, $lastName];
    }

    private function normalizeEmail(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }
}
