<?php

namespace App\Notifications;

use App\Models\Product;
use App\Services\NotificationPreferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LowStockNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Product $product,
        public int $currentStock,
        public int $minimumStock
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Stock bas: ' . ($this->product->name ?? 'Produit'),
            'message' => "Stock {$this->currentStock} / min {$this->minimumStock}.",
            'action_url' => route('product.show', $this->product),
            'category' => NotificationPreferenceService::CATEGORY_STOCK,
            'product_id' => $this->product->id,
        ];
    }
}
