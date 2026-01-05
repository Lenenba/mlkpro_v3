<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeEmailNotification extends Notification
{
    use Queueable;

    private User $accountOwner;

    public function __construct(User $accountOwner)
    {
        $this->accountOwner = $accountOwner;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $companyName = $this->accountOwner->company_name ?: config('app.name');
        $companyLogo = $this->accountOwner->company_logo_url;
        $userName = $this->accountOwner->name ?: $companyName;
        $companyType = $this->accountOwner->company_type === 'products' ? 'products' : 'services';
        $companyTypeLabel = $companyType === 'products' ? 'produits' : 'services';
        $actionUrl = url('/dashboard');

        $quickSteps = $companyType === 'products'
            ? [
                'Ajoutez vos produits et categories',
                'Mettez a jour vos stocks',
                'Creez vos premiers devis',
                'Suivez vos ventes et factures',
            ]
            : [
                'Ajoutez vos services et categories',
                'Creez vos premiers devis',
                'Planifiez vos jobs et taches',
                'Suivez vos factures et paiements',
            ];

        $highlights = [
            'Devis pro en quelques minutes',
            'Suivi clients, jobs, taches et factures',
            'Espace client clair et rapide',
        ];

        return (new MailMessage())
            ->subject('Bienvenue' . $companyName)
            ->view('emails.onboarding.welcome', [
                'companyName' => $companyName,
                'companyLogo' => $companyLogo,
                'userName' => $userName,
                'companyTypeLabel' => $companyTypeLabel,
                'actionUrl' => $actionUrl,
                'quickSteps' => $quickSteps,
                'highlights' => $highlights,
                'supportEmail' => config('mail.from.address'),
            ]);
    }
}
