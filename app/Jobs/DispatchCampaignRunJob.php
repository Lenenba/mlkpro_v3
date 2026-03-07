<?php

namespace App\Jobs;

use App\Models\CampaignChannel;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Services\Campaigns\AudienceResolver;
use App\Services\Campaigns\CampaignRunProgressService;
use App\Services\Campaigns\CampaignTrackingService;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchCampaignRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $campaignRunId
    ) {
        $this->onQueue(QueueWorkload::queue('campaigns_dispatch'));
    }

    public function backoff(): array
    {
        return QueueWorkload::backoff('campaigns_dispatch', [60, 300, 900]);
    }

    public function handle(
        AudienceResolver $audienceResolver,
        CampaignTrackingService $trackingService,
        CampaignRunProgressService $progressService,
    ): void {
        $run = CampaignRun::query()
            ->with([
                'campaign' => fn ($query) => $query->with(['channels', 'audience', 'offers.offer', 'products', 'user']),
            ])
            ->find($this->campaignRunId);

        if (! $run || ! $run->campaign) {
            return;
        }

        if (in_array($run->status, [CampaignRun::STATUS_COMPLETED, CampaignRun::STATUS_CANCELED], true)) {
            return;
        }

        if ($run->status === CampaignRun::STATUS_PENDING) {
            $run->forceFill([
                'status' => CampaignRun::STATUS_RUNNING,
                'started_at' => $run->started_at ?: now(),
            ])->save();
        }

        if ($run->recipients()->doesntExist()) {
            $resolved = $audienceResolver->resolveForCampaign($run->campaign);
            $holdoutConfig = $this->holdoutConfig($run);
            $channels = $run->campaign->channels
                ->keyBy(fn (CampaignChannel $channel) => strtoupper((string) $channel->channel));
            $holdoutCount = 0;
            $abAssignments = ['A' => 0, 'B' => 0];

            $run->forceFill([
                'audience_snapshot' => [
                    'eligible' => count($resolved['eligible']),
                    'blocked' => count($resolved['blocked']),
                ],
                'summary' => array_merge($run->summary ?? [], [
                    'resolver' => $resolved['counts'],
                ]),
            ])->save();

            foreach ($resolved['eligible'] as $payload) {
                $channel = strtoupper((string) ($payload['channel'] ?? ''));
                $destination = (string) ($payload['destination'] ?? '');
                $destinationHash = (string) ($payload['destination_hash'] ?? '');
                if ($destinationHash === '') {
                    $destinationHash = CampaignRecipient::destinationHash($destination)
                        ?: hash('sha256', 'eligible:'.$channel.':'.$destination);
                }

                $customerId = isset($payload['customer_id']) && is_numeric($payload['customer_id'])
                    ? (int) $payload['customer_id']
                    : null;
                $baseMetadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

                if ($this->isHoldoutRecipient(
                    $holdoutConfig,
                    $run->id,
                    $customerId,
                    $destinationHash
                )) {
                    $holdoutCount += 1;

                    CampaignRecipient::query()->firstOrCreate(
                        [
                            'campaign_run_id' => $run->id,
                            'channel' => $channel,
                            'destination_hash' => $destinationHash,
                        ],
                        [
                            'campaign_id' => $run->campaign_id,
                            'user_id' => $run->user_id,
                            'customer_id' => $customerId,
                            'destination' => $destination !== '' ? $destination : null,
                            'dedupe_key' => $channel.':'.$destinationHash,
                            'status' => CampaignRecipient::STATUS_SKIPPED,
                            'failure_reason' => 'holdout_group',
                            'queued_at' => now(),
                            'metadata' => array_merge($baseMetadata, [
                                'holdout' => [
                                    'enabled' => true,
                                    'percent' => $holdoutConfig['percent'],
                                ],
                            ]),
                        ]
                    );

                    continue;
                }

                $abAssignment = $this->resolveAbAssignment(
                    $channels->get($channel),
                    $run->id,
                    $destinationHash
                );
                $metadata = $baseMetadata;
                if ($abAssignment) {
                    $metadata['ab_test'] = $abAssignment;
                    $variant = strtoupper((string) ($abAssignment['variant'] ?? ''));
                    if (array_key_exists($variant, $abAssignments)) {
                        $abAssignments[$variant] += 1;
                    }
                }

                $recipient = CampaignRecipient::query()->firstOrCreate(
                    [
                        'campaign_run_id' => $run->id,
                        'channel' => $channel,
                        'destination_hash' => $destinationHash,
                    ],
                    [
                        'campaign_id' => $run->campaign_id,
                        'user_id' => $run->user_id,
                        'customer_id' => $customerId,
                        'destination' => $destination !== '' ? $destination : null,
                        'dedupe_key' => $channel.':'.$destinationHash,
                        'status' => CampaignRecipient::STATUS_QUEUED,
                        'queued_at' => now(),
                        'metadata' => $metadata ?: null,
                    ]
                );

                $trackingService->ensureTokens($recipient);
            }

            foreach ($resolved['blocked'] as $payload) {
                CampaignRecipient::query()->firstOrCreate(
                    [
                        'campaign_run_id' => $run->id,
                        'channel' => (string) ($payload['channel'] ?? ''),
                        'destination_hash' => CampaignRecipient::destinationHash((string) ($payload['destination'] ?? ''))
                            ?: hash('sha256', 'blocked:'.($payload['channel'] ?? '').':'.($payload['destination'] ?? '')),
                    ],
                    [
                        'campaign_id' => $run->campaign_id,
                        'user_id' => $run->user_id,
                        'customer_id' => $payload['customer_id'] ?? null,
                        'destination' => $payload['destination'] ?? null,
                        'status' => CampaignRecipient::STATUS_SKIPPED,
                        'failure_reason' => (string) ($payload['reason'] ?? 'blocked'),
                        'queued_at' => now(),
                    ]
                );
            }

            $run->forceFill([
                'audience_snapshot' => array_merge(
                    is_array($run->audience_snapshot) ? $run->audience_snapshot : [],
                    ['holdout_count' => $holdoutCount]
                ),
                'summary' => array_merge(
                    is_array($run->summary) ? $run->summary : [],
                    [
                        'holdout_count' => $holdoutCount,
                        'ab_assignments' => $abAssignments,
                    ]
                ),
            ])->save();
        }

        $recipientIds = $run->recipients()
            ->where('status', CampaignRecipient::STATUS_QUEUED)
            ->pluck('id');

        foreach ($recipientIds as $recipientId) {
            SendCampaignRecipientJob::dispatch((int) $recipientId)
                ->onQueue((string) config('campaigns.queues.send', 'campaigns-send'));
        }

        $progressService->refresh($run);
    }

    /**
     * @return array{enabled: bool, percent: int}
     */
    private function holdoutConfig(CampaignRun $run): array
    {
        $settings = is_array($run->campaign?->settings) ? $run->campaign->settings : [];
        $holdout = is_array($settings['holdout'] ?? null) ? $settings['holdout'] : [];

        return [
            'enabled' => (bool) ($holdout['enabled'] ?? false),
            'percent' => max(0, min(100, (int) ($holdout['percent'] ?? 0))),
        ];
    }

    private function isHoldoutRecipient(
        array $holdoutConfig,
        int $campaignRunId,
        ?int $customerId,
        string $destinationHash
    ): bool {
        if (! ($holdoutConfig['enabled'] ?? false)) {
            return false;
        }

        $percent = (int) ($holdoutConfig['percent'] ?? 0);
        if ($percent <= 0) {
            return false;
        }
        if ($percent >= 100) {
            return true;
        }

        $seed = $customerId
            ? 'customer:'.$customerId
            : 'destination:'.$destinationHash;
        $bucket = abs(crc32($campaignRunId.'|'.$seed)) % 100;

        return $bucket < $percent;
    }

    /**
     * @return array{variant: string, split_a_percent: int, bucket: int}|null
     */
    private function resolveAbAssignment(
        ?CampaignChannel $channel,
        int $campaignRunId,
        string $destinationHash
    ): ?array {
        if (! $channel) {
            return null;
        }

        $metadata = is_array($channel->metadata) ? $channel->metadata : [];
        $abTesting = is_array($metadata['ab_testing'] ?? null) ? $metadata['ab_testing'] : [];
        if (! ($abTesting['enabled'] ?? false)) {
            return null;
        }

        $splitA = max(0, min(100, (int) ($abTesting['split_a_percent'] ?? 50)));
        $bucket = abs(crc32($campaignRunId.'|'.strtoupper((string) $channel->channel).'|'.$destinationHash)) % 100;
        $variant = $bucket < $splitA ? 'A' : 'B';

        return [
            'variant' => $variant,
            'split_a_percent' => $splitA,
            'bucket' => $bucket,
        ];
    }
}
