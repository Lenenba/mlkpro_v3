<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\NotificationPreferenceService;
use App\Support\LocalePreference;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class InvoicePaymentNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Invoice $invoice,
        public Payment $payment,
        public string $audience = 'owner'
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $owner = $this->invoice->relationLoaded('user')
            ? $this->invoice->user
            : $this->invoice->user()->select(['id', 'locale'])->first();
        $locale = LocalePreference::forNotifiable($notifiable, $owner);
        $isFr = str_starts_with($locale, 'fr');
        $amount = '$'.number_format((float) $this->payment->amount, 2);
        $number = $this->invoice->number ?? $this->invoice->id;

        if ($this->audience === 'client') {
            $title = $isFr ? 'Paiement confirme' : 'Payment confirmed';
            $message = $isFr
                ? "Votre paiement de {$amount} pour la facture {$number} est confirme."
                : "Your payment of {$amount} for invoice {$number} is confirmed.";
            $actionUrl = route('dashboard');
        } else {
            $title = $isFr ? 'Paiement recu' : 'Payment received';
            $message = $isFr
                ? "La facture {$number} a ete payee ({$amount})."
                : "Invoice {$number} was paid ({$amount}).";
            $actionUrl = route('invoice.show', $this->invoice->id);
        }

        return [
            'title' => $title,
            'message' => $message,
            'action_url' => $actionUrl,
            'category' => NotificationPreferenceService::CATEGORY_BILLING,
            'invoice_id' => $this->invoice->id,
            'payment_id' => $this->payment->id,
            'audience' => $this->audience,
        ];
    }
}
