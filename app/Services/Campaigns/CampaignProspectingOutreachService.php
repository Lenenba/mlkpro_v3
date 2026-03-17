<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\CampaignProspect;
use App\Models\CampaignProspectActivity;
use App\Models\CampaignProspectBatch;
use App\Models\CampaignRecipient;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CampaignProspectingOutreachService
{
    /**
     * @var array<int, string>
     */
    private const TERMINAL_STATUSES = [
        CampaignProspect::STATUS_REPLIED,
        CampaignProspect::STATUS_QUALIFIED,
        CampaignProspect::STATUS_CONVERTED_TO_LEAD,
        CampaignProspect::STATUS_CONVERTED_TO_CUSTOMER,
        CampaignProspect::STATUS_DUPLICATE,
        CampaignProspect::STATUS_BLOCKED,
        CampaignProspect::STATUS_DISQUALIFIED,
        CampaignProspect::STATUS_DO_NOT_CONTACT,
    ];

    /**
     * @var array<int, string>
     */
    private const MANUAL_STATUS_UPDATES = [
        CampaignProspect::STATUS_APPROVED,
        CampaignProspect::STATUS_DISQUALIFIED,
        CampaignProspect::STATUS_BLOCKED,
        CampaignProspect::STATUS_DO_NOT_CONTACT,
        CampaignProspect::STATUS_DUPLICATE,
        CampaignProspect::STATUS_REPLIED,
        CampaignProspect::STATUS_QUALIFIED,
    ];

    public function __construct(
        private readonly ConsentService $consentService,
        private readonly FatigueLimiter $fatigueLimiter,
    ) {}

    public function usesProspectingAudience(?Campaign $campaign): bool
    {
        return $campaign instanceof Campaign
            && $campaign->usesProspecting()
            && $campaign->resolvedCampaignDirection() === Campaign::DIRECTION_PROSPECTING_OUTBOUND;
    }

    /**
     * @param  array<int, string>  $enabledChannels
     * @return array{eligible: array<int, array<string, mixed>>, blocked: array<int, array<string, mixed>>, counts: array<string, mixed>}
     */
    public function resolveAudience(Campaign $campaign, array $enabledChannels = []): array
    {
        $campaign->loadMissing(['channels', 'user']);
        $channels = $this->normalizedEnabledChannels($campaign, $enabledChannels);

        if ($channels === []) {
            return $this->emptyResolution();
        }

        $now = now();
        $sequenceConfig = $this->sequenceConfig($campaign);
        $this->refreshFollowUpDueStates($campaign, $sequenceConfig, $now);

        $eligible = [];
        $blocked = [];
        $eligibleByChannel = [];
        $blockedByChannel = [];
        $blockedByReason = [];
        $dedupe = [];

        CampaignProspect::query()
            ->where('campaign_id', $campaign->id)
            ->where('user_id', $campaign->user_id)
            ->whereIn('status', [
                CampaignProspect::STATUS_APPROVED,
                CampaignProspect::STATUS_CONTACTED,
                CampaignProspect::STATUS_FOLLOW_UP_DUE,
            ])
            ->orderByDesc('priority_score')
            ->orderBy('id')
            ->chunkById(200, function (Collection $prospects) use (
                $campaign,
                $channels,
                $sequenceConfig,
                $now,
                &$eligible,
                &$blocked,
                &$eligibleByChannel,
                &$blockedByChannel,
                &$blockedByReason,
                &$dedupe
            ): void {
                foreach ($prospects as $prospect) {
                    if (! $this->isProspectSendable($prospect, $sequenceConfig, $now)) {
                        continue;
                    }

                    $availableChannels = $this->availableChannels($prospect, $channels);
                    if ($availableChannels === []) {
                        $this->pushBlocked(
                            $blocked,
                            $blockedByChannel,
                            $blockedByReason,
                            null,
                            'missing_destination',
                            null,
                            null,
                            $prospect
                        );

                        continue;
                    }

                    foreach ($availableChannels as $channel) {
                        $destination = $this->destinationForProspect($prospect, $channel);
                        if (! $destination) {
                            $this->pushBlocked(
                                $blocked,
                                $blockedByChannel,
                                $blockedByReason,
                                $channel,
                                'missing_destination',
                                null,
                                null,
                                $prospect
                            );

                            continue;
                        }

                        if ($prospect->do_not_contact) {
                            $this->pushBlocked(
                                $blocked,
                                $blockedByChannel,
                                $blockedByReason,
                                $channel,
                                'do_not_contact',
                                null,
                                $destination,
                                $prospect
                            );

                            continue;
                        }

                        $consentDecision = $this->consentService->canReceive(
                            $campaign->user,
                            null,
                            $channel,
                            $destination
                        );

                        if (! ($consentDecision['allowed'] ?? false)) {
                            $this->pushBlocked(
                                $blocked,
                                $blockedByChannel,
                                $blockedByReason,
                                $channel,
                                (string) ($consentDecision['reason'] ?? 'consent_denied'),
                                null,
                                $destination,
                                $prospect
                            );

                            continue;
                        }

                        $fatigueDecision = $this->fatigueLimiter->canSend(
                            $campaign->user,
                            null,
                            $channel,
                            $campaign
                        );
                        if (! ($fatigueDecision['allowed'] ?? false)) {
                            $this->pushBlocked(
                                $blocked,
                                $blockedByChannel,
                                $blockedByReason,
                                $channel,
                                (string) ($fatigueDecision['reason'] ?? 'fatigue_denied'),
                                null,
                                (string) ($consentDecision['destination'] ?? $destination),
                                $prospect
                            );

                            continue;
                        }

                        $normalizedDestination = (string) ($consentDecision['destination'] ?? $destination);
                        $destinationHash = CampaignRecipient::destinationHash($normalizedDestination)
                            ?: hash('sha256', $channel.':'.$normalizedDestination);
                        $dedupeKey = $channel.'|'.$destinationHash;

                        if (isset($dedupe[$dedupeKey])) {
                            $this->pushBlocked(
                                $blocked,
                                $blockedByChannel,
                                $blockedByReason,
                                $channel,
                                'duplicate_destination',
                                null,
                                $normalizedDestination,
                                $prospect
                            );

                            continue;
                        }

                        $dedupe[$dedupeKey] = true;
                        $eligibleByChannel[$channel] = (int) ($eligibleByChannel[$channel] ?? 0) + 1;
                        $eligible[] = [
                            'customer_id' => null,
                            'channel' => $channel,
                            'destination' => $normalizedDestination,
                            'destination_hash' => $destinationHash,
                            'metadata' => [
                                'source' => 'prospecting',
                                'campaign_direction' => $campaign->resolvedCampaignDirection(),
                                'prospect_id' => $prospect->id,
                                'prospect_batch_id' => $prospect->campaign_prospect_batch_id,
                                'prospect_status' => $prospect->status,
                                'outreach_phase' => $this->phaseLabelForProspect($prospect, $sequenceConfig),
                                'prospect_context' => $this->contextExtrasFromProspect($prospect, $campaign),
                                'prospect_destinations' => $this->prospectDestinations($prospect),
                                'sequence' => $this->dispatchSequenceSnapshot($prospect, $sequenceConfig, $channel),
                            ],
                        ];
                    }
                }
            }, 'id');

        return [
            'eligible' => $eligible,
            'blocked' => $blocked,
            'counts' => [
                'total_eligible' => count($eligible),
                'eligible_by_channel' => $eligibleByChannel,
                'blocked_by_channel' => $blockedByChannel,
                'blocked_by_reason' => $blockedByReason,
            ],
        ];
    }

    public function assertCanQueueRun(Campaign $campaign): void
    {
        if (! $this->usesProspectingAudience($campaign)) {
            return;
        }

        $resolved = $this->resolveAudience($campaign);
        if (($resolved['counts']['total_eligible'] ?? 0) > 0) {
            return;
        }

        throw ValidationException::withMessages([
            'prospects' => ['No approved or due prospects are available for outreach on this campaign.'],
        ]);
    }

    public function sampleProspects(Campaign $campaign, int $limit = 3): Collection
    {
        if (! $this->usesProspectingAudience($campaign)) {
            return collect();
        }

        $sequenceConfig = $this->sequenceConfig($campaign);
        $this->refreshFollowUpDueStates($campaign, $sequenceConfig, now());

        return CampaignProspect::query()
            ->where('campaign_id', $campaign->id)
            ->where('user_id', $campaign->user_id)
            ->whereIn('status', [
                CampaignProspect::STATUS_APPROVED,
                CampaignProspect::STATUS_CONTACTED,
                CampaignProspect::STATUS_FOLLOW_UP_DUE,
            ])
            ->orderByDesc('priority_score')
            ->limit(max(1, $limit))
            ->get()
            ->filter(fn (CampaignProspect $prospect) => $this->isProspectSendable($prospect, $sequenceConfig, now()))
            ->values();
    }

    /**
     * @return array<string, string>
     */
    public function contextExtrasFromProspect(CampaignProspect $prospect, Campaign $campaign): array
    {
        $language = trim((string) data_get($prospect->metadata, 'preferred_language', $campaign->locale ?? 'en'));

        return [
            'firstName' => (string) ($prospect->first_name ?? ''),
            'lastName' => (string) ($prospect->last_name ?? ''),
            'companyName' => (string) ($prospect->company_name ?? ''),
            'city' => (string) ($prospect->city ?? ''),
            'preferredLanguage' => $language !== '' ? $language : 'en',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function buildContextExtrasFromRecipient(CampaignRecipient $recipient): array
    {
        $metadata = is_array($recipient->metadata) ? $recipient->metadata : [];
        $context = is_array($metadata['prospect_context'] ?? null) ? $metadata['prospect_context'] : [];

        return [
            'firstName' => (string) ($context['firstName'] ?? ''),
            'lastName' => (string) ($context['lastName'] ?? ''),
            'companyName' => (string) ($context['companyName'] ?? ''),
            'city' => (string) ($context['city'] ?? ''),
            'preferredLanguage' => (string) ($context['preferredLanguage'] ?? ''),
        ];
    }

    public function destinationForFallback(CampaignRecipient $recipient, string $channel): ?string
    {
        $metadata = is_array($recipient->metadata) ? $recipient->metadata : [];
        $destinations = is_array($metadata['prospect_destinations'] ?? null) ? $metadata['prospect_destinations'] : [];
        $target = strtoupper(trim($channel));
        $value = trim((string) ($destinations[$target] ?? ''));

        return $value !== '' ? $value : null;
    }

    public function syncRecipientEvent(
        CampaignRecipient $recipient,
        string $eventType,
        array $payload = [],
        ?Carbon $occurredAt = null
    ): void {
        $metadata = is_array($recipient->metadata) ? $recipient->metadata : [];
        if (($metadata['source'] ?? null) !== 'prospecting') {
            return;
        }

        $prospectId = isset($metadata['prospect_id']) && is_numeric($metadata['prospect_id'])
            ? (int) $metadata['prospect_id']
            : null;
        if (! $prospectId) {
            return;
        }

        $prospect = CampaignProspect::query()->find($prospectId);
        if (! $prospect || (int) $prospect->campaign_id !== (int) $recipient->campaign_id) {
            return;
        }

        $timestamp = $occurredAt ?: now();
        $campaign = $recipient->campaign ?: Campaign::query()->find($recipient->campaign_id);
        if (! $campaign) {
            return;
        }

        DB::transaction(function () use ($prospect, $recipient, $campaign, $eventType, $payload, $timestamp): void {
            $activity = $this->activityDescriptor($recipient, $eventType, $payload);
            CampaignProspectActivity::query()->create([
                'campaign_prospect_id' => $prospect->id,
                'campaign_id' => $prospect->campaign_id,
                'campaign_run_id' => $recipient->campaign_run_id,
                'campaign_recipient_id' => $recipient->id,
                'user_id' => $prospect->user_id,
                'activity_type' => $activity['type'],
                'channel' => strtoupper((string) $recipient->channel),
                'summary' => $activity['summary'],
                'payload' => $activity['payload'],
                'occurred_at' => $timestamp,
            ]);

            $updates = [
                'last_activity_at' => $timestamp,
            ];
            $sequence = $this->sequenceState($prospect);
            $sequenceConfig = $this->sequenceConfig($campaign);

            if ($eventType === CampaignEvent::EVENT_SENT) {
                $updates['first_contacted_at'] = $prospect->first_contacted_at ?: $timestamp;
                $updates['last_contacted_at'] = $timestamp;
                $updates['status'] = CampaignProspect::STATUS_CONTACTED;

                $dispatchStep = max(
                    1,
                    (int) data_get($recipient->metadata, 'sequence.dispatch_step', max(1, (int) ($sequence['current_step'] ?? 0) + 1))
                );
                $currentStep = max((int) ($sequence['current_step'] ?? 0), $dispatchStep);
                $nextFollowUpAt = $this->nextFollowUpAt($timestamp, $currentStep, $sequenceConfig);
                $sequence = array_merge($sequence, [
                    'enabled' => (bool) $sequenceConfig['enabled'],
                    'max_steps' => (int) $sequenceConfig['max_steps'],
                    'current_step' => $currentStep,
                    'current_phase' => $this->phaseLabelForStep($currentStep),
                    'last_sent_at' => $timestamp->toJSON(),
                    'last_sent_channel' => strtoupper((string) $recipient->channel),
                    'next_follow_up_at' => $nextFollowUpAt?->toJSON(),
                    'has_pending_follow_up' => $nextFollowUpAt !== null,
                    'stopped_at' => $nextFollowUpAt === null ? ($sequence['stopped_at'] ?? $timestamp->toJSON()) : null,
                    'stop_reason' => $nextFollowUpAt === null ? ($sequence['stop_reason'] ?? 'sequence_completed') : null,
                ]);
            } elseif ($eventType === CampaignEvent::EVENT_UNSUBSCRIBE) {
                $updates['status'] = CampaignProspect::STATUS_DO_NOT_CONTACT;
                $updates['do_not_contact'] = true;
                $updates['blocked_reason'] = 'campaign_unsubscribe';
                $sequence = array_merge($sequence, [
                    'next_follow_up_at' => null,
                    'has_pending_follow_up' => false,
                    'stopped_at' => $timestamp->toJSON(),
                    'stop_reason' => 'unsubscribe',
                ]);
            } elseif ($eventType === CampaignEvent::EVENT_CONVERTED) {
                $conversionType = strtolower((string) ($payload['conversion_type'] ?? ''));
                $conversionId = isset($payload['conversion_id']) && is_numeric($payload['conversion_id'])
                    ? (int) $payload['conversion_id']
                    : null;

                if ($conversionId && in_array($conversionType, ['lead', 'request'], true)) {
                    $updates['converted_to_lead_id'] = $conversionId;
                    $updates['status'] = CampaignProspect::STATUS_CONVERTED_TO_LEAD;
                } elseif ($conversionId && $conversionType === 'customer') {
                    $updates['converted_to_customer_id'] = $conversionId;
                    $updates['status'] = CampaignProspect::STATUS_CONVERTED_TO_CUSTOMER;
                }

                $sequence = array_merge($sequence, [
                    'next_follow_up_at' => null,
                    'has_pending_follow_up' => false,
                    'stopped_at' => $timestamp->toJSON(),
                    'stop_reason' => 'converted',
                ]);
            }

            if (in_array($eventType, [CampaignEvent::EVENT_OPENED, CampaignEvent::EVENT_CLICKED], true)) {
                $sequence = array_merge($sequence, [
                    'last_engagement_at' => $timestamp->toJSON(),
                ]);
            }

            if ($eventType === CampaignEvent::EVENT_FAILED) {
                $sequence = array_merge($sequence, [
                    'last_failure_at' => $timestamp->toJSON(),
                    'last_failure_reason' => (string) ($payload['reason'] ?? ''),
                ]);
            }

            $prospectMetadata = is_array($prospect->metadata) ? $prospect->metadata : [];
            $prospectMetadata['sequence'] = $sequence;
            $prospect->forceFill(array_merge($updates, [
                'metadata' => $prospectMetadata,
            ]))->save();

            $this->refreshBatchCounters((int) $prospect->campaign_prospect_batch_id);
        });
    }

    public function updateProspectStatus(
        User $accountOwner,
        User $actor,
        Campaign $campaign,
        CampaignProspect $prospect,
        string $status,
        ?string $reason = null,
        ?string $note = null
    ): CampaignProspect {
        $normalizedStatus = strtolower(trim($status));
        if (! in_array($normalizedStatus, self::MANUAL_STATUS_UPDATES, true)) {
            throw ValidationException::withMessages([
                'status' => ['Unsupported prospect status update.'],
            ]);
        }

        $this->assertProspectAccess($accountOwner, $campaign, $prospect);
        $timestamp = now();

        return DB::transaction(function () use (
            $accountOwner,
            $actor,
            $campaign,
            $prospect,
            $normalizedStatus,
            $reason,
            $note,
            $timestamp
        ): CampaignProspect {
            $metadata = is_array($prospect->metadata) ? $prospect->metadata : [];
            $sequence = $this->sequenceState($prospect);

            if ($normalizedStatus === CampaignProspect::STATUS_APPROVED) {
                if ($prospect->do_not_contact) {
                    throw ValidationException::withMessages([
                        'status' => ['A do-not-contact prospect cannot be re-approved for outreach.'],
                    ]);
                }

                if (! in_array($prospect->status, [
                    CampaignProspect::STATUS_SCORED,
                    CampaignProspect::STATUS_APPROVED,
                    CampaignProspect::STATUS_BLOCKED,
                    CampaignProspect::STATUS_DISQUALIFIED,
                    CampaignProspect::STATUS_DUPLICATE,
                ], true)) {
                    throw ValidationException::withMessages([
                        'status' => ['Only review-stage prospects can be approved manually.'],
                    ]);
                }

                $sequence = array_merge($sequence, [
                    'stopped_at' => null,
                    'stop_reason' => null,
                ]);
            } else {
                $sequence = array_merge($sequence, [
                    'next_follow_up_at' => null,
                    'has_pending_follow_up' => false,
                    'stopped_at' => $timestamp->toJSON(),
                    'stop_reason' => $normalizedStatus,
                ]);
            }

            $metadata['sequence'] = $sequence;
            $metadata['manual_status'] = [
                'status' => $normalizedStatus,
                'reason' => $reason,
                'note' => $note,
                'updated_at' => $timestamp->toJSON(),
                'updated_by_user_id' => $actor->id,
            ];

            $blockedReason = $normalizedStatus === CampaignProspect::STATUS_APPROVED
                ? null
                : ($reason ?: match ($normalizedStatus) {
                    CampaignProspect::STATUS_DUPLICATE => 'manual_duplicate',
                    CampaignProspect::STATUS_BLOCKED => 'manual_blocked',
                    CampaignProspect::STATUS_DISQUALIFIED => 'manual_disqualified',
                    CampaignProspect::STATUS_DO_NOT_CONTACT => 'manual_do_not_contact',
                    default => $prospect->blocked_reason,
                });

            $updates = [
                'status' => $normalizedStatus,
                'blocked_reason' => $blockedReason,
                'metadata' => $metadata,
                'last_activity_at' => $timestamp,
            ];

            if (in_array($normalizedStatus, [CampaignProspect::STATUS_REPLIED, CampaignProspect::STATUS_QUALIFIED], true)) {
                $updates['last_replied_at'] = $timestamp;
            }

            if ($normalizedStatus === CampaignProspect::STATUS_DO_NOT_CONTACT) {
                $updates['do_not_contact'] = true;

                foreach ($this->prospectDestinations($prospect) as $channel => $destination) {
                    $this->consentService->revoke(
                        $accountOwner,
                        null,
                        $channel,
                        $destination,
                        'campaign_prospecting',
                        'manual_do_not_contact',
                        [
                            'campaign_id' => $campaign->id,
                            'prospect_id' => $prospect->id,
                            'actor_user_id' => $actor->id,
                        ]
                    );
                }
            }

            $prospect->forceFill($updates)->save();

            CampaignProspectActivity::query()->create([
                'campaign_prospect_id' => $prospect->id,
                'campaign_id' => $campaign->id,
                'user_id' => $campaign->user_id,
                'actor_user_id' => $actor->id,
                'activity_type' => 'manual_status_updated',
                'summary' => $normalizedStatus === CampaignProspect::STATUS_APPROVED
                    ? 'Prospect approved for outreach.'
                    : sprintf('Prospect marked as %s.', str_replace('_', ' ', $normalizedStatus)),
                'payload' => [
                    'status' => $normalizedStatus,
                    'reason' => $reason,
                    'note' => $note,
                ],
                'occurred_at' => $timestamp,
            ]);

            $this->refreshBatchCounters((int) $prospect->campaign_prospect_batch_id);

            return $prospect->fresh(['activities']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function activityDescriptor(CampaignRecipient $recipient, string $eventType, array $payload): array
    {
        $phase = trim((string) data_get($recipient->metadata, 'outreach_phase', ''));
        $channel = strtoupper((string) $recipient->channel);

        return match ($eventType) {
            CampaignEvent::EVENT_QUEUED => [
                'type' => 'outreach_queued',
                'summary' => sprintf('%s outreach queued for send.', $phase !== '' ? ucfirst(str_replace('_', ' ', $phase)) : 'Prospect'),
                'payload' => $payload,
            ],
            CampaignEvent::EVENT_SENT => [
                'type' => 'outreach_sent',
                'summary' => sprintf('%s sent via %s.', $phase !== '' ? ucfirst(str_replace('_', ' ', $phase)) : 'Outreach', $channel),
                'payload' => $payload,
            ],
            CampaignEvent::EVENT_DELIVERED => [
                'type' => 'outreach_delivered',
                'summary' => sprintf('Message delivered via %s.', $channel),
                'payload' => $payload,
            ],
            CampaignEvent::EVENT_OPENED => [
                'type' => 'outreach_opened',
                'summary' => sprintf('Message opened on %s.', $channel),
                'payload' => $payload,
            ],
            CampaignEvent::EVENT_CLICKED => [
                'type' => 'outreach_clicked',
                'summary' => sprintf('CTA clicked from %s.', $channel),
                'payload' => $payload,
            ],
            CampaignEvent::EVENT_FAILED => [
                'type' => 'outreach_failed',
                'summary' => sprintf('Send failed on %s.', $channel),
                'payload' => $payload,
            ],
            CampaignEvent::EVENT_UNSUBSCRIBE => [
                'type' => 'outreach_unsubscribed',
                'summary' => sprintf('Prospect unsubscribed from %s.', $channel),
                'payload' => $payload,
            ],
            CampaignEvent::EVENT_CONVERTED => [
                'type' => 'outreach_converted',
                'summary' => 'Prospect recorded a campaign conversion.',
                'payload' => $payload,
            ],
            default => [
                'type' => 'outreach_event',
                'summary' => sprintf('Campaign event %s recorded.', $eventType),
                'payload' => $payload,
            ],
        };
    }

    /**
     * @param  array<int, string>  $enabledChannels
     * @return array<int, string>
     */
    private function normalizedEnabledChannels(Campaign $campaign, array $enabledChannels): array
    {
        if ($enabledChannels === []) {
            $enabledChannels = $campaign->channels
                ->where('is_enabled', true)
                ->pluck('channel')
                ->all();
        }

        return collect($enabledChannels)
            ->map(fn ($channel) => strtoupper((string) $channel))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{eligible: array<int, array<string, mixed>>, blocked: array<int, array<string, mixed>>, counts: array<string, mixed>}
     */
    private function emptyResolution(): array
    {
        return [
            'eligible' => [],
            'blocked' => [],
            'counts' => [
                'total_eligible' => 0,
                'eligible_by_channel' => [],
                'blocked_by_channel' => [],
                'blocked_by_reason' => [],
            ],
        ];
    }

    /**
     * @return array{enabled: bool, max_steps: int, follow_up_delays_hours: array<int, int>}
     */
    private function sequenceConfig(Campaign $campaign): array
    {
        $settings = is_array($campaign->settings) ? $campaign->settings : [];
        $sequence = is_array($settings['prospecting_sequence'] ?? null) ? $settings['prospecting_sequence'] : [];
        $delays = collect($sequence['follow_up_delays_hours'] ?? [72, 168])
            ->map(fn ($value) => max(1, min(24 * 30, (int) $value)))
            ->filter()
            ->values()
            ->take(2)
            ->all();

        $maxSteps = max(1, min(3, (int) ($sequence['max_steps'] ?? (1 + count($delays)))));
        $delays = array_slice($delays, 0, max(0, $maxSteps - 1));

        return [
            'enabled' => (bool) ($sequence['enabled'] ?? true),
            'max_steps' => $maxSteps,
            'follow_up_delays_hours' => $delays,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sequenceState(CampaignProspect $prospect): array
    {
        $metadata = is_array($prospect->metadata) ? $prospect->metadata : [];
        $sequence = is_array($metadata['sequence'] ?? null) ? $metadata['sequence'] : [];

        return [
            'enabled' => (bool) ($sequence['enabled'] ?? true),
            'max_steps' => max(1, min(3, (int) ($sequence['max_steps'] ?? 3))),
            'current_step' => max(0, min(3, (int) ($sequence['current_step'] ?? 0))),
            'current_phase' => (string) ($sequence['current_phase'] ?? ''),
            'next_follow_up_at' => $sequence['next_follow_up_at'] ?? null,
            'has_pending_follow_up' => (bool) ($sequence['has_pending_follow_up'] ?? false),
            'stop_reason' => $sequence['stop_reason'] ?? null,
            'stopped_at' => $sequence['stopped_at'] ?? null,
            'last_sent_at' => $sequence['last_sent_at'] ?? null,
            'last_sent_channel' => $sequence['last_sent_channel'] ?? null,
        ];
    }

    private function refreshFollowUpDueStates(Campaign $campaign, array $sequenceConfig, Carbon $now): void
    {
        if (! ($sequenceConfig['enabled'] ?? false)) {
            return;
        }

        CampaignProspect::query()
            ->where('campaign_id', $campaign->id)
            ->where('user_id', $campaign->user_id)
            ->whereIn('status', [
                CampaignProspect::STATUS_APPROVED,
                CampaignProspect::STATUS_CONTACTED,
                CampaignProspect::STATUS_FOLLOW_UP_DUE,
            ])
            ->chunkById(200, function (Collection $prospects) use ($sequenceConfig, $now): void {
                foreach ($prospects as $prospect) {
                    $state = $this->sequenceState($prospect);
                    if (($state['stop_reason'] ?? null) !== null) {
                        continue;
                    }

                    $nextFollowUpAt = $state['next_follow_up_at']
                        ? Carbon::parse((string) $state['next_follow_up_at'])
                        : null;

                    if ($prospect->status === CampaignProspect::STATUS_CONTACTED
                        && $nextFollowUpAt
                        && $nextFollowUpAt->lessThanOrEqualTo($now)
                        && (int) ($state['current_step'] ?? 0) < (int) ($sequenceConfig['max_steps'] ?? 1)) {
                        $prospect->forceFill([
                            'status' => CampaignProspect::STATUS_FOLLOW_UP_DUE,
                            'last_activity_at' => $prospect->last_activity_at ?: $now,
                        ])->save();
                    }
                }
            }, 'id');
    }

    private function isProspectSendable(CampaignProspect $prospect, array $sequenceConfig, Carbon $now): bool
    {
        if ($prospect->do_not_contact || in_array($prospect->status, self::TERMINAL_STATUSES, true)) {
            return false;
        }

        $state = $this->sequenceState($prospect);
        if (($state['stop_reason'] ?? null) !== null) {
            return false;
        }

        if ($prospect->status === CampaignProspect::STATUS_APPROVED) {
            return true;
        }

        if (! ($sequenceConfig['enabled'] ?? false)) {
            return false;
        }

        if (! in_array($prospect->status, [CampaignProspect::STATUS_CONTACTED, CampaignProspect::STATUS_FOLLOW_UP_DUE], true)) {
            return false;
        }

        $currentStep = (int) ($state['current_step'] ?? 0);
        if ($currentStep >= (int) ($sequenceConfig['max_steps'] ?? 1)) {
            return false;
        }

        $nextFollowUpAt = $state['next_follow_up_at']
            ? Carbon::parse((string) $state['next_follow_up_at'])
            : null;

        return $nextFollowUpAt !== null && $nextFollowUpAt->lessThanOrEqualTo($now);
    }

    /**
     * @param  array<int, string>  $campaignChannels
     * @return array<int, string>
     */
    private function availableChannels(CampaignProspect $prospect, array $campaignChannels): array
    {
        $metadata = is_array($prospect->metadata) ? $prospect->metadata : [];
        $available = collect($metadata['available_channels'] ?? [])
            ->map(fn ($value) => strtoupper((string) $value))
            ->filter()
            ->unique();

        if ($available->isEmpty()) {
            $available = collect(array_keys($this->prospectDestinations($prospect)));
        }

        return $available
            ->filter(fn (string $channel) => in_array($channel, $campaignChannels, true))
            ->values()
            ->all();
    }

    private function destinationForProspect(CampaignProspect $prospect, string $channel): ?string
    {
        return $this->prospectDestinations($prospect)[strtoupper($channel)] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function prospectDestinations(CampaignProspect $prospect): array
    {
        $destinations = [];

        $email = trim((string) ($prospect->email_normalized ?: $prospect->email));
        if ($email !== '') {
            $destinations[Campaign::CHANNEL_EMAIL] = strtolower($email);
        }

        $phone = trim((string) ($prospect->phone_normalized ?: $prospect->phone));
        if ($phone !== '') {
            $destinations[Campaign::CHANNEL_SMS] = $phone;
            $destinations[Campaign::CHANNEL_WHATSAPP] = $phone;
        }

        return $destinations;
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchSequenceSnapshot(CampaignProspect $prospect, array $sequenceConfig, string $channel): array
    {
        $state = $this->sequenceState($prospect);
        $currentStep = (int) ($state['current_step'] ?? 0);
        $dispatchStep = $prospect->status === CampaignProspect::STATUS_APPROVED
            ? 1
            : min((int) ($sequenceConfig['max_steps'] ?? 1), $currentStep + 1);

        return [
            'enabled' => (bool) ($sequenceConfig['enabled'] ?? false),
            'dispatch_step' => $dispatchStep,
            'dispatch_phase' => $this->phaseLabelForStep($dispatchStep),
            'current_step_before_send' => $currentStep,
            'next_follow_up_at_before_send' => $state['next_follow_up_at'],
            'channel' => strtoupper($channel),
        ];
    }

    private function phaseLabelForProspect(CampaignProspect $prospect, array $sequenceConfig): string
    {
        $state = $this->sequenceState($prospect);
        $currentStep = (int) ($state['current_step'] ?? 0);

        return $prospect->status === CampaignProspect::STATUS_APPROVED
            ? $this->phaseLabelForStep(1)
            : $this->phaseLabelForStep(min((int) ($sequenceConfig['max_steps'] ?? 1), $currentStep + 1));
    }

    private function phaseLabelForStep(int $step): string
    {
        return match ($step) {
            1 => 'first_touch',
            2 => 'follow_up_1',
            default => 'follow_up_2',
        };
    }

    private function nextFollowUpAt(Carbon $sentAt, int $currentStep, array $sequenceConfig): ?Carbon
    {
        if (! ($sequenceConfig['enabled'] ?? false)) {
            return null;
        }

        $maxSteps = (int) ($sequenceConfig['max_steps'] ?? 1);
        if ($currentStep >= $maxSteps) {
            return null;
        }

        $delays = $sequenceConfig['follow_up_delays_hours'] ?? [];
        $delayHours = isset($delays[$currentStep - 1]) ? (int) $delays[$currentStep - 1] : null;

        return $delayHours ? $sentAt->copy()->addHours($delayHours) : null;
    }

    private function refreshBatchCounters(int $batchId): void
    {
        $counts = CampaignProspect::query()
            ->where('campaign_prospect_batch_id', $batchId)
            ->selectRaw('SUM(CASE WHEN status IN (?, ?, ?, ?, ?, ?, ?, ?) THEN 1 ELSE 0 END) as accepted_count', [
                CampaignProspect::STATUS_SCORED,
                CampaignProspect::STATUS_APPROVED,
                CampaignProspect::STATUS_CONTACTED,
                CampaignProspect::STATUS_FOLLOW_UP_DUE,
                CampaignProspect::STATUS_REPLIED,
                CampaignProspect::STATUS_QUALIFIED,
                CampaignProspect::STATUS_CONVERTED_TO_LEAD,
                CampaignProspect::STATUS_CONVERTED_TO_CUSTOMER,
            ])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as scored_count', [
                CampaignProspect::STATUS_SCORED,
            ])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as duplicate_count', [
                CampaignProspect::STATUS_DUPLICATE,
            ])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as blocked_count', [
                CampaignProspect::STATUS_BLOCKED,
            ])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as rejected_count', [
                CampaignProspect::STATUS_DISQUALIFIED,
                CampaignProspect::STATUS_DO_NOT_CONTACT,
            ])
            ->selectRaw('SUM(CASE WHEN first_contacted_at IS NOT NULL THEN 1 ELSE 0 END) as contacted_count')
            ->selectRaw('SUM(CASE WHEN last_replied_at IS NOT NULL OR status IN (?, ?) THEN 1 ELSE 0 END) as replied_count', [
                CampaignProspect::STATUS_REPLIED,
                CampaignProspect::STATUS_QUALIFIED,
            ])
            ->selectRaw('SUM(CASE WHEN converted_to_lead_id IS NOT NULL OR status = ? THEN 1 ELSE 0 END) as lead_count', [
                CampaignProspect::STATUS_CONVERTED_TO_LEAD,
            ])
            ->selectRaw('SUM(CASE WHEN converted_to_customer_id IS NOT NULL OR status = ? THEN 1 ELSE 0 END) as customer_count', [
                CampaignProspect::STATUS_CONVERTED_TO_CUSTOMER,
            ])
            ->first();

        $batch = CampaignProspectBatch::query()->find($batchId);
        if (! $batch) {
            return;
        }

        $contactedCount = (int) ($counts?->contacted_count ?? 0);
        $status = $batch->status;
        if ($contactedCount > 0 && in_array($status, [CampaignProspectBatch::STATUS_APPROVED, CampaignProspectBatch::STATUS_ANALYZED], true)) {
            $status = CampaignProspectBatch::STATUS_RUNNING;
        }

        $summaryData = is_array($batch->analysis_summary) ? $batch->analysis_summary : [];
        $summaryData['review_required_count'] = (int) ($counts?->scored_count ?? 0);

        $batch->forceFill([
            'accepted_count' => (int) ($counts?->accepted_count ?? 0),
            'rejected_count' => (int) ($counts?->rejected_count ?? 0),
            'duplicate_count' => (int) ($counts?->duplicate_count ?? 0),
            'blocked_count' => (int) ($counts?->blocked_count ?? 0),
            'scored_count' => (int) ($counts?->scored_count ?? 0),
            'contacted_count' => $contactedCount,
            'replied_count' => (int) ($counts?->replied_count ?? 0),
            'lead_count' => (int) ($counts?->lead_count ?? 0),
            'customer_count' => (int) ($counts?->customer_count ?? 0),
            'status' => $status,
            'analysis_summary' => $summaryData,
        ])->save();
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocked
     * @param  array<string, int>  $blockedByChannel
     * @param  array<string, int>  $blockedByReason
     */
    private function pushBlocked(
        array &$blocked,
        array &$blockedByChannel,
        array &$blockedByReason,
        ?string $channel,
        string $reason,
        ?int $customerId,
        ?string $destination,
        ?CampaignProspect $prospect = null
    ): void {
        $normalizedChannel = $channel ? strtoupper($channel) : null;
        if ($normalizedChannel) {
            $blockedByChannel[$normalizedChannel] = (int) ($blockedByChannel[$normalizedChannel] ?? 0) + 1;
        }
        $blockedByReason[$reason] = (int) ($blockedByReason[$reason] ?? 0) + 1;

        $blocked[] = [
            'customer_id' => $customerId,
            'channel' => $normalizedChannel,
            'destination' => $destination,
            'reason' => $reason,
            'prospect_id' => $prospect?->id,
            'prospect_batch_id' => $prospect?->campaign_prospect_batch_id,
        ];
    }

    private function assertProspectAccess(User $accountOwner, Campaign $campaign, CampaignProspect $prospect): void
    {
        if ((int) $campaign->user_id !== (int) $accountOwner->id) {
            throw ValidationException::withMessages([
                'campaign' => ['Campaign not found for this tenant.'],
            ]);
        }

        if ((int) $prospect->campaign_id !== (int) $campaign->id || (int) $prospect->user_id !== (int) $accountOwner->id) {
            throw ValidationException::withMessages([
                'prospect' => ['Prospect not found for this campaign.'],
            ]);
        }
    }
}
