<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\LocalePreference;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private User $accountOwner;

    public function __construct(User $accountOwner)
    {
        $this->accountOwner = $accountOwner;
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
        $locale = LocalePreference::forNotifiable($notifiable, $this->accountOwner);
        $companyName = $this->accountOwner->company_name ?: config('app.name');
        $companyLogo = $this->accountOwner->company_logo_url;
        $userName = $this->accountOwner->name ?: $companyName;
        $companyType = $this->accountOwner->company_type === 'products' ? 'products' : 'services';
        $companyTypeLabel = $companyType === 'products' ? 'produits' : 'services';
        $actionUrl = url('/dashboard');

        $quickSteps = $companyType === 'products'
            ? (str_starts_with($locale, 'fr')
                ? ['Ajoutez vos produits et categories', 'Mettez a jour vos stocks', 'Creez vos premiers devis', 'Suivez vos ventes et factures']
                : ['Add your products and categories', 'Update your stock', 'Create your first quotes', 'Track your sales and invoices'])
            : (str_starts_with($locale, 'fr')
                ? ['Ajoutez vos services et categories', 'Creez vos premiers devis', 'Planifiez vos jobs et taches', 'Suivez vos factures et paiements']
                : ['Add your services and categories', 'Create your first quotes', 'Schedule your jobs and tasks', 'Track invoices and payments']);

        $highlights = str_starts_with($locale, 'fr')
            ? ['Devis pro en quelques minutes', 'Suivi clients, jobs, taches et factures', 'Espace client clair et rapide']
            : ['Professional quotes in minutes', 'Customer, job, task, and invoice tracking', 'A clear, fast client portal'];

        return (new MailMessage)
            ->subject(LocalePreference::trans('mail.welcome.subject', ['company' => $companyName], $locale))
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
