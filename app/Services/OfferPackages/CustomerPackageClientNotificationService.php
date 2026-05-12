<?php

namespace App\Services\OfferPackages;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Customer;
use App\Models\CustomerOptOut;
use App\Models\CustomerPackage;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\ActionEmailNotification;
use App\Notifications\CustomerPackageBillingNotification;
use App\Services\CompanyNotificationPreferenceService;
use App\Services\NotificationPreferenceService;
use App\Support\LocalePreference;
use App\Support\NotificationDispatcher;
use Illuminate\Support\Facades\Notification;

class CustomerPackageClientNotificationService
{
    public function notifyPaymentDue(CustomerPackage $package, Invoice $invoice, int $daysOverdue): bool
    {
        return $this->notify($package, $invoice, 'payment_due', $daysOverdue);
    }

    public function notifySuspended(CustomerPackage $package, ?Invoice $invoice = null): bool
    {
        return $this->notify($package, $invoice, 'suspended');
    }

    public function notifyResumed(CustomerPackage $package, CustomerPackage $renewed, Invoice $invoice): bool
    {
        return $this->notify($package, $invoice, 'resumed', renewed: $renewed);
    }

    private function notify(
        CustomerPackage $package,
        ?Invoice $invoice,
        string $event,
        int $daysOverdue = 0,
        ?CustomerPackage $renewed = null
    ): bool {
        $package->loadMissing(['customer.portalUser', 'customer.user', 'offerPackage']);
        $customer = $package->customer;
        if (! $customer instanceof Customer) {
            return false;
        }

        $owner = $customer->user instanceof User
            ? $customer->user
            : User::query()->find((int) $package->user_id);
        if (! $owner instanceof User) {
            return false;
        }

        $invoice?->loadMissing('customer');
        $locale = LocalePreference::forCustomer($customer, $owner);
        $copy = $this->copy($event, $locale, $package, $invoice, $daysOverdue, $renewed);
        $details = $this->details($locale, $package, $invoice, $daysOverdue, $renewed);
        $actionUrl = $invoice
            ? route('public.invoices.show', $invoice->id)
            : route('portal.packages.index');
        $portalActionUrl = $invoice && $customer->portal_user_id
            ? route('portal.invoices.show', $invoice->id)
            : route('portal.packages.index');

        $sent = false;
        if ($this->shouldEmailCustomer($owner, $customer)) {
            $sent = NotificationDispatcher::send($customer, new ActionEmailNotification(
                $copy['title'],
                $copy['intro'],
                $details,
                $actionUrl,
                $copy['action_label'],
                $copy['subject'],
                $copy['note']
            ), [
                'customer_package_id' => $package->id,
                'invoice_id' => $invoice?->id,
                'event' => $event,
            ]) || $sent;
        }

        $portalUser = $customer->portalUser;
        if ($portalUser instanceof User && $this->shouldNotifyPortalUser($portalUser)) {
            Notification::send($portalUser, new CustomerPackageBillingNotification(
                $copy['title'],
                $copy['intro'],
                $portalActionUrl,
                [
                    'customer_package_id' => $package->id,
                    'invoice_id' => $invoice?->id,
                    'renewed_customer_package_id' => $renewed?->id,
                    'event' => 'customer_package_'.$event,
                ]
            ));
            $sent = true;
        }

        return $sent;
    }

    private function shouldEmailCustomer(User $owner, Customer $customer): bool
    {
        if (! filled($customer->email)) {
            return false;
        }

        $channels = app(CompanyNotificationPreferenceService::class)
            ->alertChannels($owner, CompanyNotificationPreferenceService::CATEGORY_BILLING);
        if (! (bool) ($channels[CompanyNotificationPreferenceService::CHANNEL_EMAIL] ?? true)) {
            return false;
        }

        $hash = CampaignRecipient::destinationHash(strtolower(trim((string) $customer->email)));
        if (! $hash) {
            return false;
        }

        return ! CustomerOptOut::query()
            ->where('user_id', $owner->id)
            ->where('channel', Campaign::CHANNEL_EMAIL)
            ->where('destination_hash', $hash)
            ->exists();
    }

    private function shouldNotifyPortalUser(User $portalUser): bool
    {
        return app(NotificationPreferenceService::class)
            ->shouldNotify($portalUser, NotificationPreferenceService::CATEGORY_BILLING);
    }

