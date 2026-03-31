<?php

namespace App\Notifications;

use App\Models\Request as LeadRequest;
use App\Models\User;
use App\Support\LocalePreference;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeadCallRequestReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public User $owner,
        public LeadRequest $lead
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

        $title = $isFr ? 'Demande d appel recue' : 'Call request received';
        $intro = $isFr
            ? "Nous avons bien recu votre demande pour {$leadLabel}. Notre equipe vous contactera sous peu."
            : "We received your request for {$leadLabel}. Our team will contact you shortly.";
        $note = $isFr
            ? 'Aucun devis n a encore ete genere. Nous qualifierons votre besoin pendant l appel.'
            : 'No quote was generated yet. We will qualify your request during the call.';

        return (new MailMessage)
            ->subject($title)
            ->view('emails.notifications.action', [
                'title' => $title,
                'intro' => $intro,
                'details' => array_values(array_filter([
                    ['label' => $isFr ? 'Demande' : 'Request', 'value' => $leadLabel],
                    $this->lead->contact_name ? ['label' => $isFr ? 'Contact' : 'Contact', 'value' => $this->lead->contact_name] : null,
                    $this->lead->contact_email ? ['label' => $isFr ? 'Email' : 'Email', 'value' => $this->lead->contact_email] : null,
                    $this->lead->contact_phone ? ['label' => $isFr ? 'Telephone' : 'Phone', 'value' => $this->lead->contact_phone] : null,
                ])),
                'actionUrl' => null,
                'actionLabel' => null,
                'note' => $note,
                'companyName' => $companyName,
                'companyLogo' => $companyLogo,
            ]);
    }
}
