<?php

namespace App\Services\Campaigns;

use App\Models\CampaignEvent;
use App\Models\CampaignRecipient;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CampaignTrackingService
{
    public function __construct(
        private readonly ConsentService $consentService,
    ) {
    }

    public function ensureTokens(CampaignRecipient $recipient): CampaignRecipient
    {
        $updates = [];
        if (!$recipient->tracking_token) {
            $updates['tracking_token'] = $this->generateToken();
        }

        if (!$recipient->unsubscribe_token && strtoupper((string) $recipient->channel) === 'EMAIL') {
            $updates['unsubscribe_token'] = $this->generateToken();
        }

        if ($updates !== []) {
            $recipient->forceFill($updates)->save();
            $recipient->refresh();
        }

        return $recipient;
    }

    public function trackedUrl(CampaignRecipient $recipient): string
    {
        $recipient = $this->ensureTokens($recipient);

        return route('campaigns.track', ['token' => $recipient->tracking_token]);
    }

    public function unsubscribeUrl(CampaignRecipient $recipient): ?string
    {
        $recipient = $this->ensureTokens($recipient);
        if (!$recipient->unsubscribe_token) {
            return null;
        }

        return route('campaigns.unsubscribe', ['token' => $recipient->unsubscribe_token]);
    }

    public function recordEvent(
        CampaignRecipient $recipient,
        string $eventType,
        array $metadata = [],
        ?Carbon $occurredAt = null
    ): CampaignEvent {
        return CampaignEvent::query()->create([
            'campaign_id' => $recipient->campaign_id,
            'campaign_run_id' => $recipient->campaign_run_id,
            'campaign_recipient_id' => $recipient->id,
            'user_id' => $recipient->user_id,
            'customer_id' => $recipient->customer_id,
            'channel' => $recipient->channel,
            'event_type' => $eventType,
            'provider_message_id' => $recipient->provider_message_id,
            'occurred_at' => $occurredAt ?: now(),
            'metadata' => $metadata ?: null,
        ]);
    }

    public function markQueued(CampaignRecipient $recipient): void
    {
        $recipient->forceFill([
            'status' => CampaignRecipient::STATUS_QUEUED,
            'queued_at' => $recipient->queued_at ?: now(),
        ])->save();

        $this->recordEvent($recipient, CampaignEvent::EVENT_QUEUED);
    }

    public function markSent(CampaignRecipient $recipient, ?string $provider = null, ?string $providerMessageId = null): void
    {
        $recipient->forceFill([
            'status' => CampaignRecipient::STATUS_SENT,
            'provider' => $provider,
            'provider_message_id' => $providerMessageId,
            'sent_at' => now(),
        ])->save();

        $this->recordEvent($recipient, CampaignEvent::EVENT_SENT);
    }

    public function markDelivered(CampaignRecipient $recipient, array $metadata = []): void
    {
        $recipient->forceFill([
            'status' => CampaignRecipient::STATUS_DELIVERED,
            'delivered_at' => $recipient->delivered_at ?: now(),
        ])->save();

        $this->recordEvent($recipient, CampaignEvent::EVENT_DELIVERED, $metadata);
    }

    public function markOpened(CampaignRecipient $recipient, array $metadata = []): void
    {
        $recipient->forceFill([
            'status' => CampaignRecipient::STATUS_OPENED,
            'opened_at' => $recipient->opened_at ?: now(),
        ])->save();

        $this->recordEvent($recipient, CampaignEvent::EVENT_OPENED, $metadata);
    }

    public function markClicked(CampaignRecipient $recipient, array $metadata = []): void
    {
        $recipient->forceFill([
            'status' => CampaignRecipient::STATUS_CLICKED,
            'clicked_at' => $recipient->clicked_at ?: now(),
        ])->save();

        $this->recordEvent($recipient, CampaignEvent::EVENT_CLICKED, $metadata);
    }

    public function markConverted(
        CampaignRecipient $recipient,
        string $conversionType,
        int $conversionId,
        array $metadata = []
    ): void {
        $recipient->forceFill([
            'status' => CampaignRecipient::STATUS_CONVERTED,
            'converted_at' => $recipient->converted_at ?: now(),
        ])->save();

        CampaignEvent::query()->create([
            'campaign_id' => $recipient->campaign_id,
            'campaign_run_id' => $recipient->campaign_run_id,
            'campaign_recipient_id' => $recipient->id,
            'user_id' => $recipient->user_id,
            'customer_id' => $recipient->customer_id,
            'channel' => $recipient->channel,
            'event_type' => CampaignEvent::EVENT_CONVERTED,
            'provider_message_id' => $recipient->provider_message_id,
            'conversion_type' => $conversionType,
            'conversion_id' => $conversionId,
            'occurred_at' => now(),
            'metadata' => $metadata ?: null,
        ]);
    }

    public function markFailed(CampaignRecipient $recipient, string $reason, array $metadata = []): void
    {
        $recipient->forceFill([
            'status' => CampaignRecipient::STATUS_FAILED,
            'failed_at' => $recipient->failed_at ?: now(),
            'failure_reason' => $reason,
        ])->save();

        $this->recordEvent($recipient, CampaignEvent::EVENT_FAILED, array_merge($metadata, ['reason' => $reason]));
    }

    public function resolveClickToken(string $token): ?array
    {
        $recipient = CampaignRecipient::query()
            ->with(['message', 'campaign'])
            ->where('tracking_token', $token)
            ->first();

        if (!$recipient) {
            return null;
        }

        $this->markClicked($recipient, ['source' => 'tracking_link']);
        $destination = $recipient->message?->cta_url ?: $recipient->campaign?->cta_url;
        if (!$destination) {
            return null;
        }

        return [
            'recipient' => $recipient,
            'url' => $destination,
        ];
    }

    public function unsubscribeByToken(string $token): ?CampaignRecipient
    {
        $recipient = CampaignRecipient::query()
            ->with(['campaign.user', 'customer'])
            ->where('unsubscribe_token', $token)
            ->first();

        if (!$recipient || !$recipient->campaign || !$recipient->campaign->user) {
            return null;
        }

        $this->consentService->revoke(
            $recipient->campaign->user,
            $recipient->customer,
            $recipient->channel,
            (string) $recipient->destination,
            'campaign_unsubscribe',
            'unsubscribe_link'
        );

        $this->recordEvent($recipient, CampaignEvent::EVENT_UNSUBSCRIBE, [
            'source' => 'unsubscribe_link',
        ]);

        return $recipient;
    }

    public function applyProviderStatus(string $providerMessageId, string $status, array $metadata = []): ?CampaignRecipient
    {
        $recipient = CampaignRecipient::query()
            ->where('provider_message_id', $providerMessageId)
            ->first();

        if (!$recipient) {
            return null;
        }

        $normalized = strtolower(trim($status));
        if (in_array($normalized, ['sent', 'queued', 'accepted'], true)) {
            return $recipient;
        }

        if (in_array($normalized, ['delivered', 'delivery_success'], true)) {
            $this->markDelivered($recipient, $metadata);
            return $recipient;
        }

        if (in_array($normalized, ['opened', 'open'], true)) {
            $this->markOpened($recipient, $metadata);
            return $recipient;
        }

        if (in_array($normalized, ['clicked', 'click'], true)) {
            $this->markClicked($recipient, $metadata);
            return $recipient;
        }

        if (in_array($normalized, ['failed', 'undelivered', 'bounced'], true)) {
            $reason = (string) ($metadata['reason'] ?? $normalized);
            $this->markFailed($recipient, $reason, $metadata);
            return $recipient;
        }

        return $recipient;
    }

    public function conversionCandidates(
        User $accountOwner,
        int $hours = 72
    ) {
        return CampaignRecipient::query()
            ->where('user_id', $accountOwner->id)
            ->whereNotNull('clicked_at')
            ->whereNull('converted_at')
            ->where('clicked_at', '>=', now()->subHours(max(1, $hours)))
            ->with(['customer'])
            ->orderByDesc('clicked_at')
            ->get();
    }

    private function generateToken(): string
    {
        return Str::random(64);
    }
}
