<?php

namespace App\Notifications;

use App\Services\NotificationPreferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ReservationDatabaseNotification extends Notification implements ShouldQueue
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
            'title' => (string) ($this->payload['title'] ?? 'Reservation update'),
            'message' => (string) ($this->payload['message'] ?? ''),
            'action_url' => $this->payload['action_url'] ?? null,
            'category' => NotificationPreferenceService::CATEGORY_PLANNING,
            'event' => $this->payload['event'] ?? null,
            'reservation_id' => $this->payload['reservation_id'] ?? null,
            'status' => $this->payload['status'] ?? null,
            'starts_at' => $this->payload['starts_at'] ?? null,
        ];
    }
}
