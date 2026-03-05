<?php

namespace App\Http\Controllers\Settings;

use App\Enums\CampaignLanguageMode;
use App\Enums\CampaignOfferMode;
use App\Enums\CampaignType;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Services\Campaigns\MarketingSettingsService;
use Illuminate\Http\Request;

class MarketingSettingsController extends Controller
{
    public function __construct(
        private readonly MarketingSettingsService $marketingSettingsService,
    ) {
    }

    public function edit(Request $request)
    {
        $owner = $this->ownerOrFail($request);
        $settings = $this->marketingSettingsService->getResolved($owner);

        return $this->inertiaOrJson('Settings/Marketing', [
            'marketingSettings' => $settings,
            'enums' => [
                'campaign_types' => CampaignType::values(),
                'channels' => Campaign::allowedChannels(),
                'offer_modes' => CampaignOfferMode::values(),
                'language_modes' => CampaignLanguageMode::values(),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $owner = $this->ownerOrFail($request);

        $validated = $request->validate([
            'channels' => 'nullable|array',
            'consent' => 'nullable|array',
            'audience' => 'nullable|array',
            'templates' => 'nullable|array',
            'tracking' => 'nullable|array',
            'offers' => 'nullable|array',
        ]);

        $model = $this->marketingSettingsService->update($owner, $validated);
        $resolved = $this->marketingSettingsService->getResolved($owner);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Marketing settings updated.',
                'marketingSettings' => $resolved,
            ]);
        }

        return redirect()->back()->with('success', 'Parametres marketing enregistres.');
    }

    private function ownerOrFail(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $ownerId = $user->accountOwnerId();
        if ((int) $user->id !== (int) $ownerId) {
            abort(403);
        }

        return $user;
    }
}

