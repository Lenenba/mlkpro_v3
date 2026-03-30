<?php

namespace App\Notifications;

use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Services\NotificationPreferenceService;
use App\Support\LocalePreference;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeadFormOwnerNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $event,
        public LeadRequest $lead,
        public ?Quote $quote = null,
        public bool $sendMail = true
    ) {
        $this->onQueue(QueueWorkload::queue('notifications'));
    }

    public function backoff(): array
    {
        return QueueWorkload::backoff('notifications', [60, 300, 900]);
    }

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($this->sendMail && ! empty($notifiable->email)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $owner = $this->lead->user;
        $locale = LocalePreference::forNotifiable($notifiable, $owner);
        $isFr = str_starts_with($locale, 'fr');
        $title = $this->title($isFr);
        $companyName = $owner?->company_name ?: config('app.name');
        $companyLogo = $owner?->company_logo_url;

        return (new MailMessage)
            ->subject($title)
            ->view('emails.notifications.action', [
                'title' => $title,
                'intro' => $this->message($isFr),
                'details' => $this->details($isFr),
                'actionUrl' => $this->actionUrl(),
                'actionLabel' => $isFr ? 'Ouvrir le lead' : 'Open lead',
                'note' => $this->note($isFr),
                'companyName' => $companyName,
                'companyLogo' => $companyLogo,
            ]);
    }

    public function toArray(object $notifiable): array
    {
        $owner = $this->lead->user;
        $locale = LocalePreference::forNotifiable($notifiable, $owner);
        $isFr = str_starts_with($locale, 'fr');

        return [
            'title' => $this->title($isFr),
            'message' => $this->message($isFr),
            'action_url' => $this->actionUrl(),
            'category' => NotificationPreferenceService::CATEGORY_CRM,
            'event' => $this->event,
            'lead_id' => $this->lead->id,
            'quote_id' => $this->quote?->id,
        ];
    }

    private function title(bool $isFr = false): string
    {
        if ($this->event === 'lead_call_requested') {
            return $isFr ? 'Nouvelle demande d appel depuis le formulaire lead' : 'New call request from lead form';
        }

        if ($this->event === 'lead_email_failed') {
            return $isFr ? 'Echec de l envoi du devis' : 'Quote email failed';
        }

        return $isFr ? 'Nouveau devis cree depuis le formulaire lead' : 'New quote created from lead form';
    }

    private function message(bool $isFr = false): string
    {
        $leadLabel = $this->leadLabel();
        if ($this->event === 'lead_call_requested') {
            return $isFr
                ? "Le lead {$leadLabel} a demande un appel et doit etre qualifie."
                : "Lead {$leadLabel} requested a call and needs qualification.";
        }

        if ($this->event === 'lead_email_failed') {
            $quoteLabel = $this->quoteLabel();

            return $isFr
                ? "Le devis {$quoteLabel} a ete cree pour {$leadLabel}, mais l envoi email au client a echoue."
                : "Quote {$quoteLabel} was created for {$leadLabel}, but customer email delivery failed.";
        }

        $quoteLabel = $this->quoteLabel();

        return $isFr
            ? "Le lead {$leadLabel} a genere le devis {$quoteLabel}."
            : "Lead {$leadLabel} generated quote {$quoteLabel}.";
    }

    private function details(bool $isFr = false): array
    {
        $details = [
            ['label' => 'Lead', 'value' => $this->leadLabel()],
            ['label' => $isFr ? 'Contact' : 'Contact', 'value' => $this->lead->contact_name ?: '-'],
            ['label' => 'Email', 'value' => $this->lead->contact_email ?: '-'],
            ['label' => $isFr ? 'Telephone' : 'Phone', 'value' => $this->lead->contact_phone ?: '-'],
            ['label' => $isFr ? 'Statut' : 'Status', 'value' => $this->lead->status ?: '-'],
        ];

        if ($this->quote) {
            $details[] = ['label' => $isFr ? 'Devis' : 'Quote', 'value' => $this->quoteLabel()];
            $details[] = ['label' => $isFr ? 'Total' : 'Total', 'value' => '$'.number_format((float) ($this->quote->total ?? 0), 2)];
        }

        return $details;
    }

    private function note(bool $isFr = false): ?string
    {
        if ($this->event === 'lead_email_failed') {
            return $isFr
                ? 'Le devis reste cree. Relancez l envoi email depuis l ecran du devis.'
                : 'The quote remains created. Retry email delivery from the quote screen.';
        }

        if ($this->event === 'lead_call_requested') {
            return $isFr
                ? 'Une tache de suivi a ete creee pour qualifier le lead et planifier un appel.'
                : 'A follow-up task has been created to qualify the lead and schedule a call.';
        }

        return null;
    }

    private function actionUrl(): string
    {
        return route('request.show', ['lead' => $this->lead->id]);
    }

    private function leadLabel(): string
    {
        $label = trim((string) ($this->lead->title ?: $this->lead->service_type ?: 'Lead #'.$this->lead->id));

        return $label !== '' ? $label : 'Lead #'.$this->lead->id;
    }

    private function quoteLabel(): string
    {
        if (! $this->quote) {
            return 'N/A';
        }

        if (! empty($this->quote->number)) {
            return (string) $this->quote->number;
        }

        return '#'.$this->quote->id;
    }
}
