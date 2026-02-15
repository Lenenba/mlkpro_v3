<?php

namespace App\Notifications;

use App\Models\Request as LeadRequest;
use App\Models\User;
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
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $companyName = $this->owner->company_name ?: config('app.name');
        $companyLogo = $this->owner->company_logo_url;
        $leadLabel = trim((string) ($this->lead->title ?: $this->lead->service_type ?: ('Lead #' . $this->lead->id)));
        if ($leadLabel === '') {
            $leadLabel = 'Lead #' . $this->lead->id;
        }

        return (new MailMessage)
            ->subject('Call request received')
            ->view('emails.notifications.action', [
                'title' => 'Call request received',
                'intro' => "We received your request for {$leadLabel}. Our team will contact you shortly.",
                'details' => array_values(array_filter([
                    ['label' => 'Request', 'value' => $leadLabel],
                    $this->lead->contact_name ? ['label' => 'Contact', 'value' => $this->lead->contact_name] : null,
                    $this->lead->contact_email ? ['label' => 'Email', 'value' => $this->lead->contact_email] : null,
                    $this->lead->contact_phone ? ['label' => 'Phone', 'value' => $this->lead->contact_phone] : null,
                ])),
                'actionUrl' => null,
                'actionLabel' => null,
                'note' => 'No quote was generated yet. We will qualify your request during the call.',
                'companyName' => $companyName,
                'companyLogo' => $companyLogo,
            ]);
    }
}

