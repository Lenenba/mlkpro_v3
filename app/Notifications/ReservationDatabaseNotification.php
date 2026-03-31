<?php

namespace App\Notifications;

use App\Services\NotificationPreferenceService;
use App\Support\LocalePreference;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ReservationDatabaseNotification extends Notification implements ShouldQueue
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
        $title = (string) ($this->payload['title'] ?? LocalePreference::trans('mail.action.default_title', locale: LocalePreference::forNotifiable($notifiable)));

        return [
            'title' => $title,
            'message' => (string) ($this->payload['message'] ?? ''),
            'action_url' => $this->payload['action_url'] ?? null,
            'category' => NotificationPreferenceService::CATEGORY_PLANNING,
            'event' => $this->payload['event'] ?? null,
            'reservation_id' => $this->payload['reservation_id'] ?? null,
            'queue_item_id' => $this->payload['queue_item_id'] ?? null,
            'status' => $this->payload['status'] ?? null,
            'starts_at' => $this->payload['starts_at'] ?? null,
        ];
    }
}
