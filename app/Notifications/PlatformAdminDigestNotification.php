<?php

namespace App\Notifications;

use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PlatformAdminDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private string $frequency;

    private array $items;

    public function __construct(string $frequency, array $items)
    {
        $this->frequency = $frequency;
        $this->items = $items;
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
        $label = $this->frequency === 'weekly' ? 'Weekly' : 'Daily';

        return (new MailMessage)
            ->subject($label.' admin digest')
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
