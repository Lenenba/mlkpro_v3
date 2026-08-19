<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\User;
use App\Services\TenantBrandingResolver;
use App\Support\LocalePreference;
use App\Support\QueueWorkload;
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
        $this->onQueue(QueueWorkload::queue('notifications'));
    }

    public function backoff(): array
    {
        return QueueWorkload::backoff('notifications', [60, 300, 900]);
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brandingResolver = app(TenantBrandingResolver::class);
        $accountOwner = $brandingResolver->resolveAccountOwner($this->owner) ?: $this->owner;
        $branding = $brandingResolver->forAccountOwner($accountOwner);
        $locale = LocalePreference::forNotifiable($notifiable, $accountOwner);
        $isFr = str_starts_with($locale, 'fr');
        $productName = $this->product->name ?? 'Produit';
        $subject = $isFr ? "Demande stock - {$productName}" : "Stock request - {$productName}";

        $message = (new MailMessage)
            ->subject($subject)
            ->view('emails.notifications.action', [
                'companyName' => $branding['name'],
                'companyLogo' => $branding['custom_logo_url'],
                'companyPrimaryColor' => $branding['primary_color'],
                'companyPrimaryForegroundColor' => $branding['primary_foreground_color'],
                'title' => $subject,
                'intro' => $isFr
                    ? "Nous sommes en stock bas pour {$productName}. Pouvez-vous confirmer la disponibilite et le delai de reapprovisionnement ?"
                    : "We are running low on {$productName}. Can you confirm availability and restock timing?",
                'details' => [
                    ['label' => 'SKU', 'value' => $this->product->sku ?? '-'],
                    ['label' => $isFr ? 'Stock actuel' : 'Current stock', 'value' => (string) ((int) $this->product->stock)],
                    ['label' => $isFr ? 'Stock minimum' : 'Minimum stock', 'value' => (string) ((int) $this->product->minimum_stock)],
                    ['label' => $isFr ? 'Entreprise' : 'Company', 'value' => $branding['name']],
                ],
                'note' => $this->customMessage
                    ? (($isFr ? 'Message complementaire : ' : 'Additional message: ').$this->customMessage)
                    : ($isFr
                        ? 'Merci de repondre a cet email avec la disponibilite et le delai de reapprovisionnement.'
                        : 'Please reply to this email with availability and restock timing.'),
                'actionUrl' => null,
            ]);

        if (! empty($accountOwner->email)) {
            $message->replyTo($accountOwner->email, $branding['name']);
        }

        return $message;
    }
}
