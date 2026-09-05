<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use Illuminate\Support\Facades\DB;

class CampaignRunProgressService
{
    public function refresh(CampaignRun $run): CampaignRun
    {
        return DB::transaction(fn (): CampaignRun => $this->refreshLocked($run), 3);
    }

    private function refreshLocked(CampaignRun $run): CampaignRun
    {
        $run = CampaignRun::query()->lockForUpdate()->findOrFail($run->id);
        $counts = CampaignRecipient::query()
            ->where('campaign_run_id', $run->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $queued = (int) ($counts[CampaignRecipient::STATUS_QUEUED] ?? 0);
        $unknown = $run->recipients()->where('failure_reason', 'provider_result_unknown')->count();
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
            'delivery_unknown' => $unknown,
        ];
        $summary = array_merge(
            is_array($run->summary) ? $run->summary : [],
            $summary
        );

        $updates = [
            'summary' => $summary,
        ];

        if ($queued === 0 && in_array($run->status, [CampaignRun::STATUS_PENDING, CampaignRun::STATUS_RUNNING], true)) {
            $updates['status'] = $unknown > 0 ? CampaignRun::STATUS_FAILED : CampaignRun::STATUS_COMPLETED;
            $updates['completed_at'] = $unknown > 0 ? null : now();
            if ($unknown > 0) {
                $updates['error_message'] = 'provider_result_unknown';
            }
        }

        $run->forceFill($updates)->save();

        if ($run->status === CampaignRun::STATUS_COMPLETED) {
            $run->campaign()->where('status', '!=', Campaign::STATUS_CANCELED)->update([
                'status' => Campaign::STATUS_COMPLETED,
                'last_run_at' => now(),
                'completed_at' => now(),
            ]);
        } elseif ($run->status === CampaignRun::STATUS_FAILED && $unknown > 0) {
            $run->campaign()->where('status', Campaign::STATUS_RUNNING)->update([
                'status' => Campaign::STATUS_FAILED,
            ]);
        }

        return $run->fresh();
    }
}
