<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\NotificationPreferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class InvoicePaymentNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Invoice $invoice,
        public Payment $payment,
        public string $audience = 'owner'
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $amount = '$' . number_format((float) $this->payment->amount, 2);
        $number = $this->invoice->number ?? $this->invoice->id;

        if ($this->audience === 'client') {
            $title = 'Payment confirmed';
            $message = "Your payment of {$amount} for invoice {$number} is confirmed.";
            $actionUrl = route('dashboard');
        } else {
            $title = 'Payment received';
            $message = "Invoice {$number} was paid ({$amount}).";
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
