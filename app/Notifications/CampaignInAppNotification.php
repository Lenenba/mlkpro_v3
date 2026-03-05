<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class CampaignInAppNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public array $payload
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => (string) ($this->payload['title'] ?? 'Campaign'),
            'message' => (string) ($this->payload['message'] ?? ''),
            'action_url' => $this->payload['action_url'] ?? null,
            'category' => 'marketing',
            'campaign_id' => $this->payload['campaign_id'] ?? null,
            'campaign_run_id' => $this->payload['campaign_run_id'] ?? null,
            'campaign_recipient_id' => $this->payload['campaign_recipient_id'] ?? null,
        ];
    }
}
