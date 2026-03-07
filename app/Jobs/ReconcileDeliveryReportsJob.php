<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Services\Campaigns\CampaignRunProgressService;
use App\Services\Campaigns\CampaignTrackingService;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileDeliveryReportsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct()
    {
        $this->onQueue(QueueWorkload::queue('campaigns_maintenance'));
    }

    public function backoff(): array
    {
        return QueueWorkload::backoff('campaigns_maintenance', [120, 300]);
    }

    public function handle(
        CampaignTrackingService $trackingService,
        CampaignRunProgressService $progressService,
    ): void {
        $runIds = [];
        CampaignRecipient::query()
            ->whereIn('channel', [Campaign::CHANNEL_EMAIL, Campaign::CHANNEL_IN_APP])
            ->where('status', CampaignRecipient::STATUS_SENT)
            ->whereNotNull('sent_at')
            ->where('sent_at', '<=', now()->subMinutes(5))
            ->orderBy('id')
            ->chunkById(200, function ($recipients) use ($trackingService, &$runIds): void {
                foreach ($recipients as $recipient) {
                    $trackingService->markDelivered($recipient, ['source' => 'reconcile_job']);
                    $runIds[$recipient->campaign_run_id] = true;
                }
            });

        if ($runIds === []) {
            return;
        }

        $runs = CampaignRun::query()
            ->whereIn('id', array_keys($runIds))
            ->get();

        foreach ($runs as $run) {
            $progressService->refresh($run);
        }
    }
}
