<?php

namespace App\Services\OfferPackages;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\CampaignInAppNotification;
use App\Support\NotificationDispatcher;
use Illuminate\Support\Carbon;

class CustomerPackageAutomationService
{
    public function __construct(
        private readonly CustomerPackageService $customerPackageService
    ) {}

    public function process(?Carbon $reference = null): array
    {
        $today = ($reference ?: today())->copy()->startOfDay();

        $paidRenewals = $this->reconcilePaidRenewalInvoices();
        $renewalReminders = $this->sendRenewalReminders($today);
        $renewalInvoices = $this->createDueRenewalInvoices($today);
        $suspendedRenewals = $this->suspendOverdueRenewals($today);
        $expired = $this->expireOverduePackages($today);
        $lowBalanceAlerts = $this->sendLowBalanceAlerts($today);
        $expiringSoonReminders = $this->sendExpiringSoonReminders($today);

        return [
            'expired' => $expired,
            'low_balance_alerts' => $lowBalanceAlerts,
            'marketing_reminders' => $expiringSoonReminders,
            'renewal_reminders' => $renewalReminders,
            'renewal_invoices' => $renewalInvoices,
            'paid_renewals' => $paidRenewals,
            'suspended_renewals' => $suspendedRenewals,
        ];
    }

    private function expireOverduePackages(Carbon $today): int
    {
        $count = 0;

        CustomerPackage::query()
            ->active()
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', $today->toDateString())
            ->where(function ($query): void {
                $query->where('is_recurring', false)
                    ->orWhereNull('is_recurring')
                    ->orWhere(function ($subQuery): void {
                        $subQuery->whereNull('recurrence_status')
                            ->orWhereNotIn('recurrence_status', [
                                CustomerPackage::RECURRENCE_PAYMENT_DUE,
                                CustomerPackage::RECURRENCE_SUSPENDED,
                            ]);
                    });
            })
            ->with(['customer:id,user_id,first_name,last_name,company_name,email', 'user:id,name'])
            ->chunkById(100, function ($packages) use (&$count): void {
                foreach ($packages as $package) {
                    $package->forceFill([
                        'status' => CustomerPackage::STATUS_EXPIRED,
                        'metadata' => $this->mergeAutomationMeta($package, [
                            'expired_at' => now('UTC')->toIso8601String(),
                        ]),
                    ])->save();

                    $this->recordCustomerActivity($package, 'customer_package_expired', 'Customer forfait expired');
                    $this->notifyOwner($package, 'Forfait expired', $this->packageLabel($package).' has expired.');
                    $count++;
                }
            });

        return $count;
    }

