<?php

namespace App\Services\Prospects;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProspectCustomerMigrationService
{
    public const ACTION_CUSTOMER_RECLASSIFIED = 'prospect_customer_reclassified';

    public const ACTION_PROSPECT_LINKED = 'prospect_customer_migration_linked';

    public function __construct(
        private readonly ProspectCustomerMigrationAnalysisService $analysisService,
    ) {}

    /**
     * @return array{
     *     batch_id:string,
     *     account_id:int|null,
     *     started_at:string,
     *     completed_at:string,
     *     journal_directory:string,
     *     summary_path:string,
     *     mapping_path:string,
     *     scanned:int,
     *     real_count:int,
     *     eligible_count:int,
     *     ambiguous_count:int,
     *     migrated_count:int,
     *     failed_count:int,
     *     created_prospects_count:int,
     *     reclassified_existing_requests_count:int,
     *     rewired_quotes_count:int,
     *     customer_records_preserved_count:int,
     *     results:array<int, array<string, mixed>>,
     *     failures:array<int, array<string, mixed>>
     * }
     */
    public function execute(?int $accountId = null, int $chunkSize = 100): array
    {
        $startedAt = now();
        $batchId = 'prospect-migration-'.$startedAt->format('Ymd-His').'-'.Str::lower(Str::random(6));
        $journalDirectory = 'prospect-migrations/'.$batchId;
        $summaryPath = $journalDirectory.'/summary.json';
        $mappingPath = $journalDirectory.'/mappings.csv';

        $mappingHandle = fopen('php://temp', 'w+');
        fputcsv($mappingHandle, [
            'legacy_customer_id',
            'account_id',
            'customer_name',
            'reason',
            'primary_prospect_id',
            'prospect_ids',
            'created_prospect_ids',
            'detached_request_ids',
            'rewired_quote_ids',
            'preserved_customer_record',
            'journal_batch_id',
            'customer_created_at',
            'customer_updated_at',
            'notes',
        ]);

        $summary = [
            'batch_id' => $batchId,
            'account_id' => $accountId,
            'started_at' => $startedAt->toISOString(),
            'completed_at' => $startedAt->toISOString(),
            'journal_directory' => $journalDirectory,
            'summary_path' => $summaryPath,
            'mapping_path' => $mappingPath,
            'scanned' => 0,
            'real_count' => 0,
            'eligible_count' => 0,
            'ambiguous_count' => 0,
            'migrated_count' => 0,
            'failed_count' => 0,
            'created_prospects_count' => 0,
            'reclassified_existing_requests_count' => 0,
            'rewired_quotes_count' => 0,
            'customer_records_preserved_count' => 0,
            'results' => [],
            'failures' => [],
        ];

        $this->analysisService->query($accountId)
            ->orderBy('customers.id')
            ->chunkById($chunkSize, function (Collection $customers) use (&$summary, $mappingHandle, $batchId): void {
                foreach ($customers as $customer) {
                    $classification = $this->analysisService->classifyCustomer($customer);

                    $summary['scanned']++;
                    $summary[$classification['bucket'].'_count']++;

                    if ($classification['bucket'] !== 'eligible') {
                        continue;
                    }

                    try {
                        $result = DB::transaction(
                            fn (): array => $this->migrateCustomer((int) $customer->id, $classification, $batchId)
                        );

                        $summary['migrated_count']++;
                        $summary['created_prospects_count'] += count($result['created_prospect_ids'] ?? []);
                        $summary['reclassified_existing_requests_count'] += count($result['detached_request_ids'] ?? []);
                        $summary['rewired_quotes_count'] += count($result['rewired_quotes'] ?? []);
                        $summary['customer_records_preserved_count']++;
                        $summary['results'][] = $result;

                        fputcsv($mappingHandle, $this->mappingCsvRow($result));
                    } catch (Throwable $throwable) {
                        $summary['failed_count']++;
                        $summary['failures'][] = [
                            'customer_id' => (int) $customer->id,
                            'customer_name' => $this->analysisService->displayNameForCustomer($customer),
                            'reason' => (string) ($classification['reason'] ?? 'eligible'),
                            'message' => $throwable->getMessage(),
                        ];
                    }
                }
            }, 'customers.id', 'id');

        $summary['completed_at'] = now()->toISOString();

        rewind($mappingHandle);
        $mappingContents = stream_get_contents($mappingHandle) ?: '';
        fclose($mappingHandle);

        Storage::disk('local')->put($mappingPath, $mappingContents);
        Storage::disk('local')->put($summaryPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

        return $summary;
    }

    /**
     * @param  array{
     *     bucket:'eligible',
     *     reason:string,
     *     signals:array<string,int|bool>
     * }  $classification
     * @return array<string, mixed>
     */
    private function migrateCustomer(int $customerId, array $classification, string $batchId): array
    {
        /** @var Customer $customer */
        $customer = Customer::query()
            ->with(['defaultProperty:id,customer_id,street1,street2,city,state,zip,country,is_default'])
            ->findOrFail($customerId);

        $customerName = $this->analysisService->displayNameForCustomer($customer);
        $quotes = $this->loadOpenQuotes($customer);
        $existingRequests = $this->loadCandidateRequests($customer, $quotes);
        $primary = $this->resolvePrimaryRequest($existingRequests, $quotes);
        $createdProspectIds = [];

        if (! $primary && $quotes->isNotEmpty()) {
            $primary = $this->createProspectFromCustomer($customer, $quotes, $classification, $batchId, $customerName);
            $createdProspectIds[] = (int) $primary->id;
        }

        if (! $primary) {
            throw new \RuntimeException('No target prospect could be resolved for this customer.');
        }

        $requestQuoteIds = [];
        $rewiredQuotes = [];

        foreach ($quotes as $quote) {
            $targetRequestId = $this->resolveQuoteTargetRequestId($quote, $existingRequests, $primary);
            $rewiredQuotes[] = $this->rewireQuote($quote, $targetRequestId);
            $requestQuoteIds[$targetRequestId] = array_values(array_unique(array_merge(
                $requestQuoteIds[$targetRequestId] ?? [],
                [(int) $quote->id]
            )));
        }

        $allRequests = $existingRequests
            ->concat(collect([$primary]))
            ->unique('id')
            ->values();

        $allRequestIds = $allRequests->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $detachedRequestIds = [];
        $reclassifiedRequests = [];

        foreach ($allRequests as $request) {
            $isCreated = in_array((int) $request->id, $createdProspectIds, true);
            $fromCustomerId = $request->customer_id !== null ? (int) $request->customer_id : null;

            if ($fromCustomerId !== null && ! $isCreated) {
                $detachedRequestIds[] = (int) $request->id;
            }

            $this->applyMigrationMeta(
                $request,
                $customer,
                $classification,
                $batchId,
                $customerName,
                $allRequestIds,
                $requestQuoteIds[(int) $request->id] ?? [],
                (int) $primary->id,
                $isCreated ? 'created_from_quote_only_customer' : 'existing_request_reclassified'
            );

            $reclassifiedRequests[] = [
                'request_id' => (int) $request->id,
                'created' => $isCreated,
                'role' => $isCreated ? 'created_from_quote_only_customer' : 'existing_request_reclassified',
                'from_customer_id' => $fromCustomerId,
                'to_customer_id' => $request->customer_id !== null ? (int) $request->customer_id : null,
                'quote_ids' => $requestQuoteIds[(int) $request->id] ?? [],
            ];

            ActivityLog::record(null, $request, self::ACTION_PROSPECT_LINKED, [
                'batch_id' => $batchId,
                'legacy_customer_id' => $customer->id,
                'primary_prospect_id' => $primary->id,
                'linked_request_ids' => $allRequestIds,
                'quote_ids' => $requestQuoteIds[(int) $request->id] ?? [],
                'request_role' => $isCreated ? 'created_from_quote_only_customer' : 'existing_request_reclassified',
                'reason' => $classification['reason'],
            ], 'Legacy customer reclassified to prospect');
        }

        ActivityLog::record(null, $customer, self::ACTION_CUSTOMER_RECLASSIFIED, [
            'batch_id' => $batchId,
            'reason' => $classification['reason'],
            'primary_prospect_id' => $primary->id,
            'prospect_ids' => $allRequestIds,
            'created_prospect_ids' => $createdProspectIds,
            'detached_request_ids' => $detachedRequestIds,
            'rewired_quote_ids' => array_map(
                static fn (array $row): int => (int) ($row['quote_id'] ?? 0),
                $rewiredQuotes
            ),
            'preserved_customer_record' => true,
        ], 'Legacy customer reclassified to prospect');

        return [
            'batch_id' => $batchId,
            'legacy_customer_id' => (int) $customer->id,
            'account_id' => (int) $customer->user_id,
            'customer_name' => $customerName,
            'reason' => $classification['reason'],
            'primary_prospect_id' => (int) $primary->id,
            'prospect_ids' => $allRequestIds,
            'created_prospect_ids' => $createdProspectIds,
            'detached_request_ids' => $detachedRequestIds,
            'reclassified_requests' => $reclassifiedRequests,
            'rewired_quotes' => $rewiredQuotes,
            'customer_record_preserved' => true,
            'customer_created_at' => optional($customer->created_at)->toISOString(),
            'customer_updated_at' => optional($customer->updated_at)->toISOString(),
        ];
    }

    /**
     * @return Collection<int, Quote>
     */
    private function loadOpenQuotes(Customer $customer): Collection
    {
        return Quote::query()
            ->where('user_id', $customer->user_id)
            ->where('customer_id', $customer->id)
            ->whereNull('accepted_at')
            ->where('status', '!=', 'accepted')
            ->whereNull('archived_at')
            ->orderBy('id')
            ->get([
                'id',
                'user_id',
                'customer_id',
                'request_id',
                'prospect_id',
                'job_title',
                'status',
                'accepted_at',
                'archived_at',
                'created_at',
                'updated_at',
                'last_sent_at',
                'last_viewed_at',
                'last_followed_up_at',
            ]);
    }

    /**
     * @return Collection<int, LeadRequest>
     */
    private function loadCandidateRequests(Customer $customer, Collection $quotes): Collection
    {
        $linkedRequestIds = $quotes
            ->flatMap(fn (Quote $quote): array => array_values(array_filter([
                is_numeric($quote->prospect_id) ? (int) $quote->prospect_id : null,
                is_numeric($quote->request_id) ? (int) $quote->request_id : null,
            ])))
            ->unique()
            ->values();

        return LeadRequest::query()
            ->where('user_id', $customer->user_id)
            ->whereNotIn('status', $this->analysisService->realRequestStatuses())
            ->where(function ($query) use ($customer, $linkedRequestIds): void {
                $query->where('customer_id', $customer->id);

                if ($linkedRequestIds->isNotEmpty()) {
                    $query->orWhereIn('id', $linkedRequestIds->all());
                }
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    private function resolvePrimaryRequest(Collection $requests, Collection $quotes): ?LeadRequest
    {
        if ($requests->isEmpty()) {
            return null;
        }

        $linkedRequestIds = $quotes
            ->flatMap(fn (Quote $quote): array => array_values(array_filter([
                is_numeric($quote->prospect_id) ? (int) $quote->prospect_id : null,
                is_numeric($quote->request_id) ? (int) $quote->request_id : null,
            ])))
            ->unique()
            ->values();

        /** @var LeadRequest|null $request */
        $request = $requests->sortBy(function (LeadRequest $candidate) use ($linkedRequestIds): string {
            $hasLinkedQuote = $linkedRequestIds->contains((int) $candidate->id) ? 0 : 1;
            $createdAt = optional($candidate->created_at)->format('YmdHis.u') ?? '99999999999999.999999';

            return sprintf('%d-%s-%010d', $hasLinkedQuote, $createdAt, (int) $candidate->id);
        })->first();

        return $request;
    }

    private function resolveQuoteTargetRequestId(Quote $quote, Collection $requests, LeadRequest $primary): int
    {
        $candidateIds = collect([
            is_numeric($quote->prospect_id) ? (int) $quote->prospect_id : null,
            is_numeric($quote->request_id) ? (int) $quote->request_id : null,
        ])->filter(fn ($id) => is_int($id) && $id > 0)->values();

        foreach ($candidateIds as $candidateId) {
            if ($requests->contains(fn (LeadRequest $request): bool => (int) $request->id === $candidateId)) {
                return $candidateId;
            }
        }

        return (int) $primary->id;
    }

    /**
     * @param  array{
     *     bucket:'eligible',
     *     reason:string,
     *     signals:array<string,int|bool>
     * }  $classification
     */
    private function createProspectFromCustomer(
        Customer $customer,
        Collection $quotes,
        array $classification,
        string $batchId,
        string $customerName
    ): LeadRequest {
        $createdAt = $this->earliestTimestamp([
            $customer->created_at,
            ...$quotes->pluck('created_at')->all(),
        ]) ?? now();
        $updatedAt = $this->latestTimestamp([
            $customer->updated_at,
            ...$quotes->pluck('updated_at')->all(),
            ...$quotes->pluck('last_sent_at')->all(),
            ...$quotes->pluck('last_viewed_at')->all(),
            ...$quotes->pluck('last_followed_up_at')->all(),
        ]) ?? $createdAt;

        $payload = array_filter([
            'user_id' => (int) $customer->user_id,
            'customer_id' => null,
            'channel' => 'migration',
            'status' => $this->resolveProspectStatus($quotes),
            'status_updated_at' => $updatedAt,
            'last_activity_at' => $updatedAt,
            'title' => $this->resolveProspectTitle($customer, $quotes, $customerName),
            'description' => $customer->description,
            'contact_name' => $this->resolveContactName($customer, $customerName),
            'contact_email' => $this->normalizeNullableString($customer->email),
            'contact_phone' => $this->normalizeNullableString($customer->phone),
            'country' => $this->normalizeNullableString($customer->defaultProperty?->country),
            'state' => $this->normalizeNullableString($customer->defaultProperty?->state),
            'city' => $this->normalizeNullableString($customer->defaultProperty?->city),
            'street1' => $this->normalizeNullableString($customer->defaultProperty?->street1),
            'street2' => $this->normalizeNullableString($customer->defaultProperty?->street2),
            'postal_code' => $this->normalizeNullableString($customer->defaultProperty?->zip),
            'meta' => array_filter([
                'company_name' => $this->normalizeNullableString($customer->company_name),
                'migration' => [
                    'source' => 'legacy_customer_reclassification',
                    'batch_id' => $batchId,
                    'legacy_customer_id' => (int) $customer->id,
                    'legacy_customer_name' => $customerName,
                    'legacy_customer_email' => $this->normalizeNullableString($customer->email),
                    'reason' => $classification['reason'],
                    'migrated_at' => now()->toISOString(),
                    'request_role' => 'created_from_quote_only_customer',
                ],
            ], static fn ($value) => $value !== null && $value !== ''),
        ], static fn ($value) => $value !== null && $value !== '');

        $lead = LeadRequest::query()->create($payload);

        $this->persistWithoutTouchingTimestamps($lead, [
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'status_updated_at' => $updatedAt,
            'last_activity_at' => $updatedAt,
        ]);

        /** @var LeadRequest $fresh */
        $fresh = $lead->fresh();

        return $fresh;
    }

    /**
     * @param  array{
     *     bucket:'eligible',
     *     reason:string,
     *     signals:array<string,int|bool>
     * }  $classification
     * @param  array<int, int>  $allRequestIds
     * @param  array<int, int>  $quoteIds
     */
    private function applyMigrationMeta(
        LeadRequest $request,
        Customer $customer,
        array $classification,
        string $batchId,
        string $customerName,
        array $allRequestIds,
        array $quoteIds,
        int $primaryProspectId,
        string $requestRole
    ): void {
        $meta = (array) ($request->meta ?? []);

        if (! filled(data_get($meta, 'company_name')) && filled($customer->company_name)) {
            data_set($meta, 'company_name', $customer->company_name);
        }

        data_set($meta, 'migration', array_merge(
            (array) data_get($meta, 'migration', []),
            [
                'source' => 'legacy_customer_reclassification',
                'batch_id' => $batchId,
                'legacy_customer_id' => (int) $customer->id,
                'legacy_customer_name' => $customerName,
                'legacy_customer_email' => $this->normalizeNullableString($customer->email),
                'legacy_customer_number' => $this->normalizeNullableString($customer->number),
                'reason' => $classification['reason'],
                'migrated_at' => now()->toISOString(),
                'primary_prospect_id' => $primaryProspectId,
                'linked_request_ids' => $allRequestIds,
                'quote_ids' => $quoteIds,
                'request_role' => $requestRole,
                'customer_record_preserved' => true,
            ]
        ));

        $this->persistWithoutTouchingTimestamps($request, [
            'customer_id' => null,
            'meta' => $meta,
        ]);
    }

    /**
     * @return array<string, int|null>
     */
    private function rewireQuote(Quote $quote, int $targetRequestId): array
    {
        $before = [
            'quote_id' => (int) $quote->id,
            'from_customer_id' => $quote->customer_id !== null ? (int) $quote->customer_id : null,
            'from_request_id' => $quote->request_id !== null ? (int) $quote->request_id : null,
            'from_prospect_id' => $quote->prospect_id !== null ? (int) $quote->prospect_id : null,
        ];

        $this->persistWithoutTouchingTimestamps($quote, [
            'customer_id' => null,
            'request_id' => $targetRequestId,
            'prospect_id' => $targetRequestId,
        ]);

        return [
            ...$before,
            'to_customer_id' => $quote->customer_id !== null ? (int) $quote->customer_id : null,
            'to_request_id' => $quote->request_id !== null ? (int) $quote->request_id : null,
            'to_prospect_id' => $quote->prospect_id !== null ? (int) $quote->prospect_id : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<int, string|int|null>
     */
    private function mappingCsvRow(array $result): array
    {
        return [
            (int) ($result['legacy_customer_id'] ?? 0),
            (int) ($result['account_id'] ?? 0),
            (string) ($result['customer_name'] ?? 'Customer'),
            (string) ($result['reason'] ?? 'eligible'),
            (int) ($result['primary_prospect_id'] ?? 0),
            $this->encodeForCsv($result['prospect_ids'] ?? []),
            $this->encodeForCsv($result['created_prospect_ids'] ?? []),
            $this->encodeForCsv($result['detached_request_ids'] ?? []),
            $this->encodeForCsv(collect($result['rewired_quotes'] ?? [])->pluck('quote_id')->values()->all()),
            ! empty($result['customer_record_preserved']) ? 'true' : 'false',
            (string) ($result['batch_id'] ?? ''),
            (string) ($result['customer_created_at'] ?? ''),
            (string) ($result['customer_updated_at'] ?? ''),
            'Customer record preserved for rollback; prospect links rewired.',
        ];
    }

    private function resolveProspectStatus(Collection $quotes): string
    {
        $statuses = $quotes
            ->pluck('status')
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => strtolower(trim($value)))
            ->values();

        if ($statuses->contains('sent')) {
            return LeadRequest::STATUS_QUOTE_SENT;
        }

        if ($statuses->isNotEmpty() && $statuses->every(fn (string $status): bool => $status === 'declined')) {
            return LeadRequest::STATUS_LOST;
        }

        return LeadRequest::STATUS_QUALIFIED;
    }

    private function resolveProspectTitle(Customer $customer, Collection $quotes, string $customerName): string
    {
        $quoteTitle = $quotes
            ->pluck('job_title')
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->first();

        if (is_string($quoteTitle) && trim($quoteTitle) !== '') {
            return trim($quoteTitle);
        }

        $companyName = trim((string) ($customer->company_name ?? ''));
        if ($companyName !== '') {
            return $companyName;
        }

        return $customerName !== '' ? $customerName : 'Migrated prospect';
    }

    private function resolveContactName(Customer $customer, string $customerName): ?string
    {
        $fullName = trim(implode(' ', array_filter([
            trim((string) ($customer->first_name ?? '')),
            trim((string) ($customer->last_name ?? '')),
        ])));

        if ($fullName !== '') {
            return $fullName;
        }

        return $customerName !== '' ? $customerName : null;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function latestTimestamp(array $values): ?Carbon
    {
        return collect($values)
            ->map(fn (mixed $value): ?Carbon => $this->asCarbon($value))
            ->filter()
            ->sortBy(fn (Carbon $value): string => $value->format('YmdHis.u'))
            ->last();
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function earliestTimestamp(array $values): ?Carbon
    {
        return collect($values)
            ->map(fn (mixed $value): ?Carbon => $this->asCarbon($value))
            ->filter()
            ->sortBy(fn (Carbon $value): string => $value->format('YmdHis.u'))
            ->first();
    }

    private function asCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function persistWithoutTouchingTimestamps(Model $model, array $updates): void
    {
        $dirty = [];

        foreach ($updates as $key => $value) {
            if (! $this->valuesEqual($model->getAttribute($key), $value)) {
                $dirty[$key] = $value;
            }
        }

        if ($dirty === []) {
            return;
        }

        $model->timestamps = false;
        $model->forceFill($dirty)->save();
        $model->timestamps = true;
    }

    private function valuesEqual(mixed $left, mixed $right): bool
    {
        $leftDate = $this->asCarbon($left);
        $rightDate = $this->asCarbon($right);

        if ($leftDate || $rightDate) {
            return $leftDate?->toISOString() === $rightDate?->toISOString();
        }

        return $left == $right;
    }

    private function encodeForCsv(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '[]';
    }
}
