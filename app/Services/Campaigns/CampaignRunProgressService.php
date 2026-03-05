<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;

class CampaignRunProgressService
{
    public function refresh(CampaignRun $run): CampaignRun
    {
        $counts = CampaignRecipient::query()
            ->where('campaign_run_id', $run->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $queued = (int) ($counts[CampaignRecipient::STATUS_QUEUED] ?? 0);
        $summary = [
            'targeted' => array_sum($counts),
            'queued' => $queued,
            'sent' => (int) ($counts[CampaignRecipient::STATUS_SENT] ?? 0),
            'delivered' => (int) ($counts[CampaignRecipient::STATUS_DELIVERED] ?? 0),
            'opened' => (int) ($counts[CampaignRecipient::STATUS_OPENED] ?? 0),
            'clicked' => (int) ($counts[CampaignRecipient::STATUS_CLICKED] ?? 0),
            'converted' => (int) ($counts[CampaignRecipient::STATUS_CONVERTED] ?? 0),
            'failed' => (int) ($counts[CampaignRecipient::STATUS_FAILED] ?? 0),
            'skipped' => (int) ($counts[CampaignRecipient::STATUS_SKIPPED] ?? 0),
        ];
        $summary = array_merge(
            is_array($run->summary) ? $run->summary : [],
            $summary
        );

        $updates = [
            'summary' => $summary,
        ];

        if ($queued === 0 && in_array($run->status, [CampaignRun::STATUS_PENDING, CampaignRun::STATUS_RUNNING], true)) {
            $updates['status'] = CampaignRun::STATUS_COMPLETED;
            $updates['completed_at'] = now();
        }

        $run->forceFill($updates)->save();

        if ($run->status === CampaignRun::STATUS_COMPLETED) {
            $run->campaign()->update([
                'status' => Campaign::STATUS_COMPLETED,
                'last_run_at' => now(),
                'completed_at' => now(),
            ]);
        }

        return $run->fresh();
    }
}
