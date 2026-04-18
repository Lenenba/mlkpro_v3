<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\User;
use App\Support\LocalePreference;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class SupplierBulkStockRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Collection<int, Product>  $products
     */
    public function __construct(
        public Collection $products,
        public User $owner
    ) {
        $this->products = $products->values();
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
        $locale = LocalePreference::forNotifiable($notifiable, $this->owner);
        $isFr = str_starts_with($locale, 'fr');
        $count = $this->products->count();
        $companyName = $this->owner->company_name ?: $this->owner->name;

        $subject = $isFr
            ? ($count === 1
                ? 'Demande stock - '.$this->products->first()?->name
                : 'Demande reapprovisionnement - '.$count.' produits')
            : ($count === 1
                ? 'Stock request - '.$this->products->first()?->name
                : 'Restock request - '.$count.' products');

        $details = [
            [
                'label' => $isFr ? 'Entreprise' : 'Company',
                'value' => $companyName,
            ],
            [
                'label' => $isFr ? 'Produits demandes' : 'Requested items',
                'value' => (string) $count,
            ],
        ];

        foreach ($this->products as $product) {
            $details[] = [
                'label' => $product->name ?: ($isFr ? 'Produit' : 'Product'),
                'value' => $this->detailLine($product, $isFr),
            ];
        }

        $message = (new MailMessage)
            ->subject($subject)
            ->view('emails.notifications.action', [
                'companyName' => $companyName,
                'companyLogo' => null,
                'title' => $subject,
                'intro' => $isFr
                    ? 'Nous souhaitons reapprovisionner les articles suivants. Pouvez-vous confirmer la disponibilite et le delai de livraison ?'
                    : 'We would like to restock the following items. Can you confirm availability and lead time?',
                'details' => $details,
                'note' => $isFr
                    ? 'Merci de repondre a cet email avec les disponibilites, delais et quantites recommandees.'
                    : 'Please reply with availability, lead times, and recommended quantities.',
                'actionUrl' => null,
            ]);

        if (! empty($this->owner->email)) {
            $message->replyTo($this->owner->email, $companyName);
        }

        return $message;
    }

    private function detailLine(Product $product, bool $isFr): string
    {
        $sku = $product->sku ?: '-';
        $currentStock = (int) ($product->stock ?? 0);
        $minimumStock = (int) ($product->minimum_stock ?? 0);
        $suggestedQuantity = max(($minimumStock > 0 ? $minimumStock : 1) - $currentStock, 1);

        if ($isFr) {
            return sprintf(
                'SKU %s | Stock %d / Min %d | Suggestion %d',
                $sku,
                $currentStock,
                $minimumStock,
                $suggestedQuantity
            );
        }

        return sprintf(
            'SKU %s | Stock %d / Min %d | Suggested %d',
            $sku,
            $currentStock,
            $minimumStock,
            $suggestedQuantity
        );
    }
}
