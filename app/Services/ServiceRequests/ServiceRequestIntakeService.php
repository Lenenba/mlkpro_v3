<?php

namespace App\Services\ServiceRequests;

use App\Http\Requests\ServiceRequests\StoreServiceRequestRequest;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Prospect;
use App\Models\Request as LeadRequest;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ProspectNotificationService;
use App\Services\ProspectStatusHistoryService;
use App\Support\Prospects\ProspectIntakeMeta;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceRequestIntakeService
{
    public function __construct(
        private readonly ProspectStatusHistoryService $prospectStatusHistoryService,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array{service_request: ServiceRequest, customer: ?Customer, prospect: ?LeadRequest}
     */
    public function createManual(User $actor, array $validated): array
    {
        $accountId = (int) ($actor->accountOwnerId() ?? $actor->id);
        $relationMode = (string) ($validated['relation_mode'] ?? StoreServiceRequestRequest::RELATION_MODE_NONE);

        return DB::transaction(function () use ($accountId, $actor, $relationMode, $validated): array {
            $customer = null;
            $prospect = null;

            if ($relationMode === StoreServiceRequestRequest::RELATION_MODE_EXISTING_CUSTOMER) {
                $customer = $this->resolveExistingCustomer($accountId, (int) ($validated['customer_id'] ?? 0));
            } elseif ($relationMode === StoreServiceRequestRequest::RELATION_MODE_EXISTING_PROSPECT) {
                $prospect = $this->resolveExistingProspect($accountId, (int) ($validated['prospect_id'] ?? 0));
                $customer = $prospect->customer;
            } elseif ($relationMode === StoreServiceRequestRequest::RELATION_MODE_NEW_PROSPECT) {
                $prospect = $this->createProspectFromServiceRequest($accountId, $actor, $validated);
                $customer = $prospect->customer;
            }

            $serviceRequest = $this->persistServiceRequest(
                accountId: $accountId,
                actor: $actor,
                validated: $validated,
                customer: $customer,
                prospect: $prospect,
                sourceInput: (string) ($validated['source'] ?? ''),
            );

            return [
                'service_request' => $serviceRequest->fresh([
                    'customer:id,company_name,first_name,last_name,email,phone',
                    'prospect:id,customer_id,status,title,contact_name,contact_email,contact_phone',
                ]),
                'customer' => $customer?->fresh(['defaultProperty:id,customer_id,street1,street2,city,state,zip,country,is_default']),
                'prospect' => $prospect?->fresh(['customer:id,company_name,first_name,last_name,email,phone']),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function createFromLead(LeadRequest $lead, array $overrides = []): ServiceRequest
    {
        [$source, $channel] = $this->resolveSourceAndChannel(
            (string) ($overrides['source_input'] ?? $lead->channel ?? '')
        );
        $meta = array_merge(
            [
                'legacy_lead_id' => (int) $lead->id,
                'legacy_lead_status' => (string) $lead->status,
            ],
            Arr::except((array) ($lead->meta ?? []), $this->sourceMetaKeys((array) ($lead->meta ?? []))),
            (array) ($overrides['meta'] ?? [])
        );

        return ServiceRequest::query()->create([
            'user_id' => $lead->user_id,
            'customer_id' => $overrides['customer_id'] ?? $lead->customer_id,
            'prospect_id' => $overrides['prospect_id'] ?? $lead->id,
            'source' => $overrides['source'] ?? $source,
            'channel' => $overrides['channel'] ?? $channel,
            'status' => $overrides['status'] ?? ServiceRequest::STATUS_NEW,
            'request_type' => $overrides['request_type'] ?? data_get($lead->meta, 'request_type'),
            'service_type' => $overrides['service_type'] ?? $lead->service_type,
            'title' => $overrides['title'] ?? $lead->title,
            'description' => $overrides['description'] ?? $lead->description,
            'requester_name' => $overrides['requester_name'] ?? $lead->contact_name,
            'requester_email' => $overrides['requester_email'] ?? $lead->contact_email,
            'requester_phone' => $overrides['requester_phone'] ?? $lead->contact_phone,
            'street1' => $overrides['street1'] ?? $lead->street1,
            'street2' => $overrides['street2'] ?? $lead->street2,
            'city' => $overrides['city'] ?? $lead->city,
            'state' => $overrides['state'] ?? $lead->state,
            'postal_code' => $overrides['postal_code'] ?? $lead->postal_code,
            'country' => $overrides['country'] ?? $lead->country,
            'source_ref' => $overrides['source_ref'] ?? 'lead:'.$lead->id,
            'source_meta' => $overrides['source_meta'] ?? Arr::only((array) ($lead->meta ?? []), $this->sourceMetaKeys((array) ($lead->meta ?? []))),
            'submitted_at' => $overrides['submitted_at'] ?? $lead->created_at ?? now(),
            'accepted_at' => $overrides['accepted_at'] ?? null,
            'completed_at' => $overrides['completed_at'] ?? null,
            'cancelled_at' => $overrides['cancelled_at'] ?? null,
            'meta' => $meta ?: null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function prospectAttributesForDuplicateCheck(array $validated): array
    {
        [$source] = $this->resolveSourceAndChannel((string) ($validated['source'] ?? ''));

        return [
            'title' => $validated['title'] ?? null,
            'service_type' => $validated['service_type'] ?? null,
            'description' => $validated['description'] ?? null,
            'contact_name' => $validated['contact_name'] ?? null,
            'contact_email' => $validated['contact_email'] ?? null,
            'contact_phone' => $validated['contact_phone'] ?? null,
            'street1' => $validated['street1'] ?? null,
            'city' => $validated['city'] ?? null,
            'state' => $validated['state'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'country' => $validated['country'] ?? null,
            'meta' => ProspectIntakeMeta::merge(
                $validated['meta'] ?? null,
                source: $source,
                requestType: data_get($validated, 'meta.request_type') ?? 'service_request',
                contactConsent: data_get($validated, 'meta.contact_consent'),
                marketingConsent: data_get($validated, 'meta.marketing_consent')
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function persistServiceRequest(
        int $accountId,
        User $actor,
        array $validated,
        ?Customer $customer,
        ?LeadRequest $prospect,
        string $sourceInput
    ): ServiceRequest {
        [$source, $channel] = $this->resolveSourceAndChannel($sourceInput);
        $snapshot = $this->requesterSnapshot($validated, $customer, $prospect);
        $meta = array_merge((array) ($validated['meta'] ?? []), array_filter([
            'budget' => array_key_exists('budget', $validated) ? $validated['budget'] : data_get($validated, 'meta.budget'),
            'urgency' => $validated['urgency'] ?? null,
            'is_serviceable' => array_key_exists('is_serviceable', $validated) ? $validated['is_serviceable'] : null,
            'relation_mode' => $validated['relation_mode'] ?? null,
            'created_by_user_id' => $actor->id,
            'created_from' => 'quick_create',
        ], static fn ($value) => $value !== null && $value !== ''));

        $serviceRequest = ServiceRequest::query()->create([
            'user_id' => $accountId,
            'customer_id' => $customer?->id,
            'prospect_id' => $prospect?->id,
            'source' => $source,
            'channel' => $channel,
            'status' => ServiceRequest::STATUS_NEW,
            'request_type' => data_get($validated, 'meta.request_type'),
            'service_type' => $validated['service_type'] ?? null,
            'title' => $validated['title'] ?? $validated['service_type'] ?? $snapshot['name'] ?? null,
            'description' => $validated['description'] ?? null,
            'requester_name' => $snapshot['name'],
            'requester_email' => $snapshot['email'],
            'requester_phone' => $snapshot['phone'],
            'street1' => $validated['street1'] ?? null,
            'street2' => $validated['street2'] ?? null,
            'city' => $validated['city'] ?? null,
            'state' => $validated['state'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'country' => $validated['country'] ?? null,
            'source_ref' => null,
            'source_meta' => null,
            'submitted_at' => now(),
            'meta' => $meta ?: null,
        ]);

        ActivityLog::record($actor, $serviceRequest, 'created', [
            'customer_id' => $customer?->id,
            'prospect_id' => $prospect?->id,
            'source' => $source,
            'channel' => $channel,
        ], 'Service request created');

        return $serviceRequest;
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

    private function resolveExistingProspect(int $accountId, int $prospectId): LeadRequest
    {
        $prospect = Prospect::query()
            ->where('user_id', $accountId)
            ->with('customer:id,company_name,first_name,last_name,email,phone')
            ->find($prospectId);

        if (! $prospect) {
            throw ValidationException::withMessages([
                'prospect_id' => ['The selected prospect is invalid.'],
            ]);
        }

        return $prospect;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function createProspectFromServiceRequest(int $accountId, User $actor, array $validated): LeadRequest
    {
        [$source] = $this->resolveSourceAndChannel((string) ($validated['source'] ?? ''));
        $meta = ProspectIntakeMeta::merge(
            $validated['meta'] ?? null,
            source: $source,
            requestType: data_get($validated, 'meta.request_type') ?? 'service_request',
            contactConsent: data_get($validated, 'meta.contact_consent'),
            marketingConsent: data_get($validated, 'meta.marketing_consent')
        );

        $lead = LeadRequest::query()->create([
            'user_id' => $accountId,
            'customer_id' => null,
            'channel' => $this->leadChannelFromSource($source),
            'status' => LeadRequest::STATUS_NEW,
            'status_updated_at' => now(),
            'last_activity_at' => now(),
            'title' => $validated['title'] ?? $validated['service_type'] ?? $validated['contact_name'] ?? null,
            'service_type' => $validated['service_type'] ?? null,
            'urgency' => $validated['urgency'] ?? null,
            'description' => $validated['description'] ?? null,
            'contact_name' => $validated['contact_name'] ?? null,
            'contact_email' => $validated['contact_email'] ?? null,
            'contact_phone' => $validated['contact_phone'] ?? null,
            'street1' => $validated['street1'] ?? null,
            'street2' => $validated['street2'] ?? null,
            'city' => $validated['city'] ?? null,
            'state' => $validated['state'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'country' => $validated['country'] ?? null,
            'is_serviceable' => $validated['is_serviceable'] ?? null,
            'meta' => $meta ?: null,
        ]);

        ActivityLog::record($actor, $lead, 'created', [
            'source' => 'service_request',
        ], 'Prospect created from service request');
        $this->prospectStatusHistoryService->record($lead, $actor, [
            'to_status' => $lead->status,
            'metadata' => ['source' => 'service_request'],
        ]);
        app(ProspectNotificationService::class)->notifyCreated($lead, $actor);

        return $lead;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{name:?string,email:?string,phone:?string}
     */
    private function requesterSnapshot(array $validated, ?Customer $customer, ?LeadRequest $prospect): array
    {
        $name = $validated['contact_name'] ?? null;
        $email = $validated['contact_email'] ?? null;
        $phone = $validated['contact_phone'] ?? null;

        if (! $name && $prospect) {
            $name = $prospect->contact_name ?: $prospect->title;
        }
        if (! $email && $prospect) {
            $email = $prospect->contact_email;
        }
        if (! $phone && $prospect) {
            $phone = $prospect->contact_phone;
        }

        if (! $name && $customer) {
            $name = trim(($customer->company_name ?: '').' '.trim(($customer->first_name ?: '').' '.($customer->last_name ?: '')));
            $name = trim($name) !== '' ? trim($name) : null;
        }
        if (! $email && $customer) {
            $email = $customer->email;
        }
        if (! $phone && $customer) {
            $phone = $customer->phone;
        }

        return [
            'name' => $name ?: null,
            'email' => $email ?: null,
            'phone' => $phone ?: null,
        ];
    }

    /**
     * @return array{0:string,1:?string}
     */
    private function resolveSourceAndChannel(string $value): array
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'web_form' => ['public_form', 'web'],
            'phone', 'call' => ['manual_admin', 'phone'],
            'email', 'mail' => ['manual_admin', 'email'],
            'whatsapp', 'wa' => ['manual_admin', 'whatsapp'],
            'sms', 'text' => ['manual_admin', 'sms'],
            'qr' => ['public_form', 'qr'],
            'portal' => ['customer_portal', 'portal'],
            'api', 'webhook' => ['api', 'api'],
            'import', 'csv' => ['import', null],
            'ads' => ['campaign', 'ads'],
            'referral' => ['manual_admin', 'referral'],
            'manual', '' => ['manual_admin', null],
            default => ['manual_admin', $normalized !== '' ? $normalized : null],
        };
    }

    private function leadChannelFromSource(string $source): string
    {
        return match ($source) {
            'public_form' => 'web_form',
            'customer_portal' => 'portal',
            'api' => 'api',
            'import' => 'import',
            'campaign' => 'ads',
            default => 'manual',
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<int, string>
     */
    private function sourceMetaKeys(array $meta): array
    {
        return collect(array_keys($meta))
            ->filter(fn (string $key) => str_starts_with($key, 'source_') || in_array($key, [
                'source_kind',
                'source_direction',
                'source_campaign_direction',
            ], true))
            ->values()
            ->all();
    }
}
