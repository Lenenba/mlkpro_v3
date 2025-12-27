<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PlatformAdminDigestNotification extends Notification
{
    use Queueable;

    private string $frequency;
    private array $items;

    public function __construct(string $frequency, array $items)
    {
        $this->frequency = $frequency;
        $this->items = $items;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $label = $this->frequency === 'weekly' ? 'Weekly' : 'Daily';

        return (new MailMessage())
            ->subject($label . ' admin digest')
            ->view('emails.notifications.digest', [
                'frequency' => $label,
                'items' => $this->items,
                'generatedAt' => now(),
                'companyName' => config('app.name'),
                'companyLogo' => null,
                'supportEmail' => config('mail.from.address'),
            ]);
    }
}
