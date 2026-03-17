<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignProspect;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\Customer;
use App\Models\User;
use App\Services\Campaigns\AudienceResolver;
use App\Services\Campaigns\CampaignProspectingOutreachService;
use App\Services\Campaigns\CampaignService;
use App\Services\Campaigns\CampaignTrackingService;
use App\Services\Campaigns\TemplateRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CampaignRunController extends Controller
{
    public function __construct(
        private readonly CampaignService $campaignService,
        private readonly AudienceResolver $audienceResolver,
        private readonly TemplateRenderer $templateRenderer,
        private readonly CampaignTrackingService $trackingService,
        private readonly CampaignProspectingOutreachService $prospectingOutreachService,
    ) {}

    public function estimate(Request $request, Campaign $campaign)
    {
        [$owner, , $canManage] = $this->resolveCampaignAccess($request->user(), $campaign);
        if (! $canManage) {
            abort(403);
        }

        $counts = $this->campaignService->estimateAudience($campaign);

        return response()->json([
            'estimated' => $counts,
        ]);
    }

    public function preview(Request $request, Campaign $campaign)
    {
        [$owner, , $canManage] = $this->resolveCampaignAccess($request->user(), $campaign);
        if (! $canManage) {
            abort(403);
        }

        $validated = $request->validate([
            'sample_size' => 'nullable|integer|min:1|max:5',
        ]);
        $sampleSize = (int) ($validated['sample_size'] ?? 3);

        $campaign->loadMissing(['channels', 'offers.offer', 'products', 'user']);
        $resolved = $this->audienceResolver->resolveForCampaign($campaign);
        $customerIds = collect($resolved['eligible'])
            ->pluck('customer_id')
            ->filter()
            ->unique()
            ->take(50)
            ->values();

        $customers = $customerIds->isEmpty()
            ? collect()
            : Customer::query()
                ->where('user_id', $owner->id)
                ->whereIn('id', $customerIds->all())
                ->inRandomOrder()
                ->limit($sampleSize)
                ->with(['defaultProperty', 'portalUser'])
                ->get();
        $prospectIds = collect($resolved['eligible'])
            ->pluck('metadata.prospect_id')
            ->filter()
            ->unique()
            ->take(50)
            ->values();
        $prospects = $prospectIds->isEmpty()
            ? collect()
            : CampaignProspect::query()
                ->where('campaign_id', $campaign->id)
                ->where('user_id', $owner->id)
                ->whereIn('id', $prospectIds->all())
                ->inRandomOrder()
                ->limit($sampleSize)
                ->get();

        if ($customers->isEmpty() && $prospects->isEmpty()) {
            $customers = collect([null]);
        }

        $product = $campaign->offers->first()?->offer ?: $campaign->products->first();
        $previews = [];

        foreach ($customers as $customer) {
            $context = $this->templateRenderer->buildContext($campaign, $customer, $product);
            foreach ($campaign->channels->where('is_enabled', true) as $channelModel) {
                $rendered = $this->templateRenderer->renderChannel($channelModel, $context);
                $previews[] = [
                    'channel' => strtoupper((string) $channelModel->channel),
                    'customer' => $customer ? [
                        'id' => $customer->id,
                        'name' => trim(($customer->first_name ?? '').' '.($customer->last_name ?? '')),
                        'company' => $customer->company_name,
                        'email' => $customer->email,
                        'phone' => $customer->phone,
                    ] : null,
                    'subject' => $rendered['subject'] ?? null,
                    'title' => $rendered['title'] ?? null,
                    'body' => $rendered['body'] ?? null,
                    'character_count' => $rendered['character_count'] ?? null,
                    'sms_segments' => $rendered['sms_segments'] ?? null,
                    'sms_too_long' => $rendered['sms_too_long'] ?? false,
                    'invalid_tokens' => $rendered['invalid_tokens'] ?? [],
                ];
            }
        }

        foreach ($prospects as $prospect) {
            $context = $this->templateRenderer->buildContext(
                $campaign,
                null,
                $product,
                $this->prospectingOutreachService->contextExtrasFromProspect($prospect, $campaign)
            );
            foreach ($campaign->channels->where('is_enabled', true) as $channelModel) {
                $rendered = $this->templateRenderer->renderChannel($channelModel, $context);
                $previews[] = [
                    'channel' => strtoupper((string) $channelModel->channel),
                    'customer' => null,
                    'prospect' => [
                        'id' => $prospect->id,
                        'name' => trim(($prospect->first_name ?? '').' '.($prospect->last_name ?? '')),
                        'company' => $prospect->company_name,
                        'email' => $prospect->email,
                        'phone' => $prospect->phone,
                    ],
                    'subject' => $rendered['subject'] ?? null,
                    'title' => $rendered['title'] ?? null,
                    'body' => $rendered['body'] ?? null,
                    'character_count' => $rendered['character_count'] ?? null,
                    'sms_segments' => $rendered['sms_segments'] ?? null,
                    'sms_too_long' => $rendered['sms_too_long'] ?? false,
                    'invalid_tokens' => $rendered['invalid_tokens'] ?? [],
                ];
            }
        }

        return response()->json([
            'previews' => $previews,
            'estimated' => $resolved['counts'],
        ]);
    }

    public function testSend(Request $request, Campaign $campaign)
    {
        [, , $canManage, $canSend] = $this->resolveCampaignAccess($request->user(), $campaign);
        if (! $canManage && ! $canSend) {
            abort(403);
        }

        $validated = $request->validate([
            'channels' => 'nullable|array',
            'channels.*' => 'string',
        ]);

        $channels = collect($validated['channels'] ?? [])
            ->map(fn ($channel) => strtoupper((string) $channel))
            ->values()
            ->all();

        $results = $this->campaignService->sendTest($campaign, $request->user(), $channels);

        return response()->json([
            'results' => $results,
        ]);
    }

    public function sendNow(Request $request, Campaign $campaign)
    {
        [, , , $canSend] = $this->resolveCampaignAccess($request->user(), $campaign);
        if (! $canSend) {
            abort(403);
        }

        $run = $this->campaignService->queueRun(
            $campaign,
            $request->user(),
            CampaignRun::TRIGGER_MANUAL
        );

        return response()->json([
            'message' => 'Campaign run queued.',
            'run' => $run,
        ]);
    }

    public function schedule(Request $request, Campaign $campaign)
    {
        [, , , $canSend] = $this->resolveCampaignAccess($request->user(), $campaign);
        if (! $canSend) {
            abort(403);
        }

        $validated = $request->validate([
            'scheduled_at' => 'required|date|after:now',
        ]);

        $run = $this->campaignService->queueRun(
            $campaign,
            $request->user(),
            CampaignRun::TRIGGER_SCHEDULED,
            Carbon::parse((string) $validated['scheduled_at'])
        );

        return response()->json([
            'message' => 'Campaign scheduled.',
            'run' => $run,
        ]);
    }

    public function exportRecipients(Request $request, CampaignRun $run): StreamedResponse
    {
        $campaign = $run->campaign()->first();
        if (! $campaign) {
            abort(404);
        }

        [$owner, $canView] = $this->resolveCampaignAccess($request->user(), $campaign);
        if (! $canView || (int) $campaign->user_id !== (int) $owner->id) {
            abort(403);
        }

        $filename = sprintf('campaign-run-%d-recipients.csv', $run->id);

        return response()->streamDownload(function () use ($run): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, [
                'recipient_id',
                'customer_id',
                'customer_name',
                'channel',
                'destination',
                'status',
                'provider',
                'provider_message_id',
                'failure_reason',
                'sent_at',
                'delivered_at',
                'opened_at',
                'clicked_at',
                'converted_at',
            ]);

            CampaignRecipient::query()
                ->where('campaign_run_id', $run->id)
                ->with('customer:id,first_name,last_name,company_name')
                ->orderBy('id')
                ->chunkById(500, function ($recipients) use ($output): void {
                    foreach ($recipients as $recipient) {
                        $name = trim(
                            ($recipient->customer?->company_name ?: '')
                            ?: (($recipient->customer?->first_name ?? '').' '.($recipient->customer?->last_name ?? ''))
                        );

                        fputcsv($output, [
                            $recipient->id,
                            $recipient->customer_id,
                            $name,
                            $recipient->channel,
                            $recipient->destination,
                            $recipient->status,
                            $recipient->provider,
                            $recipient->provider_message_id,
                            $recipient->failure_reason,
                            optional($recipient->sent_at)->toDateTimeString(),
                            optional($recipient->delivered_at)->toDateTimeString(),
                            optional($recipient->opened_at)->toDateTimeString(),
                            optional($recipient->clicked_at)->toDateTimeString(),
                            optional($recipient->converted_at)->toDateTimeString(),
                        ]);
                    }
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function recordConversion(Request $request, Campaign $campaign)
    {
        [, $canView] = $this->resolveCampaignAccess($request->user(), $campaign);
        if (! $canView) {
            abort(403);
        }

        $validated = $request->validate([
            'campaign_recipient_id' => 'nullable|integer',
            'customer_id' => 'required|integer',
            'conversion_type' => 'required|string|max:40',
            'conversion_id' => 'required|integer',
        ]);

        $recipient = null;
        if (! empty($validated['campaign_recipient_id'])) {
            $recipient = CampaignRecipient::query()
                ->where('campaign_id', $campaign->id)
                ->whereKey((int) $validated['campaign_recipient_id'])
                ->first();
        }

        if (! $recipient) {
            $recipient = CampaignRecipient::query()
                ->where('campaign_id', $campaign->id)
                ->where('customer_id', (int) $validated['customer_id'])
                ->whereNotNull('clicked_at')
                ->latest('clicked_at')
                ->first();
        }

        if (! $recipient) {
            return response()->json([
                'message' => 'No eligible recipient found for conversion.',
            ], 404);
        }

        $this->trackingService->markConverted(
            $recipient,
            (string) $validated['conversion_type'],
            (int) $validated['conversion_id']
        );

        return response()->json([
            'message' => 'Conversion recorded.',
        ]);
    }

    private function resolveCampaignAccess(?User $user, Campaign $campaign): array
    {
        if (! $user) {
            abort(401);
        }

        $ownerId = $user->accountOwnerId();
        $owner = $ownerId === $user->id
            ? $user
            : User::query()->select(['id'])->find($ownerId);

        if (! $owner) {
            abort(403);
        }

        if ((int) $campaign->user_id !== (int) $owner->id) {
            abort(404);
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
