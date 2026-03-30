<?php

namespace App\Notifications;

use App\Support\LocalePreference;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordLinkNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $token,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = LocalePreference::forNotifiable($notifiable);
        $broker = (string) config('auth.defaults.passwords', 'users');
        $expires = (int) config("auth.passwords.{$broker}.expire", 60);
        $email = method_exists($notifiable, 'getEmailForPasswordReset')
            ? $notifiable->getEmailForPasswordReset()
            : (string) ($notifiable->email ?? '');

        return (new MailMessage)
            ->subject(LocalePreference::trans('mail.auth.reset_password.subject', locale: $locale))
            ->view('emails.auth.reset-password', [
                'companyName' => config('app.name'),
                'companyLogo' => null,
                'recipientName' => (string) ($notifiable->name ?? ''),
                'resetUrl' => route('password.reset', [
                    'token' => $this->token,
                    'email' => $email,
                ]),
                'expiresInMinutes' => $expires,
            ]);
    }
}
