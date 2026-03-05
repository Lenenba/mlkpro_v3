<?php

namespace App\Services\Campaigns;

use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MailingList;
use App\Models\Quote;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardKpiService
{
    public function __construct(
        private readonly MarketingSettingsService $marketingSettingsService,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function resolve(User $accountOwner, array $filters = []): array
    {
        [$start, $end, $label] = $this->resolveRange($filters);
        $cacheKey = sprintf(
            'dashboard:marketing-kpis:%d:%s:%s',
            $accountOwner->id,
            $start->toDateString(),
            $end->toDateString()
        );

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($accountOwner, $start, $end, $label): array {
            $trackingEnabled = (bool) $this->marketingSettingsService->getValue(
                $accountOwner,
                'tracking.click_tracking_enabled',
                true
            );

            $runsQuery = CampaignRun::query()
                ->where('user_id', $accountOwner->id)
                ->whereBetween('created_at', [$start, $end]);
            $campaignsSent = (clone $runsQuery)->count();

            $recipientQuery = CampaignRecipient::query()
                ->where('user_id', $accountOwner->id)
                ->whereBetween('created_at', [$start, $end]);
            $sent = (clone $recipientQuery)->whereNotNull('sent_at')->count();
            $delivered = (clone $recipientQuery)->whereNotNull('delivered_at')->count();
            $clicked = (clone $recipientQuery)->whereNotNull('clicked_at')->count();
            $converted = (clone $recipientQuery)->whereNotNull('converted_at')->count();

            $topCampaignRow = CampaignRecipient::query()
                ->join('campaigns', 'campaigns.id', '=', 'campaign_recipients.campaign_id')
                ->where('campaign_recipients.user_id', $accountOwner->id)
                ->whereBetween('campaign_recipients.created_at', [$start, $end])
                ->select([
                    'campaign_recipients.campaign_id',
                    DB::raw('MAX(campaigns.name) as campaign_name'),
                    DB::raw('SUM(CASE WHEN campaign_recipients.converted_at IS NOT NULL THEN 1 ELSE 0 END) as conversions'),
                    DB::raw('SUM(CASE WHEN campaign_recipients.clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicks'),
                ])
                ->groupBy('campaign_recipients.campaign_id')
                ->orderByDesc('conversions')
                ->orderByDesc('clicks')
                ->first();

            $listCount = MailingList::query()
                ->where('user_id', $accountOwner->id)
                ->count();
            $listSize = DB::table('mailing_list_customers')
                ->join('mailing_lists', 'mailing_lists.id', '=', 'mailing_list_customers.mailing_list_id')
                ->where('mailing_lists.user_id', $accountOwner->id)
                ->count();

            $vipCount = Customer::query()
                ->where('user_id', $accountOwner->id)
                ->where('is_vip', true)
                ->count();

            $periodDays = $start->diffInDays($end) + 1;
            $previousStart = $start->subDays($periodDays);
            $previousEnd = $start->subDay();
            $currentGrowth = Customer::query()
                ->where('user_id', $accountOwner->id)
                ->whereBetween('created_at', [$start, $end])
                ->count();
            $previousGrowth = Customer::query()
                ->where('user_id', $accountOwner->id)
                ->whereBetween('created_at', [$previousStart, $previousEnd])
                ->count();

            return [
                'range' => [
                    'label' => $label,
                    'start' => $start->toDateString(),
                    'end' => $end->toDateString(),
                ],
                'marketing' => [
                    'campaigns_sent' => $campaignsSent,
                    'delivery_success_rate' => $sent > 0 ? round(($delivered / $sent) * 100, 2) : null,
                    'click_rate' => $trackingEnabled && $sent > 0 ? round(($clicked / $sent) * 100, 2) : null,
                    'conversions_attributed' => $converted,
                    'top_performing_campaign' => $topCampaignRow ? [
                        'id' => (int) $topCampaignRow->campaign_id,
                        'name' => (string) $topCampaignRow->campaign_name,
                        'conversions' => (int) $topCampaignRow->conversions,
                        'clicks' => (int) $topCampaignRow->clicks,
                    ] : null,
                    'audience_growth' => [
                        'current' => $currentGrowth,
                        'previous' => $previousGrowth,
                        'delta' => $currentGrowth - $previousGrowth,
                    ],
                    'vip_count' => $vipCount,
                    'mailing_lists' => [
                        'count' => $listCount,
                        'customers_total' => $listSize,
                    ],
                ],
                'cross_module' => [
                    'reservations_created' => Reservation::query()
                        ->where('account_id', $accountOwner->id)
                        ->whereBetween('created_at', [$start, $end])
                        ->count(),
                    'invoices_paid' => Invoice::query()
                        ->where('user_id', $accountOwner->id)
                        ->where('status', 'paid')
                        ->whereBetween('created_at', [$start, $end])
                        ->count(),
                    'quotes_accepted' => Quote::query()
                        ->where('user_id', $accountOwner->id)
                        ->where('status', 'accepted')
                        ->whereBetween('created_at', [$start, $end])
                        ->count(),
                ],
            ];
        });
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function resolveRange(array $filters): array
    {
        $preset = strtolower(trim((string) ($filters['range'] ?? '30')));
        $now = CarbonImmutable::now()->endOfDay();

        if (in_array($preset, ['7', '30', '90'], true)) {
            $days = (int) $preset;
            $start = $now->subDays($days - 1)->startOfDay();
            return [$start, $now, "{$days}d"];
        }

        $startInput = trim((string) ($filters['start_date'] ?? ''));
        $endInput = trim((string) ($filters['end_date'] ?? ''));

        if ($startInput !== '' && $endInput !== '') {
            $start = CarbonImmutable::parse($startInput)->startOfDay();
            $end = CarbonImmutable::parse($endInput)->endOfDay();
            if ($start->greaterThan($end)) {
                [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
            }

            return [$start, $end, 'custom'];
        }

        $defaultStart = $now->subDays(29)->startOfDay();
        return [$defaultStart, $now, '30d'];
    }
}

