<?php

namespace App\Notifications;

use App\Support\LocalePreference;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UpcomingBillingReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly array $payload,
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
        $locale = LocalePreference::forNotifiable($notifiable);
        $subject = LocalePreference::trans('mail.billing_upcoming.subject', [
            'amount' => (string) ($this->payload['formattedTotal'] ?? '-'),
            'date' => (string) ($this->payload['billingDateLabel'] ?? ($this->payload['billingDate'] ?? '-')),
        ], $locale);

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.billing.upcoming-reminder', $this->payload);
    }
}
