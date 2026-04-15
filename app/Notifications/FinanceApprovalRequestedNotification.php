<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Services\NotificationPreferenceService;
use App\Support\LocalePreference;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FinanceApprovalRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Invoice $invoice
    ) {
        $this->onQueue(QueueWorkload::queue('notifications'));
    }

    public function backoff(): array
    {
        return QueueWorkload::backoff('notifications', [60, 300, 900]);
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->titleFor($notifiable),
            'message' => $this->messageFor($notifiable),
            'action_url' => route('invoice.show', $this->invoice),
            'category' => NotificationPreferenceService::CATEGORY_BILLING,
            'event' => 'finance_approval_requested',
            'invoice_id' => $this->invoice->id,
            'invoice_number' => $this->invoice->number,
            'approval_status' => $this->invoice->approval_status,
            'current_approver_role_key' => $this->invoice->current_approver_role_key,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = LocalePreference::forNotifiable($notifiable);
        $isFrench = $locale === 'fr';
        $isSpanish = $locale === 'es';

        $subject = $this->titleFor($notifiable);
        $line = $this->messageFor($notifiable);
        $cta = $isFrench
            ? 'Ouvrir la facture'
            : ($isSpanish ? 'Abrir factura' : 'Open invoice');

        return (new MailMessage)
            ->subject($subject)
            ->line($line)
            ->action($cta, route('invoice.show', $this->invoice));
    }

    private function titleFor(object $notifiable): string
    {
        $locale = LocalePreference::forNotifiable($notifiable);

        return match ($locale) {
            'fr' => "Facture en attente d'approbation",
            'es' => 'Factura pendiente de aprobacion',
            default => 'Invoice awaiting approval',
        };
    }

    private function messageFor(object $notifiable): string
    {
        $locale = LocalePreference::forNotifiable($notifiable);
        $number = $this->invoice->number ?: '#'.$this->invoice->id;
        $customer = $this->invoice->customer?->company_name
            ?: trim(($this->invoice->customer?->first_name ?: '').' '.($this->invoice->customer?->last_name ?: ''));
        $amount = number_format((float) $this->invoice->total, 2, '.', ' ');

        return match ($locale) {
            'fr' => "La facture {$number} pour {$customer} attend une validation finance ({$amount}).",
            'es' => "La factura {$number} para {$customer} espera validacion financiera ({$amount}).",
            default => "Invoice {$number} for {$customer} is waiting for finance approval ({$amount}).",
        };
    }
}