    /**
     * @return array{title: string, intro: string, action_label: string, subject: string, note: string|null}
     */
    private function copy(
        string $event,
        string $locale,
        CustomerPackage $package,
        ?Invoice $invoice,
        int $daysOverdue,
        ?CustomerPackage $renewed
    ): array {
        $isFr = str_starts_with($locale, 'fr');
        $packageName = $this->packageLabel($package);
        $invoiceNumber = $invoice?->number ?: $invoice?->id;

        if ($event === 'suspended') {
            return [
                'title' => $isFr ? 'Forfait suspendu' : 'Forfait suspended',
                'intro' => $isFr
                    ? "Votre forfait {$packageName} est suspendu parce que la facture de renouvellement est impayee."
                    : "Your {$packageName} forfait is suspended because the renewal invoice is unpaid.",
                'action_label' => $isFr ? 'Payer la facture' : 'Pay invoice',
                'subject' => $isFr ? 'Votre forfait est suspendu' : 'Your forfait is suspended',
                'note' => $isFr
                    ? 'Le forfait reprendra automatiquement apres paiement de la facture de renouvellement.'
                    : 'The forfait will resume automatically after the renewal invoice is paid.',
            ];
        }

        if ($event === 'resumed') {
            return [
                'title' => $isFr ? 'Forfait repris' : 'Forfait resumed',
                'intro' => $isFr
                    ? "Votre forfait {$packageName} est repris apres paiement de la facture {$invoiceNumber}."
                    : "Your {$packageName} forfait has resumed after invoice {$invoiceNumber} was paid.",
                'action_label' => $isFr ? 'Voir le forfait' : 'View forfait',
                'subject' => $isFr ? 'Votre forfait est repris' : 'Your forfait has resumed',
                'note' => $renewed
                    ? ($isFr
                        ? 'La nouvelle periode est active.'
                        : 'The new period is active.')
                    : null,
            ];
        }

        return [
            'title' => $isFr ? 'Facture de renouvellement impayee' : 'Renewal invoice unpaid',
            'intro' => $daysOverdue > 0
                ? ($isFr
                    ? "La facture de renouvellement {$invoiceNumber} de votre forfait {$packageName} est impayee depuis {$daysOverdue} jour(s)."
                    : "Renewal invoice {$invoiceNumber} for your {$packageName} forfait has been unpaid for {$daysOverdue} day(s).")
                : ($isFr
                    ? "La facture de renouvellement {$invoiceNumber} de votre forfait {$packageName} est disponible."
                    : "Renewal invoice {$invoiceNumber} for your {$packageName} forfait is available."),
            'action_label' => $isFr ? 'Payer la facture' : 'Pay invoice',
            'subject' => $isFr ? 'Facture de renouvellement a payer' : 'Renewal invoice to pay',
            'note' => $isFr
                ? 'Le forfait peut etre suspendu si le paiement reste en retard.'
                : 'The forfait may be suspended if the payment remains overdue.',
        ];
    }

    private function details(
        string $locale,
        CustomerPackage $package,
        ?Invoice $invoice,
        int $daysOverdue,
        ?CustomerPackage $renewed
    ): array {
        $isFr = str_starts_with($locale, 'fr');
        $details = [
            ['label' => $isFr ? 'Forfait' : 'Forfait', 'value' => $this->packageLabel($package)],
        ];

        if ($invoice) {
            $details[] = ['label' => $isFr ? 'Facture' : 'Invoice', 'value' => $invoice->number ?? $invoice->id];
            $details[] = ['label' => 'Total', 'value' => $this->money((float) $invoice->total, $invoice->currency_code)];
            $details[] = ['label' => $isFr ? 'Solde du' : 'Balance due', 'value' => $this->money((float) $invoice->balance_due, $invoice->currency_code)];
        }

        if ($package->next_renewal_at) {
            $details[] = ['label' => $isFr ? 'Renouvellement' : 'Renewal', 'value' => $package->next_renewal_at->toDateString()];
        }

        if ($daysOverdue > 0) {
            $details[] = ['label' => $isFr ? 'Retard' : 'Overdue', 'value' => $daysOverdue.' day(s)'];
        }

        if ($renewed) {
            $details[] = ['label' => $isFr ? 'Nouvelle periode' : 'New period', 'value' => $renewed->starts_at?->toDateString()];
        }

        return $details;
    }

    private function packageLabel(CustomerPackage $package): string
    {
        return (string) (
            data_get($package->source_details, 'offer_package.name')
            ?: $package->offerPackage?->name
            ?: 'Forfait #'.$package->id
        );
    }

    private function money(float $amount, ?string $currency): string
    {
        return strtoupper((string) ($currency ?: 'CAD')).' '.number_format($amount, 2);
    }
}
