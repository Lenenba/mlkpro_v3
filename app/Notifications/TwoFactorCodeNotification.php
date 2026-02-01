<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;

class TwoFactorCodeNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $code, private readonly ?Carbon $expiresAt)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = $this->expiresAt
            ? max(1, (int) ceil(now()->diffInSeconds($this->expiresAt) / 60))
            : null;

        $message = (new MailMessage)
            ->subject('Code de verification')
            ->line('Utilisez le code suivant pour terminer votre connexion :')
            ->line($this->code);

        if ($minutes) {
            $message->line("Ce code expire dans {$minutes} minutes.");
        }

        $message->line('Si vous n etes pas a l origine de cette demande, ignorez cet email.');

        return $message;
    }
}
