<?php

namespace App\Jobs;

use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Services\Campaigns\AudienceResolver;
use App\Services\Campaigns\CampaignRunProgressService;
use App\Services\Campaigns\CampaignTrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchCampaignRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(
        public int $campaignRunId
    ) {
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

        if (!$run || !$run->campaign) {
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
                $recipient = CampaignRecipient::query()->firstOrCreate(
                    [
                        'campaign_run_id' => $run->id,
                        'channel' => (string) $payload['channel'],
                        'destination_hash' => (string) ($payload['destination_hash'] ?? ''),
                    ],
                    [
                        'campaign_id' => $run->campaign_id,
                        'user_id' => $run->user_id,
                        'customer_id' => $payload['customer_id'] ?? null,
                        'destination' => $payload['destination'] ?? null,
                        'dedupe_key' => $payload['channel'] . ':' . ($payload['destination_hash'] ?? ''),
                        'status' => CampaignRecipient::STATUS_QUEUED,
                        'queued_at' => now(),
                        'metadata' => $payload['metadata'] ?? null,
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
                            ?: hash('sha256', 'blocked:' . ($payload['channel'] ?? '') . ':' . ($payload['destination'] ?? '')),
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
}
