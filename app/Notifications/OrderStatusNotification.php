<?php

namespace App\Notifications;

use App\Models\Sale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class OrderStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Sale $sale,
        public string $title,
        public string $message,
        public ?string $actionUrl = null
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'action_url' => $this->actionUrl ?? route('portal.orders.edit', $this->sale),
            'sale_id' => $this->sale->id,
            'sale_number' => $this->sale->number,
            'status' => $this->sale->status,
            'fulfillment_status' => $this->sale->fulfillment_status,
        ];
    }
}
