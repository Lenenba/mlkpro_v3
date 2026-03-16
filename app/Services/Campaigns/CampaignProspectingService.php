<?php

namespace App\Services\Campaigns;

use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\CampaignProspect;
use App\Models\CampaignProspectActivity;
use App\Models\CampaignProspectBatch;
use App\Models\CampaignRecipient;
use App\Models\Customer;
use App\Models\CustomerOptOut;
use App\Models\Request as LeadRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CampaignProspectingService
{
    /**
     * @return Collection<int, CampaignProspectBatch>
     */
    public function importBatches(
        User $accountOwner,
        User $actor,
        Campaign $campaign,
        array $payload
    ): Collection {
        if ((int) $campaign->user_id !== (int) $accountOwner->id) {
            throw ValidationException::withMessages([
                'campaign' => 'Campaign not found for this tenant.',
            ]);
        }

        if (! $campaign->usesProspecting()) {
            throw ValidationException::withMessages([
                'campaign' => 'Prospecting is not enabled on this campaign.',
            ]);
        }

        $campaign->loadMissing(['offers.offer.category', 'channels']);

        $rows = $this->extractRows($payload);
        if ($rows === []) {
            throw ValidationException::withMessages([
                'prospects' => ['No valid prospect rows were found.'],
            ]);
        }

        $batchSize = max(1, min(100, (int) ($payload['batch_size'] ?? 100)));
        $sourceType = (string) ($payload['source_type'] ?? CampaignProspect::SOURCE_IMPORT);
        $sourceReference = $payload['source_reference'] ?? null;
        $nextBatchNumber = (int) $campaign->prospectBatches()->max('batch_number') + 1;
        $offerKeywords = $this->campaignOfferKeywords($campaign);

        $batches = collect();
        foreach (array_chunk($rows, $batchSize) as $offset => $chunk) {
            $batch = DB::transaction(function () use (
                $accountOwner,
                $actor,
                $campaign,
                $chunk,
                $sourceType,
                $sourceReference,
                $nextBatchNumber,
                $offset,
                $offerKeywords
            ): CampaignProspectBatch {
                return $this->createBatch(
                    accountOwner: $accountOwner,
                    actor: $actor,
                    campaign: $campaign,
                    rows: $chunk,
                    sourceType: $sourceType,
                    sourceReference: $sourceReference,
                    batchNumber: $nextBatchNumber + $offset,
                    offerKeywords: $offerKeywords
                );
            });

            $batches->push($batch);
        }

        ActivityLog::record(
            $actor,
            $campaign,
            'campaign_prospects_imported',
            [
                'campaign_id' => $campaign->id,
                'batches_count' => $batches->count(),
                'prospects_count' => count($rows),
                'source_type' => $sourceType,
            ],
            'Campaign prospect batches imported'
        );

        return $batches;
    }

    public function approveBatch(
        User $accountOwner,
        User $actor,
        Campaign $campaign,
        CampaignProspectBatch $batch
    ): CampaignProspectBatch {
        $this->assertBatchReviewAccess($accountOwner, $campaign, $batch);
        $this->assertBatchStatus(
            $batch,
            CampaignProspectBatch::STATUS_ANALYZED,
            'Only analyzed prospect batches can be approved.'
        );

        if ((int) $batch->accepted_count <= 0) {
            throw ValidationException::withMessages([
                'batch' => ['This batch has no accepted prospects to approve.'],
            ]);
        }

        $timestamp = now();

        $batch = DB::transaction(function () use ($actor, $batch, $timestamp): CampaignProspectBatch {
            $this->applyBatchReviewDecision(
                batch: $batch,
                actor: $actor,
                timestamp: $timestamp,
                batchStatus: CampaignProspectBatch::STATUS_APPROVED,
                prospectStatus: CampaignProspect::STATUS_APPROVED,
                decision: 'approved',
                summary: 'Approved during batch review and ready for outreach.'
            );

            return $batch->fresh();
        });

        ActivityLog::record(
            $actor,
            $campaign,
            'campaign_prospect_batch_approved',
            [
                'campaign_id' => $campaign->id,
                'batch_id' => $batch->id,
                'approved_count' => $batch->accepted_count,
            ],
            'Campaign prospect batch approved'
        );

        return $batch;
    }

    public function rejectBatch(
        User $accountOwner,
        User $actor,
        Campaign $campaign,
        CampaignProspectBatch $batch
    ): CampaignProspectBatch {
        $this->assertBatchReviewAccess($accountOwner, $campaign, $batch);
        $this->assertBatchStatus(
            $batch,
            CampaignProspectBatch::STATUS_ANALYZED,
            'Only analyzed prospect batches can be rejected.'
        );

        $timestamp = now();

        $batch = DB::transaction(function () use ($actor, $batch, $timestamp): CampaignProspectBatch {
            $this->applyBatchReviewDecision(
                batch: $batch,
                actor: $actor,
                timestamp: $timestamp,
                batchStatus: CampaignProspectBatch::STATUS_CANCELED,
                prospectStatus: CampaignProspect::STATUS_DISQUALIFIED,
                decision: 'rejected',
                summary: 'Rejected during batch review and removed from outreach.'
            );

            return $batch->fresh();
        });

        ActivityLog::record(
            $actor,
            $campaign,
            'campaign_prospect_batch_rejected',
            [
                'campaign_id' => $campaign->id,
                'batch_id' => $batch->id,
                'rejected_count' => $batch->accepted_count,
            ],
            'Campaign prospect batch rejected'
        );

        return $batch;
    }

    private function createBatch(
        User $accountOwner,
        User $actor,
        Campaign $campaign,
        array $rows,
        string $sourceType,
        ?string $sourceReference,
        int $batchNumber,
        array $offerKeywords
    ): CampaignProspectBatch {
        $batch = CampaignProspectBatch::query()->create([
            'campaign_id' => $campaign->id,
            'user_id' => $accountOwner->id,
            'source_type' => $sourceType,
            'source_reference' => $sourceReference,
            'batch_number' => $batchNumber,
            'input_count' => count($rows),
            'status' => CampaignProspectBatch::STATUS_DRAFT,
        ]);

        $statusCounts = [
            'accepted_count' => 0,
            'rejected_count' => 0,
            'duplicate_count' => 0,
            'blocked_count' => 0,
            'scored_count' => 0,
            'contacted_count' => 0,
            'replied_count' => 0,
            'lead_count' => 0,
            'customer_count' => 0,
        ];
        $matchCounts = [];
        $blockedReasons = [];
        $scoreRanges = [
            'high' => 0,
            'medium' => 0,
            'low' => 0,
        ];
        $highPriorityCount = 0;

        foreach ($rows as $row) {
            $normalized = $this->normalizeRow($row, $sourceType, $sourceReference);
            $analysis = $this->analyzeRow($campaign, $normalized, $offerKeywords);

            $prospect = CampaignProspect::query()->create([
                'campaign_id' => $campaign->id,
                'campaign_prospect_batch_id' => $batch->id,
                'user_id' => $accountOwner->id,
                'source_type' => $normalized['source_type'],
                'source_reference' => $normalized['source_reference'],
                'external_ref' => $normalized['external_ref'],
                'company_name' => $normalized['company_name'],
                'contact_name' => $normalized['contact_name'],
                'first_name' => $normalized['first_name'],
                'last_name' => $normalized['last_name'],
                'email' => $normalized['email'],
                'email_normalized' => $normalized['email_normalized'],
                'phone' => $normalized['phone'],
                'phone_normalized' => $normalized['phone_normalized'],
                'website' => $normalized['website'],
                'website_domain' => $normalized['website_domain'],
                'city' => $normalized['city'],
                'state' => $normalized['state'],
                'country' => $normalized['country'],
                'industry' => $normalized['industry'],
                'company_size' => $normalized['company_size'],
                'tags' => $normalized['tags'],
                'raw_payload' => $normalized['raw_payload'],
                'normalized_payload' => $normalized['normalized_payload'],
                'fit_score' => $analysis['fit_score'],
                'intent_score' => $analysis['intent_score'],
                'priority_score' => $analysis['priority_score'],
                'qualification_summary' => $analysis['qualification_summary'],
                'status' => $analysis['status'],
                'match_status' => $analysis['match_status'],
                'matched_customer_id' => $analysis['matched_customer_id'],
                'matched_lead_id' => $analysis['matched_lead_id'],
                'do_not_contact' => $analysis['do_not_contact'],
                'blocked_reason' => $analysis['blocked_reason'],
                'owner_notes' => $normalized['owner_notes'],
                'metadata' => $analysis['metadata'],
            ]);

            $this->recordActivities($prospect, $campaign, $actor, $analysis);

            if ($analysis['scored']) {
                $statusCounts['scored_count']++;

                if (($analysis['priority_score'] ?? 0) >= 70) {
                    $scoreRanges['high']++;
                    $highPriorityCount++;
                } elseif (($analysis['priority_score'] ?? 0) >= 40) {
                    $scoreRanges['medium']++;
                } else {
                    $scoreRanges['low']++;
                }
            }

            if ($analysis['status'] === CampaignProspect::STATUS_SCORED) {
                $statusCounts['accepted_count']++;
            } elseif ($analysis['status'] === CampaignProspect::STATUS_DUPLICATE) {
                $statusCounts['duplicate_count']++;
            } elseif ($analysis['status'] === CampaignProspect::STATUS_BLOCKED) {
                $statusCounts['blocked_count']++;
                if ($analysis['blocked_reason']) {
                    $blockedReasons[$analysis['blocked_reason']] = (int) ($blockedReasons[$analysis['blocked_reason']] ?? 0) + 1;
                }
            } else {
                $statusCounts['rejected_count']++;
            }

            $matchCounts[$analysis['match_status']] = (int) ($matchCounts[$analysis['match_status']] ?? 0) + 1;
        }

        $batch->forceFill([
            ...$statusCounts,
            'status' => CampaignProspectBatch::STATUS_ANALYZED,
            'analysis_summary' => [
                'high_priority_count' => $highPriorityCount,
                'score_ranges' => $scoreRanges,
                'match_status_counts' => $matchCounts,
                'blocked_reasons' => $blockedReasons,
                'review_required_count' => $statusCounts['accepted_count'],
                'source_type' => $sourceType,
            ],
        ])->save();

        return $batch->fresh();
    }

    private function assertBatchReviewAccess(
        User $accountOwner,
        Campaign $campaign,
        CampaignProspectBatch $batch
    ): void {
        if ((int) $campaign->user_id !== (int) $accountOwner->id) {
            throw ValidationException::withMessages([
                'campaign' => ['Campaign not found for this tenant.'],
            ]);
        }

        if ((int) $batch->campaign_id !== (int) $campaign->id || (int) $batch->user_id !== (int) $accountOwner->id) {
            throw ValidationException::withMessages([
                'batch' => ['Prospect batch not found for this campaign.'],
            ]);
        }
    }

    private function assertBatchStatus(CampaignProspectBatch $batch, string $status, string $message): void
    {
        if ((string) $batch->status === $status) {
            return;
        }

        throw ValidationException::withMessages([
            'batch' => [$message],
        ]);
    }

    private function applyBatchReviewDecision(
        CampaignProspectBatch $batch,
        User $actor,
        $timestamp,
        string $batchStatus,
        string $prospectStatus,
        string $decision,
        string $summary
    ): void {
        $reviewedCount = 0;

        CampaignProspect::query()
            ->where('campaign_prospect_batch_id', $batch->id)
            ->where('status', CampaignProspect::STATUS_SCORED)
            ->orderBy('id')
            ->chunkById(100, function (Collection $prospects) use (
                $actor,
                $batch,
                $timestamp,
                $prospectStatus,
                $decision,
                $summary,
                &$reviewedCount
            ): void {
                foreach ($prospects as $prospect) {
                    $reviewedCount++;

                    $metadata = is_array($prospect->metadata) ? $prospect->metadata : [];
                    $metadata['review'] = [
                        'decision' => $decision,
                        'reviewed_at' => $timestamp->toJSON(),
                        'reviewed_by_user_id' => $actor->id,
                        'batch_id' => $batch->id,
                    ];

                    $qualificationSummary = $prospectStatus === CampaignProspect::STATUS_APPROVED
                        ? 'Approved for outreach after batch review.'
                        : 'Rejected during batch review before outreach.';

                    $prospect->forceFill([
                        'status' => $prospectStatus,
                        'blocked_reason' => $prospectStatus === CampaignProspect::STATUS_DISQUALIFIED
                            ? 'batch_rejected'
                            : $prospect->blocked_reason,
                        'qualification_summary' => $qualificationSummary,
                        'metadata' => $metadata,
                        'last_activity_at' => $timestamp,
                    ])->save();

                    CampaignProspectActivity::query()->create([
                        'campaign_prospect_id' => $prospect->id,
                        'campaign_id' => $batch->campaign_id,
                        'user_id' => $batch->user_id,
                        'actor_user_id' => $actor->id,
                        'activity_type' => $decision === 'approved' ? 'approved' : 'rejected',
                        'summary' => $summary,
                        'payload' => [
                            'batch_id' => $batch->id,
                            'decision' => $decision,
                        ],
                        'occurred_at' => $timestamp,
                    ]);
                }
            }, 'id');

        $summaryData = is_array($batch->analysis_summary) ? $batch->analysis_summary : [];
        $summaryData['review_required_count'] = 0;
        $summaryData['review_decision'] = $decision;
        $summaryData['reviewed_at'] = $timestamp->toJSON();
        $summaryData['reviewed_by_user_id'] = $actor->id;
        $summaryData['reviewed_count'] = $reviewedCount;

        $batch->forceFill([
            'status' => $batchStatus,
            'approved_at' => $decision === 'approved' ? $timestamp : null,
            'approved_by_user_id' => $decision === 'approved' ? $actor->id : null,
            'analysis_summary' => $summaryData,
        ])->save();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractRows(array $payload): array
    {
        if (($payload['file'] ?? null) instanceof UploadedFile) {
            return $this->parseCsvFile($payload['file'], is_array($payload['mapping'] ?? null) ? $payload['mapping'] : []);
        }

        $prospects = $payload['prospects'] ?? [];
        if (! is_array($prospects)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($row) => is_array($row) ? $row : null, $prospects),
            fn ($row) => $row !== null
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseCsvFile(UploadedFile $file, array $mapping): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if (! $handle) {
            throw ValidationException::withMessages([
                'file' => ['Unable to read import file.'],
            ]);
        }

        $headers = fgetcsv($handle);
        if (! $headers) {
            fclose($handle);

            throw ValidationException::withMessages([
                'file' => ['Import file is empty.'],
            ]);
        }

        $headers = array_map('trim', $headers);
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $row = array_pad($row, count($headers), null);
            $rowData = array_combine($headers, $row);
            if (! $rowData) {
                continue;
            }

            $rowLower = array_change_key_case($rowData, CASE_LOWER);
            $rows[] = [
                'company_name' => $this->resolveImportValue($rowLower, $mapping, 'company_name', [
                    'company_name', 'company', 'business', 'organization', 'organisation',
                ]),
                'contact_name' => $this->resolveImportValue($rowLower, $mapping, 'contact_name', [
                    'contact_name', 'name', 'contact', 'full_name',
                ]),
                'first_name' => $this->resolveImportValue($rowLower, $mapping, 'first_name', [
                    'first_name', 'firstname', 'given_name',
                ]),
                'last_name' => $this->resolveImportValue($rowLower, $mapping, 'last_name', [
                    'last_name', 'lastname', 'surname', 'family_name',
                ]),
                'email' => $this->resolveImportValue($rowLower, $mapping, 'email', [
                    'email', 'e-mail',
                ]),
                'phone' => $this->resolveImportValue($rowLower, $mapping, 'phone', [
                    'phone', 'telephone', 'tel', 'mobile', 'cell',
                ]),
                'website' => $this->resolveImportValue($rowLower, $mapping, 'website', [
                    'website', 'site', 'site_web', 'domain', 'url',
                ]),
                'city' => $this->resolveImportValue($rowLower, $mapping, 'city', [
                    'city', 'ville',
                ]),
                'state' => $this->resolveImportValue($rowLower, $mapping, 'state', [
                    'state', 'province', 'region',
                ]),
                'country' => $this->resolveImportValue($rowLower, $mapping, 'country', [
                    'country', 'pays',
                ]),
                'industry' => $this->resolveImportValue($rowLower, $mapping, 'industry', [
                    'industry', 'sector', 'vertical',
                ]),
                'company_size' => $this->resolveImportValue($rowLower, $mapping, 'company_size', [
                    'company_size', 'size', 'employees', 'headcount',
                ]),
                'tags' => $this->resolveImportValue($rowLower, $mapping, 'tags', [
                    'tags', 'labels', 'keywords',
                ]),
                'external_ref' => $this->resolveImportValue($rowLower, $mapping, 'external_ref', [
                    'external_ref', 'external_id', 'id', 'source_id',
                ]),
                'owner_notes' => $this->resolveImportValue($rowLower, $mapping, 'owner_notes', [
                    'owner_notes', 'notes', 'description', 'message',
                ]),
                'metadata' => [
                    'csv_row' => $rowLower,
                ],
            ];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row, string $sourceType, ?string $sourceReference): array
    {
        $contactName = trim((string) ($row['contact_name'] ?? ''));
        $firstName = trim((string) ($row['first_name'] ?? ''));
        $lastName = trim((string) ($row['last_name'] ?? ''));

        if ($contactName === '' && ($firstName !== '' || $lastName !== '')) {
            $contactName = trim($firstName.' '.$lastName);
        }

        if ($contactName !== '' && ($firstName === '' || $lastName === '')) {
            [$derivedFirst, $derivedLast] = $this->splitContactName($contactName);
            $firstName = $firstName !== '' ? $firstName : $derivedFirst;
            $lastName = $lastName !== '' ? $lastName : $derivedLast;
        }

        $email = trim((string) ($row['email'] ?? ''));
        $phone = trim((string) ($row['phone'] ?? ''));
        $website = trim((string) ($row['website'] ?? ''));
        $emailNormalized = $this->normalizeEmail($email);
        $phoneNormalized = $this->normalizePhone($phone);
        $websiteDomain = $this->normalizeDomain($website);
        $tags = $this->normalizeTags($row['tags'] ?? []);
        $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];

        $normalizedPayload = array_filter([
            'email_normalized' => $emailNormalized,
            'phone_normalized' => $phoneNormalized,
            'website_domain' => $websiteDomain,
            'tags' => $tags,
        ], fn ($value) => $value !== null && $value !== [] && $value !== '');

        if ($metadata !== []) {
            $normalizedPayload['metadata'] = $metadata;
        }

        return [
            'source_type' => $sourceType,
            'source_reference' => trim((string) ($row['source_reference'] ?? $sourceReference ?: '')) ?: null,
            'external_ref' => trim((string) ($row['external_ref'] ?? '')) ?: null,
            'company_name' => trim((string) ($row['company_name'] ?? '')) ?: null,
            'contact_name' => $contactName !== '' ? $contactName : null,
            'first_name' => $firstName !== '' ? $firstName : null,
            'last_name' => $lastName !== '' ? $lastName : null,
            'email' => $email !== '' ? $email : null,
            'email_normalized' => $emailNormalized,
            'phone' => $phone !== '' ? $phone : null,
            'phone_normalized' => $phoneNormalized,
            'website' => $website !== '' ? $this->normalizeWebsite($website) : null,
            'website_domain' => $websiteDomain,
            'city' => trim((string) ($row['city'] ?? '')) ?: null,
            'state' => trim((string) ($row['state'] ?? '')) ?: null,
            'country' => trim((string) ($row['country'] ?? '')) ?: null,
            'industry' => trim((string) ($row['industry'] ?? '')) ?: null,
            'company_size' => trim((string) ($row['company_size'] ?? '')) ?: null,
            'tags' => $tags !== [] ? $tags : null,
            'owner_notes' => trim((string) ($row['owner_notes'] ?? '')) ?: null,
            'raw_payload' => $row,
            'normalized_payload' => $normalizedPayload,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<int, string>  $offerKeywords
     * @return array<string, mixed>
     */
    private function analyzeRow(Campaign $campaign, array $normalized, array $offerKeywords): array
    {
        if (! $this->hasMinimumIdentity($normalized)) {
            return [
                'status' => CampaignProspect::STATUS_DISQUALIFIED,
                'match_status' => CampaignProspect::MATCH_MANUAL_REVIEW,
                'matched_customer_id' => null,
                'matched_lead_id' => null,
                'fit_score' => null,
                'intent_score' => null,
                'priority_score' => null,
                'qualification_summary' => 'Rejected: insufficient identity data for qualification.',
                'blocked_reason' => 'insufficient_identity',
                'metadata' => [
                    'available_channels' => [],
                    'blocked_channels' => [],
                    'score_reasons' => [],
                    'dedupe_reason' => null,
                ],
                'scored' => false,
                'do_not_contact' => false,
                'activities' => [
                    [
                        'type' => 'batch_imported',
                        'summary' => 'Prospect imported into batch review.',
                        'payload' => [],
                    ],
                    [
                        'type' => 'blocked',
                        'summary' => 'Rejected due to insufficient identity data.',
                        'payload' => ['reason' => 'insufficient_identity'],
                    ],
                ],
            ];
        }

        $dedupe = $this->findDuplicateMatches($campaign, $normalized);
        $contactability = $this->evaluateContactability($campaign, $normalized);
        [$fitScore, $intentScore, $priorityScore, $scoreReasons] = $this->scoreProspect($normalized, $offerKeywords);

        $status = CampaignProspect::STATUS_SCORED;
        $matchStatus = CampaignProspect::MATCH_NONE;
        $matchedCustomerId = null;
        $matchedLeadId = null;
        $blockedReason = null;
        $doNotContact = false;
        $activities = [
            [
                'type' => 'batch_imported',
                'summary' => 'Prospect imported into batch review.',
                'payload' => [
                    'source_type' => $normalized['source_type'],
                ],
            ],
        ];

        if ($dedupe['matched_customer']) {
            $status = CampaignProspect::STATUS_DUPLICATE;
            $matchStatus = CampaignProspect::MATCH_CUSTOMER;
            $matchedCustomerId = $dedupe['matched_customer']->id;
            $activities[] = [
                'type' => 'dedupe_matched',
                'summary' => 'Matched an existing customer.',
                'payload' => ['customer_id' => $matchedCustomerId, 'reason' => $dedupe['reason']],
            ];
        } elseif ($dedupe['matched_lead']) {
            $status = CampaignProspect::STATUS_DUPLICATE;
            $matchStatus = CampaignProspect::MATCH_LEAD;
            $matchedLeadId = $dedupe['matched_lead']->id;
            $activities[] = [
                'type' => 'dedupe_matched',
                'summary' => 'Matched an existing lead.',
                'payload' => ['lead_id' => $matchedLeadId, 'reason' => $dedupe['reason']],
            ];
        } elseif ($dedupe['matched_prospect']) {
            $status = CampaignProspect::STATUS_DUPLICATE;
            $matchStatus = CampaignProspect::MATCH_PROSPECT;
            $activities[] = [
                'type' => 'dedupe_matched',
                'summary' => 'Matched an existing prospect.',
                'payload' => ['prospect_id' => $dedupe['matched_prospect']->id, 'reason' => $dedupe['reason']],
            ];
        } elseif ($contactability['eligible_channels'] === []) {
            $status = CampaignProspect::STATUS_BLOCKED;
            $matchStatus = $contactability['blocked_channels'] !== []
                ? CampaignProspect::MATCH_BLOCKED_DESTINATION
                : CampaignProspect::MATCH_MANUAL_REVIEW;
            $blockedReason = $contactability['blocked_reason'];
            $doNotContact = $contactability['blocked_channels'] !== [];
            $activities[] = [
                'type' => 'blocked',
                'summary' => 'Blocked for outreach review.',
                'payload' => [
                    'reason' => $blockedReason,
                    'blocked_channels' => $contactability['blocked_channels'],
                ],
            ];
        }

        if ($fitScore !== null || $intentScore !== null || $priorityScore !== null) {
            $activities[] = [
                'type' => 'scored',
                'summary' => 'Prospect scored for campaign fit and priority.',
                'payload' => [
                    'fit_score' => $fitScore,
                    'intent_score' => $intentScore,
                    'priority_score' => $priorityScore,
                    'reasons' => $scoreReasons,
                ],
            ];
        }

        return [
            'status' => $status,
            'match_status' => $matchStatus,
            'matched_customer_id' => $matchedCustomerId,
            'matched_lead_id' => $matchedLeadId,
            'fit_score' => $fitScore,
            'intent_score' => $intentScore,
            'priority_score' => $priorityScore,
            'qualification_summary' => $this->qualificationSummary($status, $matchStatus, $contactability, $scoreReasons),
            'blocked_reason' => $blockedReason,
            'metadata' => [
                'available_channels' => $contactability['eligible_channels'],
                'blocked_channels' => $contactability['blocked_channels'],
                'missing_channels' => $contactability['missing_channels'],
                'score_reasons' => $scoreReasons,
                'dedupe_reason' => $dedupe['reason'],
            ],
            'scored' => $fitScore !== null || $intentScore !== null || $priorityScore !== null,
            'do_not_contact' => $doNotContact,
            'activities' => $activities,
        ];
    }

    private function recordActivities(CampaignProspect $prospect, Campaign $campaign, User $actor, array $analysis): void
    {
        foreach ($analysis['activities'] as $activity) {
            CampaignProspectActivity::query()->create([
                'campaign_prospect_id' => $prospect->id,
                'campaign_id' => $campaign->id,
                'user_id' => $campaign->user_id,
                'actor_user_id' => $actor->id,
                'activity_type' => (string) ($activity['type'] ?? 'updated'),
                'summary' => $activity['summary'] ?? null,
                'payload' => is_array($activity['payload'] ?? null) ? $activity['payload'] : null,
                'occurred_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array{matched_customer:?Customer,matched_lead:?LeadRequest,matched_prospect:?CampaignProspect,reason:?string}
     */
    private function findDuplicateMatches(Campaign $campaign, array $normalized): array
    {
        $email = $normalized['email_normalized'];
        $phone = $normalized['phone_normalized'];
        $domain = $normalized['website_domain'];

        $customer = null;
        if ($email || $phone) {
            $customer = Customer::query()
                ->where('user_id', $campaign->user_id)
                ->where(function ($query) use ($email, $phone): void {
                    if ($email) {
                        $query->orWhereRaw('LOWER(email) = ?', [$email]);
                    }
                    if ($phone) {
                        $query->orWhere('phone', $phone);
                    }
                })
                ->first();
        }

        if ($customer) {
            return [
                'matched_customer' => $customer,
                'matched_lead' => null,
                'matched_prospect' => null,
                'reason' => $email ? 'customer_email_match' : 'customer_phone_match',
            ];
        }

        $lead = null;
        if ($email || $phone) {
            $lead = LeadRequest::query()
                ->where('user_id', $campaign->user_id)
                ->where(function ($query) use ($email, $phone): void {
                    if ($email) {
                        $query->orWhereRaw('LOWER(contact_email) = ?', [$email]);
                    }
                    if ($phone) {
                        $query->orWhere('contact_phone', $phone);
                    }
                })
                ->first();
        }

        if ($lead) {
            return [
                'matched_customer' => null,
                'matched_lead' => $lead,
                'matched_prospect' => null,
                'reason' => $email ? 'lead_email_match' : 'lead_phone_match',
            ];
        }

        $prospect = null;
        if ($email || $phone || $domain) {
            $prospectQuery = CampaignProspect::query()
                ->where('user_id', $campaign->user_id);

            if ($email) {
                $prospectQuery->where('email_normalized', $email);
            } elseif ($phone) {
                $prospectQuery->where('phone_normalized', $phone);
            } elseif ($domain) {
                $prospectQuery->where('website_domain', $domain);
            }

            if ($email && $phone) {
                $prospectQuery->orWhere(function ($query) use ($campaign, $phone): void {
                    $query->where('user_id', $campaign->user_id)
                        ->where('phone_normalized', $phone);
                });
            }

            if ($email && $domain) {
                $prospectQuery->orWhere(function ($query) use ($campaign, $domain): void {
                    $query->where('user_id', $campaign->user_id)
                        ->where('website_domain', $domain);
                });
            }

            if (! $email && $phone && $domain) {
                $prospectQuery->orWhere(function ($query) use ($campaign, $domain): void {
                    $query->where('user_id', $campaign->user_id)
                        ->where('website_domain', $domain);
                });
            }

            $prospect = $prospectQuery->latest('id')->first();
        }

        return [
            'matched_customer' => null,
            'matched_lead' => null,
            'matched_prospect' => $prospect,
            'reason' => $prospect ? ($email ? 'prospect_email_match' : ($phone ? 'prospect_phone_match' : 'prospect_domain_match')) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array{eligible_channels: array<int, string>, blocked_channels: array<int, string>, missing_channels: array<int, string>, blocked_reason:?string}
     */
    private function evaluateContactability(Campaign $campaign, array $normalized): array
    {
        $eligible = [];
        $blocked = [];
        $missing = [];

        $enabledChannels = $campaign->channels
            ->where('is_enabled', true)
            ->pluck('channel')
            ->map(fn ($channel) => strtoupper((string) $channel))
            ->unique()
            ->values();

        foreach ($enabledChannels as $channel) {
            $destination = match ($channel) {
                Campaign::CHANNEL_EMAIL => $normalized['email_normalized'],
                Campaign::CHANNEL_SMS, Campaign::CHANNEL_WHATSAPP => $normalized['phone_normalized'],
                default => null,
            };

            if (! $destination) {
                $missing[] = $channel;
                continue;
            }

            $hash = CampaignRecipient::destinationHash($destination);
            $isBlocked = $hash
                ? CustomerOptOut::query()
                    ->where('user_id', $campaign->user_id)
                    ->where('channel', $channel)
                    ->where('destination_hash', $hash)
                    ->exists()
                : false;

            if ($isBlocked) {
                $blocked[] = $channel;
                continue;
            }

            $eligible[] = $channel;
        }

        $blockedReason = null;
        if ($eligible === []) {
            $blockedReason = $blocked !== []
                ? 'opted_out_or_suppressed'
                : 'no_destination_for_enabled_channels';
        }

        return [
            'eligible_channels' => $eligible,
            'blocked_channels' => $blocked,
            'missing_channels' => $missing,
            'blocked_reason' => $blockedReason,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<int, string>  $offerKeywords
     * @return array{0:int,1:int,2:int,3:array<int,string>}
     */
    private function scoreProspect(array $normalized, array $offerKeywords): array
    {
        $fitScore = 0;
        $intentScore = 0;
        $reasons = [];

        if ($normalized['email_normalized']) {
            $fitScore += 15;
            $intentScore += 10;
            $reasons[] = 'valid_email';
        }

        if ($normalized['phone_normalized']) {
            $fitScore += 10;
            $intentScore += 5;
            $reasons[] = 'valid_phone';
        }

        if ($normalized['website_domain']) {
            $fitScore += 10;
            $intentScore += 5;
            $reasons[] = 'has_website';
        }

        if ($normalized['company_name']) {
            $fitScore += 10;
            $reasons[] = 'has_company_name';
        }

        if ($normalized['contact_name']) {
            $fitScore += 5;
            $reasons[] = 'has_contact_name';
        }

        if ($normalized['city'] || $normalized['country']) {
            $fitScore += 5;
            $reasons[] = 'has_location';
        }

        if ($normalized['industry']) {
            $fitScore += 10;
            $reasons[] = 'has_industry';
        }

        $text = $this->prospectText($normalized);
        $keywordMatches = collect($offerKeywords)
            ->filter(fn ($keyword) => $keyword !== '' && str_contains($text, $keyword))
            ->values();

        if ($keywordMatches->isNotEmpty()) {
            $fitScore += min(25, $keywordMatches->count() * 8);
            $intentScore += min(15, $keywordMatches->count() * 4);
            $reasons[] = 'offer_keyword_match';
        }

        $sourceType = strtolower((string) ($normalized['source_type'] ?? ''));
        $intentScore += match ($sourceType) {
            CampaignProspect::SOURCE_LANDING_PAGE => 55,
            CampaignProspect::SOURCE_ADS => 50,
            CampaignProspect::SOURCE_CONNECTOR => 30,
            CampaignProspect::SOURCE_DIRECTORY_API => 25,
            CampaignProspect::SOURCE_CSV => 20,
            CampaignProspect::SOURCE_IMPORT => 20,
            CampaignProspect::SOURCE_MANUAL => 15,
            default => 10,
        };

        if ($this->containsIntentSignal($text)) {
            $intentScore += 15;
            $reasons[] = 'intent_signal';
        }

        $fitScore = max(0, min(100, $fitScore));
        $intentScore = max(0, min(100, $intentScore));
        $priorityScore = (int) round(($fitScore * 0.65) + ($intentScore * 0.35));

        return [$fitScore, $intentScore, max(0, min(100, $priorityScore)), $reasons];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<int, string>  $scoreReasons
     */
    private function qualificationSummary(
        string $status,
        string $matchStatus,
        array $contactability,
        array $scoreReasons
    ): string {
        if ($status === CampaignProspect::STATUS_DUPLICATE) {
            return match ($matchStatus) {
                CampaignProspect::MATCH_CUSTOMER => 'Duplicate: matched an existing customer.',
                CampaignProspect::MATCH_LEAD => 'Duplicate: matched an existing lead.',
                CampaignProspect::MATCH_PROSPECT => 'Duplicate: matched an existing prospect.',
                default => 'Duplicate: matched an existing record.',
            };
        }

        if ($status === CampaignProspect::STATUS_BLOCKED) {
            if (($contactability['blocked_reason'] ?? null) === 'opted_out_or_suppressed') {
                return 'Blocked: all eligible destinations are suppressed or opted out.';
            }

            return 'Blocked: no eligible destination available on enabled channels.';
        }

        if ($status === CampaignProspect::STATUS_DISQUALIFIED) {
            return 'Rejected: insufficient data for campaign qualification.';
        }

        return 'Ready for review: scored for fit and outreach priority ('.implode(', ', $scoreReasons).').';
    }

    /**
     * @return array<int, string>
     */
    private function campaignOfferKeywords(Campaign $campaign): array
    {
        return $campaign->offers
            ->map(function ($offer): array {
                $product = $offer->offer;
                if (! $product) {
                    return [];
                }

                $tags = is_array($product->tags ?? null) ? $product->tags : [];

                return [
                    (string) $product->name,
                    (string) ($product->description ?? ''),
                    (string) ($product->category?->name ?? ''),
                    ...array_map(fn ($tag) => (string) $tag, $tags),
                ];
            })
            ->flatten()
            ->map(fn ($value) => trim(Str::lower((string) $value)))
            ->flatMap(fn ($value) => preg_split('/[^a-z0-9]+/i', $value) ?: [])
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => strlen($value) >= 3)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function hasMinimumIdentity(array $normalized): bool
    {
        return collect([
            $normalized['company_name'] ?? null,
            $normalized['contact_name'] ?? null,
            $normalized['email_normalized'] ?? null,
            $normalized['phone_normalized'] ?? null,
            $normalized['website_domain'] ?? null,
        ])->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty();
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function prospectText(array $normalized): string
    {
        return Str::lower(implode(' ', array_filter([
            $normalized['company_name'] ?? null,
            $normalized['contact_name'] ?? null,
            $normalized['industry'] ?? null,
            $normalized['website_domain'] ?? null,
            is_array($normalized['tags'] ?? null) ? implode(' ', $normalized['tags']) : null,
            $normalized['owner_notes'] ?? null,
        ])));
    }

    private function containsIntentSignal(string $text): bool
    {
        foreach (['quote', 'pricing', 'demo', 'book', 'call', 'urgent', 'buy', 'service'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeEmail(?string $value): ?string
    {
        $candidate = trim((string) $value);
        if ($candidate === '') {
            return null;
        }

        return filter_var($candidate, FILTER_VALIDATE_EMAIL) ? Str::lower($candidate) : null;
    }

    private function normalizePhone(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?: '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00') && strlen($digits) > 2) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 10) {
            $digits = '1'.$digits;
        }

        if (strlen($digits) < 11) {
            return null;
        }

        return '+'.$digits;
    }

    private function normalizeWebsite(?string $value): ?string
    {
        $candidate = trim((string) $value);
        if ($candidate === '') {
            return null;
        }

        if (! str_starts_with(Str::lower($candidate), 'http://') && ! str_starts_with(Str::lower($candidate), 'https://')) {
            $candidate = 'https://'.$candidate;
        }

        return filter_var($candidate, FILTER_VALIDATE_URL) ? $candidate : null;
    }

    private function normalizeDomain(?string $value): ?string
    {
        $website = $this->normalizeWebsite($value);
        if (! $website) {
            return null;
        }

        $host = parse_url($website, PHP_URL_HOST);
        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        return Str::lower(preg_replace('/^www\./i', '', trim($host)) ?: '');
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    private function normalizeTags(mixed $value): array
    {
        if (is_array($value)) {
            return collect($value)
                ->map(fn ($item) => trim((string) $item))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        $text = trim((string) $value);
        if ($text === '') {
            return [];
        }

        return collect(preg_split('/[,;\r\n]+/', $text) ?: [])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitContactName(string $contactName): array
    {
        $parts = preg_split('/\s+/', trim($contactName), 2) ?: [];

        return [
            trim((string) ($parts[0] ?? '')),
            trim((string) ($parts[1] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $mapping
     * @param  array<int, string>  $fallbackKeys
     */
    private function resolveImportValue(array $row, array $mapping, string $target, array $fallbackKeys): ?string
    {
        $candidateKeys = [];
        $mapped = $mapping[$target] ?? null;
        if (is_string($mapped) && trim($mapped) !== '') {
            $candidateKeys[] = Str::lower(trim($mapped));
        }

        foreach ($fallbackKeys as $key) {
            $candidateKeys[] = Str::lower(trim($key));
        }

        foreach (array_unique($candidateKeys) as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }

            $value = trim((string) $row[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
