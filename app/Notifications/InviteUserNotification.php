<?php

namespace App\Notifications;

use App\Support\LocalePreference;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InviteUserNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private string $token;

    private ?string $companyName;

    private ?string $companyLogo;

    private ?string $context;

    public function __construct(
        string $token,
        ?string $companyName = null,
        ?string $companyLogo = null,
        ?string $context = null
    ) {
        $this->token = $token;
        $this->companyName = $companyName;
        $this->companyLogo = $companyLogo;
        $this->context = $context;
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
        $email = method_exists($notifiable, 'getEmailForPasswordReset')
            ? $notifiable->getEmailForPasswordReset()
            : ($notifiable->email ?? null);

        $actionUrl = route('password.reset', [
            'token' => $this->token,
            'email' => $email,
        ]);

        $broker = config('auth.defaults.passwords', 'users');
        $expires = (int) (config("auth.passwords.{$broker}.expire") ?? 60);

        $roleLabel = $this->context === 'client'
            ? LocalePreference::trans('mail.auth.invite.role_client', locale: $locale)
            : LocalePreference::trans('mail.auth.invite.role_team_member', locale: $locale);

        $companyName = $this->companyName ?: config('app.name');

        return (new MailMessage)
            ->subject(LocalePreference::trans('mail.auth.invite.subject', ['company' => $companyName], $locale))
            ->view('emails.auth.invite', [
                'companyName' => $companyName,
                'companyLogo' => $this->companyLogo,
                'actionUrl' => $actionUrl,
                'expires' => $expires,
                'roleLabel' => $roleLabel,
                'recipientName' => $notifiable->name ?? null,
            ]);
    }
}
