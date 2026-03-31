<?php

namespace App\Notifications;

use App\Support\LocalePreference;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordLinkNotification extends ResetPassword
{
    use Queueable;

    public function __construct(
        string $token,
    ) {
        parent::__construct($token);
    }

    public function toMail($notifiable): MailMessage
    {
        $locale = LocalePreference::forNotifiable($notifiable);
        $broker = (string) config('auth.defaults.passwords', 'users');
        $expires = (int) config("auth.passwords.{$broker}.expire", 60);

        return (new MailMessage)
            ->subject(LocalePreference::trans('mail.auth.reset_password.subject', locale: $locale))
            ->view('emails.auth.reset-password', [
                'companyName' => config('app.name'),
                'companyLogo' => null,
                'recipientName' => (string) ($notifiable->name ?? ''),
                'resetUrl' => $this->resetUrl($notifiable),
                'expiresInMinutes' => $expires,
            ]);
    }
}
