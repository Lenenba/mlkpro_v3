<?php

namespace App\Services\Assistant;

use App\Models\AudienceSegment;
use App\Models\Campaign;
use App\Models\MailingList;
use App\Models\MessageTemplate;
use App\Models\Product;
use App\Models\User;
use App\Models\VipTier;
use App\Services\Campaigns\DashboardKpiService;
use App\Services\Campaigns\MarketingSettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CampaignAssistantContextService
{
    public function __construct(
        private readonly MarketingSettingsService $marketingSettingsService,
        private readonly DashboardKpiService $dashboardKpiService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        [$owner, $canView, $canManage, $canSend] = $this->resolveCampaignAccess($user);
        $campaignsEnabled = $owner?->hasCompanyFeature('campaigns') ?? false;

        $base = [
            'available' => (bool) ($owner && $campaignsEnabled && $canView),
            'campaigns_enabled' => $campaignsEnabled,
            'can_view' => $canView,
            'can_manage' => $canManage,
            'can_send' => $canSend,
        ];

        if (! $owner || ! $campaignsEnabled || ! $canView) {
            return $base;
        }

        /** @var array<string, mixed> $ownerContext */
        $ownerContext = Cache::remember(
            sprintf('assistant:campaign-context:%d', $owner->id),
            now()->addMinutes(5),
            fn (): array => $this->buildOwnerContext($owner)
        );

        return array_merge($base, $ownerContext);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOwnerContext(User $owner): array
    {
        $settings = $this->marketingSettingsService->getResolved($owner);
        $enabledChannels = collect(Campaign::allowedChannels())
            ->filter(fn (string $channel): bool => $this->marketingSettingsService->isChannelEnabled($owner, $channel))
            ->values()
            ->all();

        $segments = AudienceSegment::query()
            ->where('user_id', $owner->id)
            ->orderByDesc('cached_count')
            ->orderBy('name')
            ->limit(8)
            ->get([
                'id',
                'name',
                'tags',
                'cached_count',
                'last_computed_at',
            ])
            ->map(fn (AudienceSegment $segment): array => [
                'id' => (int) $segment->id,
                'name' => (string) $segment->name,
                'tags' => array_values(array_slice((array) ($segment->tags ?? []), 0, 4)),
                'cached_count' => (int) ($segment->cached_count ?? 0),
                'last_computed_at' => $segment->last_computed_at?->toDateString(),
            ])
            ->values()
            ->all();

        $mailingLists = MailingList::query()
            ->where('user_id', $owner->id)
            ->withCount('customers')
            ->orderByDesc('customers_count')
            ->orderBy('name')
            ->limit(8)
            ->get([
                'id',
                'name',
                'tags',
            ])
            ->map(fn (MailingList $list): array => [
                'id' => (int) $list->id,
                'name' => (string) $list->name,
                'tags' => array_values(array_slice((array) ($list->tags ?? []), 0, 4)),
                'customers_count' => (int) ($list->customers_count ?? 0),
            ])
            ->values()
            ->all();

        $vipTiers = VipTier::query()
            ->where('user_id', $owner->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(6)
            ->get([
                'id',
                'code',
                'name',
            ])
            ->map(fn (VipTier $tier): array => [
                'id' => (int) $tier->id,
                'code' => (string) $tier->code,
                'name' => (string) $tier->name,
            ])
            ->values()
            ->all();

        $offers = Product::query()
            ->where('user_id', $owner->id)
            ->where('is_active', true)
            ->with('category:id,name')
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get([
                'id',
                'category_id',
                'item_type',
                'name',
                'price',
                'tags',
                'promo_discount_percent',
            ])
            ->map(fn (Product $offer): array => [
                'id' => (int) $offer->id,
                'name' => (string) $offer->name,
                'offer_type' => (string) $offer->item_type,
                'price' => (float) $offer->price,
                'category_name' => (string) ($offer->category?->name ?? ''),
                'tags' => array_values(array_slice((array) ($offer->tags ?? []), 0, 4)),
                'promo_discount_percent' => $offer->promo_discount_percent !== null
                    ? (float) $offer->promo_discount_percent
                    : null,
                'updated_at' => $offer->updated_at?->toISOString(),
            ])
            ->values()
            ->all();

        $recentCampaigns = Campaign::query()
            ->where('user_id', $owner->id)
            ->orderByDesc('updated_at')
            ->limit(6)
            ->get([
                'id',
                'name',
                'campaign_type',
                'offer_mode',
                'status',
                'updated_at',
            ])
            ->map(fn (Campaign $campaign): array => [
                'id' => (int) $campaign->id,
                'name' => (string) $campaign->name,
                'campaign_type' => (string) ($campaign->campaign_type ?: $campaign->type),
                'offer_mode' => (string) $campaign->offer_mode,
                'status' => (string) $campaign->status,
                'updated_at' => $campaign->updated_at?->toDateString(),
            ])
            ->values()
            ->all();

        $defaultTemplates = MessageTemplate::query()
            ->where('user_id', $owner->id)
            ->where('is_default', true)
            ->orderBy('channel')
            ->orderBy('campaign_type')
            ->orderBy('language')
            ->limit(12)
            ->get([
                'id',
                'name',
                'channel',
                'campaign_type',
                'language',
                'updated_at',
            ])
            ->map(fn (MessageTemplate $template): array => [
                'id' => (int) $template->id,
                'name' => (string) $template->name,
                'channel' => (string) $template->channel,
                'campaign_type' => $template->campaign_type ? (string) $template->campaign_type : null,
                'language' => $template->language ? (string) $template->language : null,
                'updated_at' => $template->updated_at?->toISOString(),
            ])
            ->values()
            ->all();

        $kpis = $this->dashboardKpiService->resolve($owner, ['range' => '90']);
        $marketingKpis = is_array($kpis['marketing'] ?? null) ? $kpis['marketing'] : [];
        $topCampaign = is_array($marketingKpis['top_performing_campaign'] ?? null)
            ? $marketingKpis['top_performing_campaign']
            : null;
        $historicalRecommendations = $this->buildHistoricalRecommendations($owner);

        return [
            'tenant' => [
                'owner_id' => (int) $owner->id,
                'company_name' => (string) ($owner->company_name ?? ''),
                'company_type' => (string) ($owner->company_type ?? ''),
                'locale' => (string) ($owner->locale ?? 'fr'),
                'timezone' => (string) ($owner->company_timezone ?? config('app.timezone', 'UTC')),
            ],
            'marketing' => [
                'enabled_channels' => $enabledChannels,
                'allowed_offer_modes' => $this->marketingSettingsService->allowedOfferModes($owner),
                'consent' => [
                    'require_explicit' => (bool) ($settings['consent']['require_explicit'] ?? true),
                    'default_behavior' => (string) ($settings['consent']['default_behavior'] ?? 'deny_without_explicit'),
                ],
                'quiet_hours' => [
                    'timezone' => (string) ($settings['channels']['quiet_hours']['timezone'] ?? $owner->company_timezone ?? config('app.timezone', 'UTC')),
                    'start' => (string) ($settings['channels']['quiet_hours']['start'] ?? '21:00'),
                    'end' => (string) ($settings['channels']['quiet_hours']['end'] ?? '08:00'),
                ],
            ],
            'counts' => [
                'segments' => AudienceSegment::query()->where('user_id', $owner->id)->count(),
                'mailing_lists' => MailingList::query()->where('user_id', $owner->id)->count(),
                'vip_tiers' => VipTier::query()->where('user_id', $owner->id)->where('is_active', true)->count(),
                'active_offers' => Product::query()->where('user_id', $owner->id)->where('is_active', true)->count(),
                'campaigns' => Campaign::query()->where('user_id', $owner->id)->count(),
                'default_templates' => MessageTemplate::query()->where('user_id', $owner->id)->where('is_default', true)->count(),
            ],
            'segments' => $segments,
            'mailing_lists' => $mailingLists,
            'vip_tiers' => $vipTiers,
            'offers' => $offers,
            'recent_campaigns' => $recentCampaigns,
            'default_templates' => $defaultTemplates,
            'performance' => [
                'campaigns_sent' => (int) ($marketingKpis['campaigns_sent'] ?? 0),
                'top_performing_campaign' => $topCampaign ? [
                    'id' => (int) ($topCampaign['id'] ?? 0),
                    'name' => (string) ($topCampaign['name'] ?? ''),
                    'conversions' => (int) ($topCampaign['conversions'] ?? 0),
                    'clicks' => (int) ($topCampaign['clicks'] ?? 0),
                ] : null,
                'channel_recommendations' => $historicalRecommendations['channel_recommendations'],
                'template_recommendations' => $historicalRecommendations['template_recommendations'],
                'offer_recommendations' => $historicalRecommendations['offer_recommendations'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildHistoricalRecommendations(User $owner): array
    {
        return [
            'channel_recommendations' => [
                'global' => $this->aggregateChannelHistory($owner),
                'by_type' => $this->aggregateChannelHistoryByType($owner),
            ],
            'template_recommendations' => [
                'global' => $this->aggregateTemplateHistory($owner),
                'by_type' => $this->aggregateTemplateHistoryByType($owner),
            ],
            'offer_recommendations' => [
                'global' => $this->aggregateOfferHistory($owner),
                'by_type' => $this->aggregateOfferHistoryByType($owner),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function aggregateChannelHistory(User $owner): array
    {
        $rows = DB::table('campaign_recipients')
            ->where('campaign_recipients.user_id', $owner->id)
            ->selectRaw('campaign_recipients.channel')
            ->selectRaw('COUNT(*) as sent_count')
            ->selectRaw('SUM(CASE WHEN campaign_recipients.delivered_at IS NOT NULL THEN 1 ELSE 0 END) as delivered_count')
            ->selectRaw('SUM(CASE WHEN campaign_recipients.clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked_count')
            ->selectRaw('SUM(CASE WHEN campaign_recipients.converted_at IS NOT NULL THEN 1 ELSE 0 END) as converted_count')
            ->groupBy('campaign_recipients.channel')
            ->get();

        return $this->scoreHistoryRows($rows, 'channel', 8);
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function aggregateChannelHistoryByType(User $owner): array
    {
        $rows = DB::table('campaign_recipients')
            ->join('campaigns', 'campaigns.id', '=', 'campaign_recipients.campaign_id')
            ->where('campaign_recipients.user_id', $owner->id)
            ->selectRaw('COALESCE(campaigns.campaign_type, campaigns.type) as campaign_type')
            ->selectRaw('campaign_recipients.channel')
            ->selectRaw('COUNT(*) as sent_count')
            ->selectRaw('SUM(CASE WHEN campaign_recipients.delivered_at IS NOT NULL THEN 1 ELSE 0 END) as delivered_count')
            ->selectRaw('SUM(CASE WHEN campaign_recipients.clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked_count')
            ->selectRaw('SUM(CASE WHEN campaign_recipients.converted_at IS NOT NULL THEN 1 ELSE 0 END) as converted_count')
            ->groupBy(DB::raw('COALESCE(campaigns.campaign_type, campaigns.type)'), 'campaign_recipients.channel')
            ->get();

        return $this->groupScoredHistoryRows($rows, 'campaign_type', 'channel', 6);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function aggregateTemplateHistory(User $owner): array
    {
        $rows = DB::table('campaign_recipients')
            ->join('campaign_channels', function ($join): void {
                $join->on('campaign_channels.campaign_id', '=', 'campaign_recipients.campaign_id')
                    ->on('campaign_channels.channel', '=', 'campaign_recipients.channel');
            })
            ->where('campaign_recipients.user_id', $owner->id)
            ->whereNotNull('campaign_channels.message_template_id')
            ->selectRaw('campaign_channels.message_template_id as template_id')
            ->selectRaw('campaign_recipients.channel')
            ->selectRaw('COUNT(*) as sent_count')
            ->selectRaw('SUM(CASE WHEN campaign_recipients.delivered_at IS NOT NULL THEN 1 ELSE 0 END) as delivered_count')
            ->selectRaw('SUM(CASE WHEN campaign_recipients.clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked_count')
            ->selectRaw('SUM(CASE WHEN campaign_recipients.converted_at IS NOT NULL THEN 1 ELSE 0 END) as converted_count')
            ->groupBy('campaign_channels.message_template_id', 'campaign_recipients.channel')
            ->get();

        return $this->scoreHistoryRows($rows, 'template_id', 12, ['channel']);
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function aggregateTemplateHistoryByType(User $owner): array
    {
        $rows = DB::table('campaign_recipients')
            ->join('campaigns', 'campaigns.id', '=', 'campaign_recipients.campaign_id')
            ->join('campaign_channels', function ($join): void {
                $join->on('campaign_channels.campaign_id', '=', 'campaign_recipients.campaign_id')
                    ->on('campaign_channels.channel', '=', 'campaign_recipients.channel');
            })
            ->where('campaign_recipients.user_id', $owner->id)
            ->whereNotNull('campaign_channels.message_template_id')
            ->selectRaw('COALESCE(campaigns.campaign_type, campaigns.type) as campaign_type')
            ->selectRaw('campaign_channels.message_template_id as template_id')
            ->selectRaw('campaign_recipients.channel')
            ->selectRaw('COUNT(*) as sent_count')
            ->selectRaw('SUM(CASE WHEN campaign_recipients.delivered_at IS NOT NULL THEN 1 ELSE 0 END) as delivered_count')
            ->selectRaw('SUM(CASE WHEN campaign_recipients.clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked_count')
            ->selectRaw('SUM(CASE WHEN campaign_recipients.converted_at IS NOT NULL THEN 1 ELSE 0 END) as converted_count')
            ->groupBy(DB::raw('COALESCE(campaigns.campaign_type, campaigns.type)'), 'campaign_channels.message_template_id', 'campaign_recipients.channel')
            ->get();

        return $this->groupScoredHistoryRows($rows, 'campaign_type', 'template_id', 8, ['channel']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function aggregateOfferHistory(User $owner): array
    {
        $rows = DB::table('campaign_recipients')
            ->join('campaign_offers', 'campaign_offers.campaign_id', '=', 'campaign_recipients.campaign_id')
            ->where('campaign_recipients.user_id', $owner->id)
            ->selectRaw('campaign_offers.offer_id')
            ->selectRaw('campaign_offers.offer_type')
            ->selectRaw('COUNT(*) as sent_count')
            ->selectRaw('SUM(CASE WHEN campaign_recipients.delivered_at IS NOT NULL THEN 1 ELSE 0 END) as delivered_count')
            ->selectRaw('SUM(CASE WHEN campaign_recipients.clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked_count')
            ->selectRaw('SUM(CASE WHEN campaign_recipients.converted_at IS NOT NULL THEN 1 ELSE 0 END) as converted_count')
            ->groupBy('campaign_offers.offer_id', 'campaign_offers.offer_type')
            ->get();

        return $this->scoreHistoryRows($rows, 'offer_id', 12, ['offer_type']);
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function aggregateOfferHistoryByType(User $owner): array
    {
        $rows = DB::table('campaign_recipients')
            ->join('campaigns', 'campaigns.id', '=', 'campaign_recipients.campaign_id')
            ->join('campaign_offers', 'campaign_offers.campaign_id', '=', 'campaign_recipients.campaign_id')
            ->where('campaign_recipients.user_id', $owner->id)
            ->selectRaw('COALESCE(campaigns.campaign_type, campaigns.type) as campaign_type')
            ->selectRaw('campaign_offers.offer_id')
            ->selectRaw('campaign_offers.offer_type')
            ->selectRaw('COUNT(*) as sent_count')
            ->selectRaw('SUM(CASE WHEN campaign_recipients.delivered_at IS NOT NULL THEN 1 ELSE 0 END) as delivered_count')
            ->selectRaw('SUM(CASE WHEN campaign_recipients.clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked_count')
            ->selectRaw('SUM(CASE WHEN campaign_recipients.converted_at IS NOT NULL THEN 1 ELSE 0 END) as converted_count')
            ->groupBy(DB::raw('COALESCE(campaigns.campaign_type, campaigns.type)'), 'campaign_offers.offer_id', 'campaign_offers.offer_type')
            ->get();

        return $this->groupScoredHistoryRows($rows, 'campaign_type', 'offer_id', 8, ['offer_type']);
    }

    /**
     * @param  iterable<int, object>  $rows
     * @param  array<int, string>  $extraColumns
     * @return array<int, array<string, mixed>>
     */
    private function scoreHistoryRows(iterable $rows, string $identityColumn, int $limit, array $extraColumns = []): array
    {
        return collect($rows)
            ->map(function (object $row) use ($identityColumn, $extraColumns): array {
                $sent = (int) ($row->sent_count ?? 0);
                $delivered = (int) ($row->delivered_count ?? 0);
                $clicked = (int) ($row->clicked_count ?? 0);
                $converted = (int) ($row->converted_count ?? 0);

                $payload = [
                    $identityColumn => is_numeric($row->{$identityColumn} ?? null)
                        ? (int) $row->{$identityColumn}
                        : (string) ($row->{$identityColumn} ?? ''),
                    'sent_count' => $sent,
                    'delivered_count' => $delivered,
                    'clicked_count' => $clicked,
                    'converted_count' => $converted,
                    'score' => $this->recommendationScore($sent, $delivered, $clicked, $converted),
                ];

                foreach ($extraColumns as $column) {
                    $payload[$column] = is_numeric($row->{$column} ?? null)
                        ? (int) $row->{$column}
                        : (string) ($row->{$column} ?? '');
                }

                return $payload;
            })
            ->filter(fn (array $row) => $row[$identityColumn] !== '' && $row['sent_count'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  iterable<int, object>  $rows
     * @param  array<int, string>  $extraColumns
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupScoredHistoryRows(
        iterable $rows,
        string $groupColumn,
        string $identityColumn,
        int $limit,
        array $extraColumns = []
    ): array {
        return collect($rows)
            ->groupBy(fn (object $row) => strtoupper(trim((string) ($row->{$groupColumn} ?? ''))))
            ->map(fn ($group) => $this->scoreHistoryRows($group, $identityColumn, $limit, $extraColumns))
            ->filter(fn (array $group) => $group !== [])
            ->all();
    }

    private function recommendationScore(int $sent, int $delivered, int $clicked, int $converted): float
    {
        if ($sent <= 0) {
            return 0.0;
        }

        $deliveryRate = $delivered > 0 ? $delivered / $sent : 0.0;
        $clickRate = $clicked > 0 ? $clicked / $sent : 0.0;
        $conversionRate = $converted > 0 ? $converted / $sent : 0.0;

        return round(
            min(42.0, $conversionRate * 180.0)
            + min(26.0, $clickRate * 90.0)
            + min(8.0, $deliveryRate * 10.0)
            + min(10.0, log10($sent + 1) * 6.0),
            2
        );
    }

    /**
     * @return array{0: ?User, 1: bool, 2: bool, 3: bool}
     */
    private function resolveCampaignAccess(User $user): array
    {
        $ownerId = $user->accountOwnerId();
        $owner = $ownerId === $user->id
            ? $user
            : User::query()->find($ownerId);

        if (! $owner) {
            return [null, false, false, false];
        }

        if ($user->id === $owner->id) {
            return [$owner, true, true, true];
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();

        $canManage = (bool) (
            $membership?->hasPermission('campaigns.manage')
            || $membership?->hasPermission('sales.manage')
        );
        $canSend = (bool) $membership?->hasPermission('campaigns.send');
        $canView = $canManage
            || $canSend
            || (bool) $membership?->hasPermission('campaigns.view');

        return [$owner, $canView, $canManage, $canSend];
    }
}