    private function reconcilePaidRenewalInvoices(): int
    {
        $count = 0;

        CustomerPackage::query()
            ->active()
            ->recurring()
            ->whereIn('recurrence_status', [
                CustomerPackage::RECURRENCE_PAYMENT_DUE,
                CustomerPackage::RECURRENCE_SUSPENDED,
            ])
            ->with([
                'customer:id,user_id,first_name,last_name,company_name,email',
                'user:id,name',
                'offerPackage.items',
            ])
            ->chunkById(100, function ($packages) use (&$count): void {
                foreach ($packages as $package) {
                    if ((int) data_get($package->metadata, 'recurrence.paid_renewed_to_customer_package_id', 0) > 0) {
                        continue;
                    }

                    $invoiceId = (int) data_get($package->metadata, 'recurrence.pending_invoice_id', 0);
                    if ($invoiceId < 1) {
                        continue;
                    }

                    $invoice = Invoice::query()
                        ->whereKey($invoiceId)
                        ->where('user_id', $package->user_id)
                        ->where('status', 'paid')
                        ->with('items')
                        ->first();
                    if (! $invoice) {
                        continue;
                    }

                    $owner = $package->user instanceof User
                        ? $package->user
                        : User::query()->find($package->user_id);

                    $renewed = $this->customerPackageService->renewFromPaidInvoice($invoice, $owner);
                    if ($renewed) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function sendLowBalanceAlerts(Carbon $today): int
    {
        $count = 0;

        CustomerPackage::query()
            ->active()
            ->where('remaining_quantity', '>', 0)
            ->with(['customer:id,user_id,first_name,last_name,company_name,email', 'user:id,name'])
            ->chunkById(100, function ($packages) use (&$count): void {
                foreach ($packages as $package) {
                    if (! $this->isLowBalance($package) || $this->hasAutomationNotification($package, 'low_balance_sent_at')) {
                        continue;
                    }

                    $package->forceFill([
                        'metadata' => $this->mergeAutomationNotification($package, 'low_balance_sent_at', [
                            'low_balance_threshold' => $this->lowBalanceThreshold($package),
                        ]),
                    ])->save();

                    $this->recordCustomerActivity($package, 'customer_package_low_balance', 'Customer forfait low balance');
                    $this->notifyOwner(
                        $package,
                        'Forfait balance is low',
                        $this->packageLabel($package).' has '.$package->remaining_quantity.' '.$package->unit_type.' remaining.'
                    );
                    $count++;
                }
            });

        return $count;
    }

    private function sendExpiringSoonReminders(Carbon $today): int
    {
        $count = 0;
        $limit = $today->copy()->addDays(7);

        CustomerPackage::query()
            ->active()
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '>=', $today->toDateString())
            ->whereDate('expires_at', '<=', $limit->toDateString())
            ->with(['customer:id,user_id,first_name,last_name,company_name,email', 'user:id,name'])
            ->chunkById(100, function ($packages) use (&$count): void {
                foreach ($packages as $package) {
                    if ($this->hasAutomationNotification($package, 'expiring_soon_sent_at')) {
                        continue;
                    }

                    $package->forceFill([
                        'metadata' => $this->mergeAutomationNotification($package, 'expiring_soon_sent_at', [
                            'expires_at' => $package->expires_at?->toDateString(),
                        ]),
                    ])->save();

                    $this->recordCustomerActivity($package, 'customer_package_expiring_soon', 'Customer forfait expiring soon');
                    $this->notifyOwner(
                        $package,
                        'Forfait expiring soon',
                        $this->packageLabel($package).' expires on '.$package->expires_at?->toDateString().'.'
                    );
                    $count++;
                }
            });

        return $count;
    }

    private function sendRenewalReminders(Carbon $today): int
    {
        $count = 0;
        $limit = $today->copy()->addDays(7);

        CustomerPackage::query()
            ->active()
            ->recurring()
            ->where('recurrence_status', CustomerPackage::RECURRENCE_ACTIVE)
            ->whereNotNull('next_renewal_at')
            ->whereDate('next_renewal_at', '>=', $today->toDateString())
            ->whereDate('next_renewal_at', '<=', $limit->toDateString())
            ->with(['customer:id,user_id,first_name,last_name,company_name,email', 'user:id,name'])
            ->chunkById(100, function ($packages) use (&$count): void {
                foreach ($packages as $package) {
                    if ($this->hasAutomationNotification($package, 'renewal_due_sent_at')) {
                        continue;
                    }

                    $package->forceFill([
                        'metadata' => $this->mergeAutomationNotification($package, 'renewal_due_sent_at', [
                            'next_renewal_at' => $package->next_renewal_at?->toDateString(),
                            'recurrence_frequency' => $package->recurrence_frequency,
                        ]),
                    ])->save();

                    $this->recordCustomerActivity($package, 'customer_package_renewal_due', 'Recurring forfait renewal due');
                    $this->notifyOwner(
                        $package,
                        'Recurring forfait renewal due',
                        $this->packageLabel($package).' renews on '.$package->next_renewal_at?->toDateString().'.'
                    );
                    $count++;
                }
            });

        return $count;
    }

    private function createDueRenewalInvoices(Carbon $today): int
    {
        $count = 0;

        CustomerPackage::query()
            ->active()
            ->recurring()
            ->whereNotNull('next_renewal_at')
            ->whereDate('next_renewal_at', '<=', $today->toDateString())
            ->where(function ($query): void {
                $query->where('recurrence_status', CustomerPackage::RECURRENCE_ACTIVE)
                    ->orWhereNull('recurrence_status');
            })
            ->with([
                'customer:id,user_id,first_name,last_name,company_name,email',
                'user:id,name',
                'offerPackage.items',
            ])
            ->chunkById(100, function ($packages) use (&$count): void {
                foreach ($packages as $package) {
                    $owner = $package->user instanceof User
                        ? $package->user
                        : User::query()->find($package->user_id);

                    if (! $owner || ! $package->customer instanceof Customer) {
                        continue;
                    }

                    if ((int) data_get($package->metadata, 'recurrence.pending_invoice_id', 0) > 0) {
                        continue;
                    }

                    $this->customerPackageService->createRenewalInvoice($owner, $package->customer, $package);
                    $count++;
                }
            });

        return $count;
    }

    private function suspendOverdueRenewals(Carbon $today): int
    {
        $count = 0;

        CustomerPackage::query()
            ->active()
            ->recurring()
            ->where('recurrence_status', CustomerPackage::RECURRENCE_PAYMENT_DUE)
            ->whereNotNull('next_renewal_at')
            ->whereDate('next_renewal_at', '<', $today->toDateString())
            ->with(['customer:id,user_id,first_name,last_name,company_name,email', 'user:id,name'])
            ->chunkById(100, function ($packages) use (&$count, $today): void {
                foreach ($packages as $package) {
                    $graceDays = $this->renewalPaymentGraceDays($package);
                    if ($package->next_renewal_at?->copy()->addDays($graceDays)->gt($today)) {
                        continue;
                    }

                    $metadata = (array) ($package->metadata ?? []);
                    $metadata['recurrence'] = array_merge((array) ($metadata['recurrence'] ?? []), [
                        'suspended_at' => now('UTC')->toIso8601String(),
                        'suspension_reason' => 'renewal_payment_overdue',
                        'payment_grace_days' => $graceDays,
                    ]);

                    $package->forceFill([
                        'recurrence_status' => CustomerPackage::RECURRENCE_SUSPENDED,
                        'metadata' => $this->mergeAutomationMeta($package, [
                            'last_checked_at' => now('UTC')->toIso8601String(),
                            'renewal_suspended_at' => now('UTC')->toIso8601String(),
                        ], $metadata),
                    ])->save();

                    $this->recordCustomerActivity($package, 'customer_package_renewal_suspended', 'Recurring forfait suspended for overdue payment');
                    $this->notifyOwner(
                        $package,
                        'Recurring forfait suspended',
                        $this->packageLabel($package).' is suspended because the renewal payment is overdue.'
                    );
                    $count++;
                }
            });

        return $count;
    }

    private function isLowBalance(CustomerPackage $package): bool
    {
        return (int) $package->remaining_quantity <= $this->lowBalanceThreshold($package);
    }

    private function lowBalanceThreshold(CustomerPackage $package): int
    {
        return max(1, (int) ceil(max(1, (int) $package->initial_quantity) * 0.2));
    }

    private function renewalPaymentGraceDays(CustomerPackage $package): int
    {
        return max(1, (int) data_get($package->metadata, 'recurrence.payment_grace_days', 7));
    }

    private function hasAutomationNotification(CustomerPackage $package, string $key): bool
    {
        return filled(data_get($package->metadata, 'automation.notifications.'.$key));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mergeAutomationNotification(CustomerPackage $package, string $key, array $payload = []): array
    {
        $metadata = $this->mergeAutomationMeta($package, [
            'last_checked_at' => now('UTC')->toIso8601String(),
        ]);

        $notifications = (array) data_get($metadata, 'automation.notifications', []);
        $notifications[$key] = array_merge([
            'sent_at' => now('UTC')->toIso8601String(),
        ], $payload);
        data_set($metadata, 'automation.notifications', $notifications);

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mergeAutomationMeta(CustomerPackage $package, array $payload, ?array $metadata = null): array
    {
        $metadata = $metadata ?? (array) ($package->metadata ?? []);
        $automation = (array) ($metadata['automation'] ?? []);
        $metadata['automation'] = array_merge($automation, $payload);

        return $metadata;
    }

    private function recordCustomerActivity(CustomerPackage $package, string $action, string $description): void
    {
        $customer = $package->customer;
        if (! $customer instanceof Customer) {
            return;
        }

        ActivityLog::record(null, $customer, $action, [
            'customer_package_id' => $package->id,
            'offer_package_id' => $package->offer_package_id,
            'remaining_quantity' => (int) $package->remaining_quantity,
            'expires_at' => $package->expires_at?->toDateString(),
            'marketing_relaunch_candidate' => true,
        ], $description);
    }

    private function notifyOwner(CustomerPackage $package, string $title, string $message): void
    {
        $owner = $package->user instanceof User
            ? $package->user
            : User::query()->find($package->user_id);

        if (! $owner) {
            return;
        }

        NotificationDispatcher::send($owner, new CampaignInAppNotification([
            'title' => $title,
            'message' => $message,
            'action_url' => route('customer.show', $package->customer_id),
            'customer_package_id' => $package->id,
        ]), [
            'customer_package_id' => $package->id,
        ]);
    }

    private function packageLabel(CustomerPackage $package): string
    {
        return (string) (
            data_get($package->source_details, 'offer_package.name')
            ?: $package->offerPackage?->name
            ?: 'Forfait #'.$package->id
        );
    }
}
