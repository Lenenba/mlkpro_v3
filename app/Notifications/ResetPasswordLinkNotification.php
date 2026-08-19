<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\TenantBrandingResolver;
use App\Support\LocalePreference;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordLinkNotification extends ResetPassword
{
    use Queueable;

    public function __construct(
        string $token,
        private readonly ?string $localeOverride = null,
    ) {
        parent::__construct($token);
    }

    public function toMail($notifiable): MailMessage
    {
        $locale = LocalePreference::isSupported($this->localeOverride)
            ? LocalePreference::normalize($this->localeOverride)
            : LocalePreference::forNotifiable($notifiable);
        $broker = (string) config('auth.defaults.passwords', 'users');
        $expires = (int) config("auth.passwords.{$broker}.expire", 60);
        $brandingResolver = app(TenantBrandingResolver::class);
        $usesTenantBranding = $notifiable instanceof User
            && ! $notifiable->isSuperadmin()
            && ! $notifiable->isPlatformAdmin();
        $branding = $usesTenantBranding
            ? $brandingResolver->resolve($notifiable)
            : $brandingResolver->forAccountOwner(null);

        return (new MailMessage)
            ->subject(LocalePreference::trans('mail.auth.reset_password.subject', locale: $locale))
            ->view('emails.auth.reset-password', [
                'companyName' => $branding['name'],
                'companyLogo' => $branding['custom_logo_url'],
                'showPoweredBy' => $usesTenantBranding,
                'recipientName' => (string) ($notifiable->name ?? ''),
                'resetUrl' => route('password.reset', [
                    'token' => $this->token,
                    'email' => method_exists($notifiable, 'getEmailForPasswordReset')
                        ? $notifiable->getEmailForPasswordReset()
                        : (string) ($notifiable->email ?? ''),
                    'locale' => $locale,
                ]),
                'expiresInMinutes' => $expires,
            ]);
    }
}
