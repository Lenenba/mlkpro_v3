<?php

namespace App\Services\OfferPackages;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\CampaignInAppNotification;
use App\Services\StripeInvoiceService;
use App\Support\NotificationDispatcher;
use Illuminate\Support\Carbon;

class CustomerPackageAutomationService
{
    public function __construct(
        private readonly CustomerPackageService $customerPackageService,
        private readonly StripeInvoiceService $stripeInvoiceService,
        private readonly CustomerPackageClientNotificationService $clientNotifications,
        private readonly CustomerPackageMarketingEventService $marketingEvents
    ) {}

    public function process(?Carbon $reference = null): array
    {
        $today = ($reference ?: today())->copy()->startOfDay();

        $paidRenewals = $this->reconcilePaidRenewalInvoices();
        $renewalReminders = $this->sendRenewalReminders($today);
        $renewalInvoices = $this->createDueRenewalInvoices($today);
        $stripePaymentSummary = $this->attemptAutomaticRenewalPayments($today);
        $clientPaymentReminders = $this->sendPaymentDueClientReminders($today);
        $suspendedRenewals = $this->suspendOverdueRenewals($today);
        $clientSuspensionNotices = $this->sendSuspensionClientNotices($today);
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
            'stripe_payment_attempts' => $stripePaymentSummary['attempts'],
            'stripe_payment_successes' => $stripePaymentSummary['successes'],
            'stripe_payment_fallbacks' => $stripePaymentSummary['fallbacks'],
            'client_payment_reminders' => $clientPaymentReminders,
            'client_suspension_notices' => $clientSuspensionNotices,
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

    /**
     * @return array{attempts: int, successes: int, fallbacks: int}
     */
    private function attemptAutomaticRenewalPayments(Carbon $today): array
    {
        $summary = [
            'attempts' => 0,
            'successes' => 0,
            'fallbacks' => 0,
        ];

        CustomerPackage::query()
            ->active()
            ->recurring()
            ->where('recurrence_status', CustomerPackage::RECURRENCE_PAYMENT_DUE)
            ->whereNotNull('next_renewal_at')
            ->whereDate('next_renewal_at', '<=', $today->toDateString())
            ->with([
                'customer.portalUser:id,stripe_customer_id',
                'customer:id,user_id,portal_user_id,first_name,last_name,company_name,email,stripe_customer_id,stripe_default_payment_method_id',
                'user:id,name',
                'offerPackage.items',
            ])
            ->chunkById(100, function ($packages) use (&$summary, $today): void {
                foreach ($packages as $package) {
                    $invoice = $this->pendingRenewalInvoiceForAutomation($package);
                    if (! $invoice || $this->autoPaymentAlreadyHandledForInvoice($package, $invoice, today: $today)) {
                        continue;
                    }

                    $result = $this->stripeInvoiceService->attemptAutomaticPayment($invoice, $package);
                    $this->recordAutomaticPaymentResult($package, $invoice, $result);

                    if ((bool) ($result['attempted'] ?? false)) {
                        $summary['attempts']++;
                    }

                    if (($result['status'] ?? null) === 'succeeded') {
                        $summary['successes']++;
                    } else {
                        $summary['fallbacks']++;
                    }
                }
            });

        return $summary;
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

    private function sendPaymentDueClientReminders(Carbon $today): int
    {
        $count = 0;

        CustomerPackage::query()
            ->active()
            ->recurring()
            ->where('recurrence_status', CustomerPackage::RECURRENCE_PAYMENT_DUE)
            ->whereNotNull('next_renewal_at')
            ->whereDate('next_renewal_at', '<=', $today->toDateString())
            ->with([
                'customer.portalUser:id,role_id,locale,notification_settings',
                'customer.user:id,locale,company_name,company_logo,company_notification_settings',
                'customer:id,user_id,portal_user_id,portal_access,first_name,last_name,company_name,email',
                'user:id,name',
                'offerPackage:id,name,metadata',
            ])
            ->chunkById(100, function ($packages) use (&$count, $today): void {
                foreach ($packages as $package) {
                    $invoice = $this->pendingRenewalInvoiceForAutomation($package);
                    if (! $invoice) {
                        continue;
                    }

                    $daysOverdue = max(0, (int) $package->next_renewal_at?->copy()->startOfDay()->diffInDays($today, false));
                    $delay = $this->duePaymentReminderDelay($package, $invoice, $daysOverdue);
                    if ($delay === null) {
                        continue;
                    }

                    if (! $this->clientNotifications->notifyPaymentDue($package, $invoice, $daysOverdue)) {
                        continue;
                    }

                    $package->forceFill([
                        'metadata' => $this->mergeClientNotificationMeta(
                            $package,
                            'client_payment_due_reminders',
                            $invoice,
                            'day_'.$delay,
                            [
                                'days_overdue' => $daysOverdue,
                                'configured_delay' => $delay,
                            ]
                        ),
                    ])->save();

                    $this->recordCustomerActivity($package, 'customer_package_client_payment_reminder_sent', 'Recurring forfait payment reminder sent to client', [
                        'invoice_id' => $invoice->id,
                        'days_overdue' => $daysOverdue,
                        'configured_delay' => $delay,
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    private function sendSuspensionClientNotices(Carbon $today): int
    {
        $count = 0;

        CustomerPackage::query()
            ->active()
            ->recurring()
            ->where('recurrence_status', CustomerPackage::RECURRENCE_SUSPENDED)
            ->with([
                'customer.portalUser:id,role_id,locale,notification_settings',
                'customer.user:id,locale,company_name,company_logo,company_notification_settings',
                'customer:id,user_id,portal_user_id,portal_access,first_name,last_name,company_name,email',
                'user:id,name',
                'offerPackage:id,name,metadata',
            ])
            ->chunkById(100, function ($packages) use (&$count, $today): void {
                foreach ($packages as $package) {
                    $invoice = $this->pendingRenewalInvoiceForAutomation($package);
                    if ($this->hasClientNotification($package, 'client_suspension_notices', $invoice, 'suspended')) {
                        continue;
                    }

                    if (! $this->clientNotifications->notifySuspended($package, $invoice)) {
                        continue;
                    }

                    $package->forceFill([
                        'metadata' => $this->mergeClientNotificationMeta(
                            $package,
                            'client_suspension_notices',
                            $invoice,
                            'suspended',
                            [
                                'checked_at' => $today->toDateString(),
                            ]
                        ),
                    ])->save();

                    $this->recordCustomerActivity($package, 'customer_package_client_suspension_notice_sent', 'Recurring forfait suspension notice sent to client', [
                        'invoice_id' => $invoice?->id,
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    private function pendingRenewalInvoiceForAutomation(CustomerPackage $package): ?Invoice
    {
        $invoiceId = (int) data_get($package->metadata, 'recurrence.pending_invoice_id', 0);
        if ($invoiceId < 1) {
            return null;
        }

        return Invoice::query()
            ->whereKey($invoiceId)
            ->where('user_id', $package->user_id)
            ->whereNotIn('status', ['paid', 'void', 'draft'])
            ->with(['customer', 'items', 'user'])
            ->first();
    }

    private function autoPaymentAlreadyHandledForInvoice(CustomerPackage $package, Invoice $invoice, Carbon $today): bool
    {
        $lastInvoiceId = (int) data_get($package->metadata, 'recurrence.auto_payment.last_invoice_id', 0);
        if ($lastInvoiceId !== (int) $invoice->id) {
            return false;
        }

        $lastStatus = (string) data_get($package->metadata, 'recurrence.auto_payment.last_status', '');
        if (in_array($lastStatus, ['succeeded', 'failed'], true)) {
            return true;
        }

        if ($lastStatus !== 'skipped') {
            return false;
        }

        $lastAttemptedAt = data_get($package->metadata, 'recurrence.auto_payment.last_attempted_at');
        if (! $lastAttemptedAt) {
            return false;
        }

        try {
            return Carbon::parse($lastAttemptedAt)->startOfDay()->gte($today->copy()->startOfDay());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function recordAutomaticPaymentResult(CustomerPackage $package, Invoice $invoice, array $result): void
    {
        $freshPackage = CustomerPackage::query()
            ->whereKey($package->id)
            ->with(['customer:id,user_id,first_name,last_name,company_name,email', 'user:id,name'])
            ->first();
        if (! $freshPackage) {
            return;
        }

        $status = (string) ($result['status'] ?? 'skipped');
        $attempt = array_filter([
            'invoice_id' => $invoice->id,
            'status' => $status,
            'attempted' => (bool) ($result['attempted'] ?? false),
            'payment_intent_id' => $result['payment_intent_id'] ?? null,
            'payment_id' => $result['payment_id'] ?? null,
            'stripe_customer_id' => $result['stripe_customer_id'] ?? null,
            'stripe_payment_method_id' => $result['stripe_payment_method_id'] ?? null,
            'reason' => $result['reason'] ?? null,
            'decline_code' => $result['decline_code'] ?? null,
            'message' => $result['message'] ?? null,
            'attempted_at' => now('UTC')->toIso8601String(),
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $metadata = (array) ($freshPackage->metadata ?? []);
        $recurrence = (array) ($metadata['recurrence'] ?? []);
        $autoPayment = (array) ($recurrence['auto_payment'] ?? []);
        $attempts = (array) ($autoPayment['attempts'] ?? []);
        $attempts[] = $attempt;
        $attempts = array_slice($attempts, -5);

        $autoPayment = array_merge($autoPayment, [
            'last_invoice_id' => $invoice->id,
            'last_status' => $status,
            'last_attempted_at' => $attempt['attempted_at'],
            'last_reason' => $attempt['reason'] ?? null,
            'last_message' => $attempt['message'] ?? null,
            'last_payment_intent_id' => $attempt['payment_intent_id'] ?? null,
            'last_payment_id' => $attempt['payment_id'] ?? null,
            'attempts' => $attempts,
        ]);

        $recurrence['auto_payment'] = array_filter($autoPayment, fn (mixed $value): bool => $value !== null && $value !== '');
        $recurrence['pending_invoice_status'] = $invoice->fresh()?->status ?? $invoice->status;
        $metadata['recurrence'] = $recurrence;

        $freshPackage->forceFill([
            'metadata' => $this->mergeAutomationMeta($freshPackage, [
                'last_checked_at' => now('UTC')->toIso8601String(),
                'last_stripe_auto_payment_status' => $status,
            ], $metadata),
        ])->save();

        $action = match ($status) {
            'succeeded' => 'customer_package_renewal_auto_payment_succeeded',
            'failed' => 'customer_package_renewal_auto_payment_failed',
            default => 'customer_package_renewal_auto_payment_skipped',
        };

        $this->recordCustomerActivity(
            $freshPackage,
            $action,
            match ($status) {
                'succeeded' => 'Recurring forfait renewal paid automatically via Stripe',
                'failed' => 'Recurring forfait automatic Stripe renewal payment failed',
                default => 'Recurring forfait automatic Stripe renewal payment skipped',
            },
            [
                'invoice_id' => $invoice->id,
                'payment_intent_id' => $attempt['payment_intent_id'] ?? null,
                'reason' => $attempt['reason'] ?? null,
                'message' => $attempt['message'] ?? null,
            ]
        );
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
        return max(1, (int) (
            data_get($package->metadata, 'recurrence.payment_grace_days')
            ?? data_get($package->source_details, 'recurrence.payment_grace_days')
            ?? data_get($package->offerPackage?->metadata, 'recurrence.payment_grace_days')
            ?? 7
        ));
    }

    private function duePaymentReminderDelay(CustomerPackage $package, Invoice $invoice, int $daysOverdue): ?int
    {
        $eligible = array_values(array_filter(
            $this->paymentReminderDays($package),
            fn (int $delay): bool => $delay <= $daysOverdue
        ));
        if ($eligible === []) {
            return null;
        }

        $delay = max($eligible);

        return $this->hasClientNotification($package, 'client_payment_due_reminders', $invoice, 'day_'.$delay)
            ? null
            : $delay;
    }

    /**
     * @return array<int, int>
     */
    private function paymentReminderDays(CustomerPackage $package): array
    {
        $value = data_get($package->metadata, 'recurrence.payment_reminder_days')
            ?? data_get($package->source_details, 'recurrence.payment_reminder_days')
            ?? data_get($package->offerPackage?->metadata, 'recurrence.payment_reminder_days')
            ?? [0, 3, 6];

        if (is_string($value)) {
            $value = preg_split('/[,\s]+/', $value) ?: [];
        }

        if (! is_array($value)) {
            $value = [$value];
        }

        $days = array_values(array_unique(array_filter(array_map(
            fn (mixed $day): int => max(0, (int) $day),
            $value
        ), fn (int $day): bool => $day <= 365)));
        sort($days);

        return $days !== [] ? $days : [0, 3, 6];
    }

    private function hasClientNotification(CustomerPackage $package, string $type, ?Invoice $invoice, string $key): bool
    {
        $invoiceKey = 'invoice_'.($invoice?->id ?? 0);

        return filled(data_get($package->metadata, 'automation.notifications.'.$type.'.'.$invoiceKey.'.'.$key.'.sent_at'));
    }

    private function mergeClientNotificationMeta(
        CustomerPackage $package,
        string $type,
        ?Invoice $invoice,
        string $key,
        array $payload = []
    ): array {
        $metadata = $this->mergeAutomationMeta($package, [
            'last_checked_at' => now('UTC')->toIso8601String(),
        ]);
        $invoiceKey = 'invoice_'.($invoice?->id ?? 0);

        data_set(
            $metadata,
            'automation.notifications.'.$type.'.'.$invoiceKey.'.'.$key,
            array_merge([
                'sent_at' => now('UTC')->toIso8601String(),
                'invoice_id' => $invoice?->id,
            ], $payload)
        );

        return $metadata;
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

    private function recordCustomerActivity(CustomerPackage $package, string $action, string $description, array $extra = []): void
    {
        $customer = $package->customer;
        if (! $customer instanceof Customer) {
            return;
        }

        ActivityLog::record(null, $customer, $action, array_filter(array_merge([
            'customer_package_id' => $package->id,
            'offer_package_id' => $package->offer_package_id,
            'remaining_quantity' => (int) $package->remaining_quantity,
            'expires_at' => $package->expires_at?->toDateString(),
            'marketing_relaunch_candidate' => true,
        ], $extra), fn (mixed $value): bool => $value !== null && $value !== ''), $description);

        $eventType = match ($action) {
            'customer_package_expired' => CustomerPackageMarketingEventService::EVENT_EXPIRED,
            'customer_package_low_balance' => CustomerPackageMarketingEventService::EVENT_LOW_BALANCE,
            'customer_package_expiring_soon' => CustomerPackageMarketingEventService::EVENT_EXPIRING_SOON,
            'customer_package_renewal_suspended' => CustomerPackageMarketingEventService::EVENT_SUSPENDED,
            default => null,
        };

        if ($eventType) {
            $this->marketingEvents->record($package, $eventType, array_merge([
                'source' => 'offer_packages_automation',
                'source_action' => $action,
            ], $extra));
        }
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
