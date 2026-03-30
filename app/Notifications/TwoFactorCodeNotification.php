<?php

namespace App\Notifications;

use App\Support\LocalePreference;
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
        $locale = LocalePreference::forNotifiable($notifiable);
        $minutes = $this->expiresAt
            ? max(1, (int) ceil(now()->diffInSeconds($this->expiresAt) / 60))
            : null;

        return (new MailMessage)
            ->subject(LocalePreference::trans('mail.auth.two_factor.subject', locale: $locale))
            ->view('emails.auth.two-factor-code', [
                'companyName' => config('app.name'),
                'companyLogo' => null,
                'recipientName' => (string) ($notifiable->name ?? ''),
                'code' => $this->code,
                'expiresInMinutes' => $minutes,
            ]);
    }
}
