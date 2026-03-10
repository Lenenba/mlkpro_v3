<?php

namespace App\Notifications;

use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class CampaignInAppNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public array $payload
    ) {
        $this->onQueue(QueueWorkload::queue('notifications'));
    }

    public function backoff(): array
    {
        return QueueWorkload::backoff('notifications', [60, 300, 900]);
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
