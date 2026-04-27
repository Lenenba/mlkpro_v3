<?php

namespace App\Services\Prospects;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProspectCustomerMigrationVerificationService
{
    public function __construct(
        private readonly ProspectCustomerMigrationAnalysisService $analysisService,
    ) {}

    /**
     * @return array{
     *     verification_id:string,
     *     batch_id:string,
     *     account_id:int|null,
     *     generated_at:string,
     *     source_summary_path:string,
     *     report_path:string,
     *     segment_path:string,
     *     source_migrated_count:int,
     *     source_failed_count:int,
     *     migrated_customers_checked:int,
     *     verified_customers:int,
     *     customers_with_issues:int,
     *     issue_counts:array<string,int>,
     *     remaining_eligible_count:int,
     *     remaining_ambiguous_count:int,
     *     remaining_eligible_reason_counts:array<string,int>,
     *     remaining_ambiguous_reason_counts:array<string,int>,
     *     remaining_eligible_samples:array<int,array<string,mixed>>,
     *     qualification_samples:array<int,array<string,mixed>>,
     *     consistency_issue_samples:array<int,array<string,mixed>>,
     *     source_failure_samples:array<int,array<string,mixed>>
     * }
     */
    public function verify(?string $batchId = null, ?int $accountId = null, int $sampleLimit = 10): array
    {
        $sampleLimit = max(1, $sampleLimit);
        $sourceSummary = $this->resolveSourceSummary($batchId, $accountId);
        $resolvedAccountId = is_numeric($accountId)
            ? (int) $accountId
            : (is_numeric($sourceSummary['account_id'] ?? null) ? (int) $sourceSummary['account_id'] : null);

        $verificationId = 'prospect-migration-verification-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6));
        $sourceSummaryPath = (string) ($sourceSummary['summary_path'] ?? '');
        $journalDirectory = $sourceSummaryPath !== ''
            ? str_replace('\\', '/', dirname($sourceSummaryPath))
            : 'prospect-migrations/'.$verificationId;
        $reportPath = $journalDirectory.'/verification.json';
        $segmentPath = $journalDirectory.'/verification-segments.csv';

        $segmentHandle = fopen('php://temp', 'w+');
        fputcsv($segmentHandle, [
            'segment_type',
            'customer_id',
            'customer_name',
            'reason',
            'issue_codes',
            'details',
        ]);

        $issueCounts = [];
        $consistencyIssueSamples = [];
        $verifiedCustomers = 0;
        $customersWithIssues = 0;

        foreach ((array) ($sourceSummary['results'] ?? []) as $row) {
            $issues = $this->verifyMigratedCustomer($row);

            if ($issues['issue_codes'] === []) {
                $verifiedCustomers++;

                continue;
            }

            $customersWithIssues++;

            foreach ($issues['issue_codes'] as $issueCode) {
                $issueCounts[$issueCode] = (int) ($issueCounts[$issueCode] ?? 0) + 1;
            }

            if (count($consistencyIssueSamples) < $sampleLimit) {
                $consistencyIssueSamples[] = $issues;
            }

            fputcsv($segmentHandle, [
                'needs_correction',
                (int) ($issues['legacy_customer_id'] ?? 0),
                (string) ($issues['customer_name'] ?? 'Customer'),
                (string) ($issues['reason'] ?? 'migration'),
                implode('|', (array) ($issues['issue_codes'] ?? [])),
                json_encode($issues['details'] ?? [], JSON_UNESCAPED_SLASHES),
            ]);
        }

        ksort($issueCounts);

        $excludedCustomerIds = $this->migratedCustomerIdsForScope($resolvedAccountId, $sourceSummary);
        $remaining = $this->collectRemainingSegments($resolvedAccountId, $excludedCustomerIds, $sampleLimit, $segmentHandle);

        $report = [
            'verification_id' => $verificationId,
            'batch_id' => (string) ($sourceSummary['batch_id'] ?? 'unknown-batch'),
            'account_id' => $resolvedAccountId,
            'generated_at' => now()->toISOString(),
            'source_summary_path' => $sourceSummaryPath,
            'report_path' => $reportPath,
            'segment_path' => $segmentPath,
            'source_migrated_count' => (int) ($sourceSummary['migrated_count'] ?? 0),
            'source_failed_count' => (int) ($sourceSummary['failed_count'] ?? 0),
            'migrated_customers_checked' => count((array) ($sourceSummary['results'] ?? [])),
            'verified_customers' => $verifiedCustomers,
            'customers_with_issues' => $customersWithIssues,
            'issue_counts' => $issueCounts,
            'remaining_eligible_count' => (int) ($remaining['eligible_count'] ?? 0),
            'remaining_ambiguous_count' => (int) ($remaining['ambiguous_count'] ?? 0),
            'remaining_eligible_reason_counts' => (array) ($remaining['eligible_reason_counts'] ?? []),
            'remaining_ambiguous_reason_counts' => (array) ($remaining['ambiguous_reason_counts'] ?? []),
            'remaining_eligible_samples' => (array) ($remaining['eligible_samples'] ?? []),
            'qualification_samples' => (array) ($remaining['ambiguous_samples'] ?? []),
            'consistency_issue_samples' => $consistencyIssueSamples,
            'source_failure_samples' => array_slice((array) ($sourceSummary['failures'] ?? []), 0, $sampleLimit),
        ];

        rewind($segmentHandle);
        $segmentContents = stream_get_contents($segmentHandle) ?: '';
        fclose($segmentHandle);

        Storage::disk('local')->put($segmentPath, $segmentContents);
        Storage::disk('local')->put($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

        return $report;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{
     *     legacy_customer_id:int,
     *     customer_name:string,
     *     reason:string,
     *     issue_codes:array<int,string>,
     *     details:array<string,mixed>
     * }
     */
    private function verifyMigratedCustomer(array $row): array
    {
        $legacyCustomerId = (int) ($row['legacy_customer_id'] ?? 0);
        $customerName = (string) ($row['customer_name'] ?? ('Customer #'.$legacyCustomerId));
        $reason = (string) ($row['reason'] ?? 'eligible');
        $prospectIds = collect((array) ($row['prospect_ids'] ?? []))
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values();
        $rewiredQuoteRows = collect((array) ($row['rewired_quotes'] ?? []));
        $realStatuses = $this->analysisService->realRequestStatuses();

        $issues = [];
        $details = [
            'legacy_customer_id' => $legacyCustomerId,
            'prospect_ids' => $prospectIds->all(),
            'quote_ids' => $rewiredQuoteRows->pluck('quote_id')->map(fn ($value) => (int) $value)->values()->all(),
        ];

        $prospects = $prospectIds->isEmpty()
            ? collect()
            : LeadRequest::query()->whereIn('id', $prospectIds->all())->get()->keyBy('id');

        foreach ($prospectIds as $prospectId) {
            /** @var LeadRequest|null $prospect */
            $prospect = $prospects->get($prospectId);

            if (! $prospect) {
                $issues[] = 'missing_prospect';
                $details['missing_prospect_ids'][] = $prospectId;

                continue;
            }

            $metaLegacyCustomerId = data_get($prospect->meta, 'migration.legacy_customer_id');
            if (! is_numeric($metaLegacyCustomerId)) {
                $issues[] = 'prospect_missing_migration_meta';
                $details['prospects_missing_meta'][] = $prospectId;
            } elseif ((int) $metaLegacyCustomerId !== $legacyCustomerId) {
                $issues[] = 'prospect_migration_meta_mismatch';
                $details['prospect_meta_mismatches'][] = [
                    'prospect_id' => $prospectId,
                    'expected_legacy_customer_id' => $legacyCustomerId,
                    'actual_legacy_customer_id' => (int) $metaLegacyCustomerId,
                ];
            }

            if (! in_array((string) $prospect->status, $realStatuses, true) && $prospect->customer_id !== null) {
                $issues[] = 'open_prospect_still_has_customer_id';
                $details['open_prospects_with_customer_id'][] = [
                    'prospect_id' => $prospectId,
                    'customer_id' => (int) $prospect->customer_id,
                    'status' => (string) $prospect->status,
                ];
            }
        }

        foreach ($rewiredQuoteRows as $quoteRow) {
            $quoteId = (int) ($quoteRow['quote_id'] ?? 0);
            /** @var Quote|null $quote */
            $quote = Quote::query()->find($quoteId);

            if (! $quote) {
                $issues[] = 'missing_quote';
                $details['missing_quote_ids'][] = $quoteId;

                continue;
            }

            if ($quote->request_id !== $quote->prospect_id) {
                $issues[] = 'quote_request_prospect_mismatch';
                $details['quote_request_prospect_mismatches'][] = [
                    'quote_id' => $quoteId,
                    'request_id' => $quote->request_id,
                    'prospect_id' => $quote->prospect_id,
                ];
            }

            if (! $prospectIds->contains((int) $quote->prospect_id)) {
                $issues[] = 'quote_not_linked_to_expected_prospect';
                $details['quotes_with_unexpected_prospect'][] = [
                    'quote_id' => $quoteId,
                    'prospect_id' => $quote->prospect_id,
                ];
            }

            if ((int) ($quote->customer_id ?? 0) === $legacyCustomerId) {
                $issues[] = 'quote_still_linked_to_legacy_customer';
                $details['quotes_still_linked_to_legacy_customer'][] = $quoteId;
            }

            /** @var LeadRequest|null $linkedProspect */
            $linkedProspect = is_numeric($quote->prospect_id) ? $prospects->get((int) $quote->prospect_id) : null;
            if (
                $linkedProspect
                && ! in_array((string) $linkedProspect->status, $realStatuses, true)
                && $quote->customer_id !== null
            ) {
                $issues[] = 'open_prospect_quote_has_customer_id';
                $details['open_prospect_quotes_with_customer_id'][] = [
                    'quote_id' => $quoteId,
                    'customer_id' => (int) $quote->customer_id,
                    'prospect_id' => (int) $linkedProspect->id,
                    'prospect_status' => (string) $linkedProspect->status,
                ];
            }
        }

        $remainingOpenQuotesOnLegacyCustomer = Quote::query()
            ->where('customer_id', $legacyCustomerId)
            ->whereNull('accepted_at')
            ->where('status', '!=', 'accepted')
            ->whereNull('archived_at')
            ->count();

        if ($remainingOpenQuotesOnLegacyCustomer > 0) {
            $issues[] = 'legacy_customer_still_has_open_quotes';
            $details['remaining_open_quotes_on_legacy_customer'] = $remainingOpenQuotesOnLegacyCustomer;
        }

        $remainingOpenProspectsOnLegacyCustomer = LeadRequest::query()
            ->where('customer_id', $legacyCustomerId)
            ->whereNotIn('status', $realStatuses)
            ->count();

        if ($remainingOpenProspectsOnLegacyCustomer > 0) {
            $issues[] = 'legacy_customer_still_has_attached_prospects';
            $details['remaining_open_prospects_on_legacy_customer'] = $remainingOpenProspectsOnLegacyCustomer;
        }

        $issueCodes = collect($issues)->unique()->values()->all();

        return [
            'legacy_customer_id' => $legacyCustomerId,
            'customer_name' => $customerName,
            'reason' => $reason,
            'issue_codes' => $issueCodes,
            'details' => $details,
        ];
    }

    /**
     * @param  resource  $segmentHandle
     * @return array{
     *     eligible_count:int,
     *     ambiguous_count:int,
     *     eligible_reason_counts:array<string,int>,
     *     ambiguous_reason_counts:array<string,int>,
     *     eligible_samples:array<int,array<string,mixed>>,
     *     ambiguous_samples:array<int,array<string,mixed>>
     * }
     */
    private function collectRemainingSegments(?int $accountId, array $excludedCustomerIds, int $sampleLimit, $segmentHandle): array
    {
        $summary = [
            'eligible_count' => 0,
            'ambiguous_count' => 0,
            'eligible_reason_counts' => [],
            'ambiguous_reason_counts' => [],
            'eligible_samples' => [],
            'ambiguous_samples' => [],
        ];

        $this->analysisService->query($accountId)
            ->when($excludedCustomerIds !== [], fn ($query) => $query->whereNotIn('customers.id', $excludedCustomerIds))
            ->orderBy('customers.id')
            ->chunkById(200, function (Collection $customers) use (&$summary, $sampleLimit, $segmentHandle): void {
                foreach ($customers as $customer) {
                    $analysis = $this->analysisService->classifyCustomer($customer);
                    $bucket = (string) ($analysis['bucket'] ?? '');

                    if (! in_array($bucket, ['eligible', 'ambiguous'], true)) {
                        continue;
                    }

                    $reason = (string) ($analysis['reason'] ?? $bucket);
                    $sample = [
                        'id' => (int) $customer->id,
                        'name' => $this->analysisService->displayNameForCustomer($customer),
                        'reason' => $reason,
                        'signals' => $analysis['signals'] ?? [],
                    ];

                    $summary[$bucket.'_count']++;
                    $reasonKey = $bucket.'_reason_counts';
                    $summary[$reasonKey][$reason] = (int) ($summary[$reasonKey][$reason] ?? 0) + 1;

                    if (count($summary[$bucket.'_samples']) < $sampleLimit) {
                        $summary[$bucket.'_samples'][] = $sample;
                    }

                    fputcsv($segmentHandle, [
                        $bucket === 'ambiguous' ? 'needs_qualification' : 'remaining_eligible',
                        (int) $customer->id,
                        (string) $sample['name'],
                        $reason,
                        '',
                        json_encode($sample['signals'] ?? [], JSON_UNESCAPED_SLASHES),
                    ]);
                }
            }, 'customers.id', 'id');

        ksort($summary['eligible_reason_counts']);
        ksort($summary['ambiguous_reason_counts']);

        return $summary;
    }

    /**
     * @return array<int, int>
     */
    private function migratedCustomerIdsForScope(?int $accountId, array $sourceSummary): array
    {
        $migratedIds = collect((array) ($sourceSummary['results'] ?? []))
            ->pluck('legacy_customer_id')
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int) $value);

        if (! $accountId) {
            return $migratedIds->unique()->values()->all();
        }

        $accountCustomerIds = Customer::query()
            ->where('user_id', $accountId)
            ->pluck('id');

        if ($accountCustomerIds->isEmpty()) {
            return $migratedIds->unique()->values()->all();
        }

        $loggedIds = ActivityLog::query()
            ->where('subject_type', (new Customer)->getMorphClass())
            ->where('action', ProspectCustomerMigrationService::ACTION_CUSTOMER_RECLASSIFIED)
            ->whereIn('subject_id', $accountCustomerIds->all())
            ->pluck('subject_id');

        return $migratedIds
            ->concat($loggedIds)
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSourceSummary(?string $batchId, ?int $accountId): array
    {
        $candidates = collect(Storage::disk('local')->allFiles('prospect-migrations'))
            ->filter(fn (string $path): bool => Str::endsWith(str_replace('\\', '/', $path), '/summary.json'))
            ->map(function (string $path): ?array {
                $payload = json_decode((string) Storage::disk('local')->get($path), true);

                if (! is_array($payload)) {
                    return null;
                }

                $payload['summary_path'] = $payload['summary_path'] ?? str_replace('\\', '/', $path);

                return $payload;
            })
            ->filter()
            ->when($batchId, fn (Collection $rows) => $rows->filter(
                fn (array $row): bool => (string) ($row['batch_id'] ?? '') === $batchId
            ))
            ->when(is_numeric($accountId), fn (Collection $rows) => $rows->filter(
                fn (array $row): bool => (int) ($row['account_id'] ?? 0) === (int) $accountId
            ))
            ->sortByDesc(fn (array $row): string => (string) ($row['started_at'] ?? ''))
            ->values();

        /** @var array<string, mixed>|null $summary */
        $summary = $candidates->first();

        if (! $summary) {
            throw new \RuntimeException('No migration batch summary could be found for this scope.');
        }

        return $summary;
    }
}
