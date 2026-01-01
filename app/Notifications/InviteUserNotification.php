<?php

namespace App\Notifications;

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
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
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
            ? 'client'
            : 'membre d\'equipe';

        $companyName = $this->companyName ?: config('app.name');

        return (new MailMessage)
            ->subject('Votre acces a ' . $companyName)
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
