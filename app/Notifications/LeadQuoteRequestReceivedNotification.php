<?php

namespace App\Notifications;

use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\User;
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

        $quoteLabel = !empty($this->quote->number)
            ? (string) $this->quote->number
            : ('#' . $this->quote->id);

        $description = trim((string) ($this->lead->description ?? ''));
        $description = $description !== '' ? Str::limit($description, 220) : '-';

        $subject = $this->quoteEmailSent
            ? 'Request received - quote generated'
            : 'Request received - quote delivery pending';
        $intro = $this->quoteEmailSent
            ? "We received your request for {$leadLabel} and generated your quote {$quoteLabel}."
            : "We received your request for {$leadLabel} and generated your quote {$quoteLabel}. Our team will contact you shortly because quote email delivery is delayed.";
        $note = $this->quoteEmailSent
            ? 'Your quote was sent in a separate email with the secure access link.'
            : 'Your quote is ready. If you do not receive it shortly, our team will follow up directly.';

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.notifications.action', [
                'title' => 'Request received',
                'intro' => $intro,
                'details' => array_values(array_filter([
                    ['label' => 'Request', 'value' => $leadLabel],
                    ['label' => 'Service', 'value' => $this->lead->service_type ?: '-'],
                    ['label' => 'Summary', 'value' => $description],
                    $this->lead->contact_name ? ['label' => 'Contact', 'value' => $this->lead->contact_name] : null,
                    $this->lead->contact_email ? ['label' => 'Email', 'value' => $this->lead->contact_email] : null,
                    $this->lead->contact_phone ? ['label' => 'Phone', 'value' => $this->lead->contact_phone] : null,
                    ['label' => 'Quote', 'value' => $quoteLabel],
                    ['label' => 'Total', 'value' => '$' . number_format((float) ($this->quote->total ?? 0), 2)],
                ])),
                'actionUrl' => null,
                'actionLabel' => null,
                'note' => $note,
                'companyName' => $companyName,
                'companyLogo' => $companyLogo,
            ]);
    }
}
