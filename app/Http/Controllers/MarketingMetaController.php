<?php

namespace App\Http\Controllers;

use App\Enums\CampaignAudienceSourceLogic;
use App\Enums\CampaignChannel;
use App\Enums\CampaignLanguageMode;
use App\Enums\CampaignOfferMode;
use App\Enums\CampaignType;
use App\Enums\OfferType;
use App\Models\User;
use App\Services\Campaigns\MarketingSettingsService;
use App\Services\Campaigns\ProspectProviderConnectionService;
use App\Services\Campaigns\ProspectProviderRegistry;
use Illuminate\Http\Request;

class MarketingMetaController extends Controller
{
    public function __construct(
        private readonly MarketingSettingsService $settingsService,
        private readonly ProspectProviderRegistry $prospectProviderRegistry,
        private readonly ProspectProviderConnectionService $prospectProviderConnectionService,
    ) {
    }

    public function __invoke(Request $request)
    {
        [$owner, $canView] = $this->resolveAccess($request->user());
        if (!$canView) {
            abort(403);
        }

        $settings = $this->settingsService->getResolved($owner);
        $enabledChannels = collect(CampaignChannel::values(true))
            ->filter(fn ($channel) => $this->settingsService->isChannelEnabled($owner, $channel))
            ->values()
            ->all();

        return response()->json([
            'campaign_types' => CampaignType::values(),
            'channels' => CampaignChannel::values(),
            'enabled_channels' => $enabledChannels,
            'offer_types' => OfferType::values(),
            'offer_modes' => CampaignOfferMode::values(),
            'allowed_offer_modes' => $this->settingsService->allowedOfferModes($owner),
            'language_modes' => CampaignLanguageMode::values(),
            'audience_source_logic' => CampaignAudienceSourceLogic::values(),
            'template_channels' => [
                'EMAIL' => ['subject', 'previewText', 'html'],
                'SMS' => ['text', 'shortener'],
                'IN_APP' => ['title', 'body', 'deepLink', 'image'],
            ],
            'prospect_providers' => $this->prospectProviderRegistry->definitions(),
            'prospect_provider_connections' => $this->prospectProviderConnectionService->listPayloads($owner),
            'settings' => $settings,
        ]);
    }

    private function resolveAccess(?User $user): array
    {
        if (!$user) {
            abort(401);
        }

        $ownerId = $user->accountOwnerId();
        $owner = $ownerId === $user->id
            ? $user
            : User::query()->select(['id'])->find($ownerId);
        if (!$owner) {
            abort(403);
        }

        if ($user->id === $owner->id) {
            return [$owner, true];
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();

        $canView = (bool) (
            $membership?->hasPermission('campaigns.view')
            || $membership?->hasPermission('campaigns.manage')
            || $membership?->hasPermission('campaigns.send')
            || $membership?->hasPermission('sales.manage')
        );

        return [$owner, $canView];
    }
}
