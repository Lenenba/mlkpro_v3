<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SupplierStockRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Product $product,
        public User $owner,
        public ?string $customMessage = null
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $productName = $this->product->name ?? 'Produit';
        $subject = "Demande stock - {$productName}";

        $message = (new MailMessage())
            ->subject($subject)
            ->greeting('Bonjour,')
            ->line("Nous sommes en stock bas pour {$productName}.")
            ->line('Pouvez-vous confirmer la disponibilite et le delai de reapprovisionnement ?')
            ->line('Details:')
            ->line("SKU: " . ($this->product->sku ?? '-'))
            ->line("Stock actuel: " . ((int) $this->product->stock))
            ->line("Stock minimum: " . ((int) $this->product->minimum_stock))
            ->line("Entreprise: " . ($this->owner->company_name ?: $this->owner->name));

        if ($this->customMessage) {
            $message->line('Message:')->line($this->customMessage);
        }

        if (!empty($this->owner->email)) {
            $message->replyTo($this->owner->email, $this->owner->company_name ?: $this->owner->name);
        }

        return $message->line('Merci.');
    }
}
