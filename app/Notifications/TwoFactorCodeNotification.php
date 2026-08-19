<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\TenantBrandingResolver;
use App\Support\LocalePreference;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class TwoFactorCodeNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $code, private readonly ?Carbon $expiresAt) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = LocalePreference::forNotifiable($notifiable);
        $minutes = $this->expiresAt
            ? max(1, (int) ceil(now()->diffInSeconds($this->expiresAt, true) / 60))
            : null;
        $brandingResolver = app(TenantBrandingResolver::class);
        $usesTenantBranding = $notifiable instanceof User
            && ! $notifiable->isSuperadmin()
            && ! $notifiable->isPlatformAdmin();
        $branding = $usesTenantBranding
            ? $brandingResolver->resolve($notifiable)
            : $brandingResolver->forAccountOwner(null);

        return (new MailMessage)
            ->subject(LocalePreference::trans('mail.auth.two_factor.subject', locale: $locale))
            ->view('emails.auth.two-factor-code', [
                'companyName' => $branding['name'],
                'companyLogo' => $branding['custom_logo_url'],
                'companyPrimaryColor' => $usesTenantBranding ? $branding['primary_color'] : null,
                'companyPrimaryForegroundColor' => $usesTenantBranding ? $branding['primary_foreground_color'] : null,
                'showPoweredBy' => $usesTenantBranding,
                'recipientName' => (string) ($notifiable->name ?? ''),
                'code' => $this->code,
                'expiresInMinutes' => $minutes,
            ]);
    }
}
