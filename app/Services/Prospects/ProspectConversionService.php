<?php

namespace App\Services\Prospects;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\ServiceRequest;
use App\Models\Task;
use App\Models\User;
use App\Services\ProspectNotificationService;
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
        $duplicateMatches = $created
            ? $this->detectExistingCustomerConflicts($lead, $accountId, $validated)
            : [];

        if ($duplicateMatches !== []) {
            throw ValidationException::withMessages($this->duplicateConflictErrors($duplicateMatches));
        }

        $matchedCustomerIds = collect([
            ...$matchedCustomerIds,
            ...array_map(
                fn (array $match): int => (int) ($match['id'] ?? 0),
                $duplicateMatches
            ),
        ])
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        return DB::transaction(function () use ($accountId, $actor, $created, $lead, $matchedCustomerIds, $mode, $validated) {
            $timestamp = now();
            $customer = $created
                ? $this->createCustomerFromLead($lead, $accountId, $validated)
                : $this->resolveExistingCustomer($accountId, (int) ($validated['customer_id'] ?? 0));

            $customer->loadMissing('defaultProperty');
            $quoteIds = $this->attachLeadQuotesToCustomer($lead, $customer, $accountId);
            $this->attachLeadOperationalRecordsToCustomer($lead, $customer, $accountId);
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
                    'matched_customer_ids' => $matchedCustomerIds,
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

            ActivityLog::record($actor, $lead, 'status_changed', [
                'from_status' => $previousStatus,
                'to_status' => $lead->status,
                'source' => 'customer_conversion',
                'mode' => $mode,
                'customer_id' => $customer->id,
                'matched_customer_ids' => $matchedCustomerIds,
                'quote_ids' => $quoteIds,
                'created_customer' => $created,
            ], 'Prospect status changed');

            app(ProspectNotificationService::class)->notifyConverted($lead, $actor);

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

    public function ensureCustomerForQuoteAcceptance(Quote $quote, ?User $actor = null, bool $strict = false): ?Customer
    {
        if ($quote->customer_id) {
            return Customer::query()->find($quote->customer_id);
        }

        $lead = $quote->prospect ?: $quote->request;
        if (! $lead) {
            if ($strict) {
                throw ValidationException::withMessages([
                    'customer_id' => ['A customer or prospect is required before accepting this quote.'],
                ]);
            }

            return null;
        }

        $accountId = (int) ($actor?->accountOwnerId() ?? $quote->user_id ?? $lead->user_id);
        if ((int) $lead->user_id !== $accountId) {
            if ($strict) {
                throw ValidationException::withMessages([
                    'lead' => ['This prospect does not belong to the quote account.'],
                ]);
            }

            return null;
        }

        return DB::transaction(function () use ($accountId, $actor, $lead, $quote, $strict) {
            $lead->refresh();
            $quote->refresh();

            if ($quote->customer_id) {
                return Customer::query()->find($quote->customer_id);
            }

            if ($lead->customer_id) {
                $customer = Customer::query()
                    ->where('user_id', $accountId)
                    ->find($lead->customer_id);

                if ($customer) {
                    $this->attachAcceptedQuoteRecordsToCustomer($lead, $quote, $customer, $accountId);

                    return $customer;
                }
            }

            $customer = $this->resolveAutomaticCustomerForLead($lead, $accountId);
            $created = false;

            if (! $customer) {
                $contactEmail = $this->normalizeEmail($lead->contact_email);
                if (! $contactEmail) {
                    if ($strict) {
                        throw ValidationException::withMessages([
                            'contact_email' => ['An email address is required to convert this prospect into a customer.'],
                        ]);
                    }

                    return null;
                }

                try {
                    $customer = $this->createCustomerFromLead($lead, $accountId, [
                        'contact_name' => $lead->contact_name ?: $lead->title ?: 'Accepted Prospect',
                        'contact_email' => $contactEmail,
                        'contact_phone' => $lead->contact_phone,
                        'company_name' => $lead->companyName() ?: $lead->title ?: $lead->service_type,
                        'street1' => $lead->street1,
                        'street2' => $lead->street2,
                        'city' => $lead->city,
                        'state' => $lead->state,
                        'postal_code' => $lead->postal_code,
                        'country' => $lead->country,
                    ]);
                } catch (ValidationException $exception) {
                    if ($strict) {
                        throw $exception;
                    }

                    return null;
                }
                $created = true;
            }

            $quoteIds = $this->attachAcceptedQuoteRecordsToCustomer($lead, $quote, $customer, $accountId);
            $timestamp = now();

            $lead->forceFill([
                'customer_id' => $customer->id,
                'last_activity_at' => $timestamp,
                'converted_at' => $lead->converted_at ?? $timestamp,
                'meta' => $lead->mergeCustomerConversionMeta([
                    'converted_at' => ($lead->converted_at ?? $timestamp)->toISOString(),
                    'converted_by_user_id' => $actor?->id,
                    'mode' => $created ? 'auto_create_on_quote_acceptance' : 'auto_link_on_quote_acceptance',
                    'customer_id' => $customer->id,
                    'quote_ids' => $quoteIds,
                    'created_customer' => $created,
                    'source' => 'quote_acceptance',
                ]),
            ])->save();

            ActivityLog::record($actor, $lead, 'converted_to_customer', [
                'mode' => $created ? 'auto_create_on_quote_acceptance' : 'auto_link_on_quote_acceptance',
                'customer_id' => $customer->id,
                'quote_ids' => $quoteIds,
                'created_customer' => $created,
                'source' => 'quote_acceptance',
            ], 'Prospect converted to customer after quote acceptance');

            ActivityLog::record($actor, $quote, 'prospect_attached_to_customer', [
                'request_id' => $lead->id,
                'customer_id' => $customer->id,
                'created_customer' => $created,
                'source' => 'quote_acceptance',
            ], 'Quote attached to customer after prospect acceptance');

            $quote->refresh();

            return $customer->fresh(['defaultProperty:id,customer_id,street1,street2,city,state,zip,country,is_default']);
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

        $globalEmailCustomer = Customer::query()
            ->whereRaw('LOWER(email) = ?', [$contactEmail])
            ->first(['id', 'user_id']);

        if ($globalEmailCustomer) {
            $message = (int) $globalEmailCustomer->user_id === $accountId
                ? 'A customer already exists with this email address. Link the existing customer instead.'
                : 'This email address is already used by another customer record.';

            throw ValidationException::withMessages([
                'contact_email' => [$message],
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
     * @return array<int, int>
     */
    private function attachAcceptedQuoteRecordsToCustomer(LeadRequest $lead, Quote $quote, Customer $customer, int $accountId): array
    {
        $customer->loadMissing('defaultProperty');
        $quoteIds = $this->attachLeadQuotesToCustomer($lead, $customer, $accountId);
        $this->attachLeadOperationalRecordsToCustomer($lead, $customer, $accountId);

        $updates = [];
        if (! $quote->customer_id) {
            $updates['customer_id'] = $customer->id;
        }

        $defaultPropertyId = $customer->defaultProperty?->id;
        if (! $quote->property_id && $defaultPropertyId) {
            $updates['property_id'] = $defaultPropertyId;
        }

        if ($updates !== []) {
            $quote->forceFill($updates)->save();
            $quoteIds[] = $quote->id;
        }

        return collect($quoteIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function attachLeadOperationalRecordsToCustomer(LeadRequest $lead, Customer $customer, int $accountId): void
    {
        ServiceRequest::query()
            ->where('user_id', $accountId)
            ->where('prospect_id', $lead->id)
            ->whereNull('customer_id')
            ->update(['customer_id' => $customer->id]);

        Task::query()
            ->where('account_id', $accountId)
            ->where('request_id', $lead->id)
            ->whereNull('customer_id')
            ->update(['customer_id' => $customer->id]);
    }

    private function resolveAutomaticCustomerForLead(LeadRequest $lead, int $accountId): ?Customer
    {
        $contactEmail = $this->normalizeEmail($lead->contact_email);
        if ($contactEmail) {
            $customer = Customer::query()
                ->where('user_id', $accountId)
                ->whereRaw('LOWER(email) = ?', [$contactEmail])
                ->with('defaultProperty:id,customer_id,street1,street2,city,state,zip,country,is_default')
                ->first();

            if ($customer) {
                return $customer;
            }
        }

        $contactPhone = $this->normalizePhone($lead->contact_phone);
        if ($contactPhone) {
            $phoneTail = substr($contactPhone, -7);
            $customer = Customer::query()
                ->where('user_id', $accountId)
                ->where('phone', 'like', '%'.$phoneTail.'%')
                ->with('defaultProperty:id,customer_id,street1,street2,city,state,zip,country,is_default')
                ->get()
                ->first(fn (Customer $customer): bool => $this->normalizePhone($customer->phone) === $contactPhone);

            if ($customer) {
                return $customer;
            }
        }

        $companyName = $this->normalizeText($lead->companyName());
        if ($companyName) {
            return Customer::query()
                ->where('user_id', $accountId)
                ->whereRaw('LOWER(company_name) = ?', [$companyName])
                ->with('defaultProperty:id,customer_id,street1,street2,city,state,zip,country,is_default')
                ->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, array{id:int,reasons:array<int, string>}>
     */
    private function detectExistingCustomerConflicts(LeadRequest $lead, int $accountId, array $validated): array
    {
        $contactEmail = $this->normalizeEmail($validated['contact_email'] ?? $lead->contact_email);
        $contactPhone = $this->normalizePhone($validated['contact_phone'] ?? $lead->contact_phone);
        $companyName = $this->normalizeText($validated['company_name'] ?? $lead->companyName());

        if (! $contactEmail && ! $contactPhone && ! $companyName) {
            return [];
        }

        $phoneTail = $contactPhone ? substr($contactPhone, -7) : null;

        return Customer::query()
            ->where('user_id', $accountId)
            ->where(function ($query) use ($companyName, $contactEmail, $phoneTail) {
                if ($contactEmail) {
                    $query->orWhereRaw('LOWER(email) = ?', [$contactEmail]);
                }

                if ($phoneTail) {
                    $query->orWhere('phone', 'like', '%'.$phoneTail.'%');
                }

                if ($companyName) {
                    $query->orWhereRaw('LOWER(company_name) = ?', [$companyName]);
                }
            })
            ->get(['id', 'company_name', 'email', 'phone'])
            ->map(function (Customer $customer) use ($companyName, $contactEmail, $contactPhone) {
                $reasons = [];

                if ($contactEmail && $this->normalizeEmail($customer->email) === $contactEmail) {
                    $reasons[] = 'email_exact';
                }

                if ($contactPhone && $this->normalizePhone($customer->phone) === $contactPhone) {
                    $reasons[] = 'phone_exact';
                }

                if ($companyName && $this->normalizeText($customer->company_name) === $companyName) {
                    $reasons[] = 'company_exact';
                }

                if ($reasons === []) {
                    return null;
                }

                return [
                    'id' => $customer->id,
                    'reasons' => $reasons,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{id:int,reasons:array<int, string>}>  $matches
     * @return array<string, array<int, string>>
     */
    private function duplicateConflictErrors(array $matches): array
    {
        $reasonCodes = collect($matches)
            ->flatMap(fn (array $match): array => (array) ($match['reasons'] ?? []))
            ->unique()
            ->values();

        $errors = [
            'customer_id' => ['A matching customer already exists. Link the existing customer instead.'],
        ];

        if ($reasonCodes->contains('email_exact')) {
            $errors['contact_email'] = ['A customer already exists with this email address. Link the existing customer instead.'];
        }

        if ($reasonCodes->contains('phone_exact')) {
            $errors['contact_phone'] = ['A customer already exists with this phone number. Link the existing customer instead.'];
        }

        if ($reasonCodes->contains('company_exact')) {
            $errors['company_name'] = ['A customer already exists with this company name. Link the existing customer instead.'];
        }

        return $errors;
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

    private function normalizePhone(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        if ($digits !== '' && strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) >= 7 ? $digits : null;
    }

    private function normalizeText(mixed $value): ?string
    {
        $normalized = preg_replace('/\s+/', ' ', strtolower(trim((string) $value))) ?? '';

        return $normalized !== '' ? $normalized : null;
    }
}
