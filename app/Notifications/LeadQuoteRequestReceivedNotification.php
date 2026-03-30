<?php

namespace App\Notifications;

use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\User;
use App\Support\LocalePreference;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class LeadQuoteRequestReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public User $owner,
        public LeadRequest $lead,
        public Quote $quote,
        public bool $quoteEmailSent = true
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
        $locale = LocalePreference::forNotifiable($notifiable, $this->owner);
        $isFr = str_starts_with($locale, 'fr');
        $companyName = $this->owner->company_name ?: config('app.name');
        $companyLogo = $this->owner->company_logo_url;
        $leadLabel = trim((string) ($this->lead->title ?: $this->lead->service_type ?: ('Lead #'.$this->lead->id)));
        if ($leadLabel === '') {
            $leadLabel = 'Lead #'.$this->lead->id;
        }

        $quoteLabel = ! empty($this->quote->number)
            ? (string) $this->quote->number
            : ('#'.$this->quote->id);

        $description = trim((string) ($this->lead->description ?? ''));
        $description = $description !== '' ? Str::limit($description, 220) : '-';

        $subject = $this->quoteEmailSent
            ? ($isFr ? 'Demande recue - devis genere' : 'Request received - quote generated')
            : ($isFr ? 'Demande recue - envoi du devis en attente' : 'Request received - quote delivery pending');
        $intro = $this->quoteEmailSent
            ? ($isFr
                ? "Nous avons bien recu votre demande pour {$leadLabel} et genere votre devis {$quoteLabel}."
                : "We received your request for {$leadLabel} and generated your quote {$quoteLabel}.")
            : ($isFr
                ? "Nous avons bien recu votre demande pour {$leadLabel} et genere votre devis {$quoteLabel}. Notre equipe vous contactera sous peu car l envoi email du devis est retarde."
                : "We received your request for {$leadLabel} and generated your quote {$quoteLabel}. Our team will contact you shortly because quote email delivery is delayed.");
        $note = $this->quoteEmailSent
            ? ($isFr
                ? 'Votre devis a ete envoye dans un email separe avec le lien securise.'
                : 'Your quote was sent in a separate email with the secure access link.')
            : ($isFr
                ? 'Votre devis est pret. Si vous ne le recevez pas rapidement, notre equipe fera un suivi direct.'
                : 'Your quote is ready. If you do not receive it shortly, our team will follow up directly.');

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.notifications.action', [
                'title' => $isFr ? 'Demande recue' : 'Request received',
                'intro' => $intro,
                'details' => array_values(array_filter([
                    ['label' => $isFr ? 'Demande' : 'Request', 'value' => $leadLabel],
                    ['label' => $isFr ? 'Service' : 'Service', 'value' => $this->lead->service_type ?: '-'],
                    ['label' => $isFr ? 'Resume' : 'Summary', 'value' => $description],
                    $this->lead->contact_name ? ['label' => $isFr ? 'Contact' : 'Contact', 'value' => $this->lead->contact_name] : null,
                    $this->lead->contact_email ? ['label' => 'Email', 'value' => $this->lead->contact_email] : null,
                    $this->lead->contact_phone ? ['label' => $isFr ? 'Telephone' : 'Phone', 'value' => $this->lead->contact_phone] : null,
                    ['label' => $isFr ? 'Devis' : 'Quote', 'value' => $quoteLabel],
                    ['label' => $isFr ? 'Total' : 'Total', 'value' => '$'.number_format((float) ($this->quote->total ?? 0), 2)],
                ])),
                'actionUrl' => null,
                'actionLabel' => null,
                'note' => $note,
                'companyName' => $companyName,
                'companyLogo' => $companyLogo,
            ]);
    }
}
