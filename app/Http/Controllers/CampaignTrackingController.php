<?php

namespace App\Http\Controllers;

use App\Jobs\ReconcileDeliveryReportsJob;
use App\Services\Campaigns\CampaignLeadAttributionService;
use App\Services\Campaigns\CampaignRunProgressService;
use App\Services\Campaigns\CampaignTrackingService;
use Illuminate\Http\Request;

class CampaignTrackingController extends Controller
{
    public function track(
        Request $request,
        string $token,
        CampaignTrackingService $trackingService,
        CampaignLeadAttributionService $leadAttributionService
    )
    {
        $resolved = $trackingService->resolveClickToken($token);
        if (!$resolved || empty($resolved['url'])) {
            abort(404);
        }

        if (! empty($resolved['recipient'])) {
            $leadAttributionService->rememberRecipientClick($request, $resolved['recipient']);
        }

        return redirect()->away((string) $resolved['url']);
    }

    public function unsubscribe(string $token, CampaignTrackingService $trackingService)
    {
        $recipient = $trackingService->unsubscribeByToken($token);
        if (!$recipient) {
            abort(404);
        }

        return response()->view('campaigns.unsubscribed', [
            'campaign' => $recipient->campaign,
        ]);
    }

    public function smsWebhook(
        Request $request,
        CampaignTrackingService $trackingService,
        CampaignRunProgressService $progressService
    ) {
        if (!$this->validWebhookSecret($request, 'sms_secret')) {
            abort(403);
        }

        $validated = $request->validate([
            'message_id' => 'required|string|max:191',
            'status' => 'required|string|max:80',
            'reason' => 'nullable|string|max:255',
        ]);

        $recipient = $trackingService->applyProviderStatus(
            (string) $validated['message_id'],
            (string) $validated['status'],
            ['reason' => $validated['reason'] ?? null]
        );

        if ($recipient) {
            $run = $recipient->run;
            if ($run) {
                $progressService->refresh($run);
            }
        }

        return response()->json(['ok' => true]);
    }

    public function emailWebhook(
        Request $request,
        CampaignTrackingService $trackingService,
        CampaignRunProgressService $progressService
    ) {
        if (!$this->validWebhookSecret($request, 'email_secret')) {
            abort(403);
        }

        $validated = $request->validate([
            'message_id' => 'required|string|max:191',
            'status' => 'required|string|max:80',
            'reason' => 'nullable|string|max:255',
        ]);

        $recipient = $trackingService->applyProviderStatus(
            (string) $validated['message_id'],
            (string) $validated['status'],
            ['reason' => $validated['reason'] ?? null]
        );

        if ($recipient) {
            $run = $recipient->run;
            if ($run) {
                $progressService->refresh($run);
            }
        }

        return response()->json(['ok' => true]);
    }

    public function reconcile()
    {
        ReconcileDeliveryReportsJob::dispatch()
            ->onQueue((string) config('campaigns.queues.maintenance', 'campaigns-maintenance'));

        return response()->json([
            'queued' => true,
        ]);
    }

    private function validWebhookSecret(Request $request, string $key): bool
    {
        $expected = (string) config('campaigns.webhooks.' . $key);
        if ($expected === '') {
            return false;
        }

        $provided = (string) $request->header('X-Campaign-Webhook-Secret', '');
        return hash_equals($expected, $provided);
    }
}
