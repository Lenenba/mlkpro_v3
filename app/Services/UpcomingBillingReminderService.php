<?php

namespace App\Services;

use App\Models\Billing\BillingCycleReminderLog;
use App\Models\Billing\StripeSubscription;
use App\Models\User;
use App\Notifications\UpcomingBillingReminderNotification;
use App\Support\CurrencyFormatter;
use App\Support\LocalePreference;
use App\Support\NotificationDispatcher;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class UpcomingBillingReminderService
{
    public function __construct(
        private readonly BillingPlanService $billingPlans,
        private readonly BillingSubscriptionService $billingSubscriptions,
        private readonly StripeBillingService $stripeBilling,
    ) {}

    public function process(array $days = [], ?int $tenantId = null, bool $dryRun = false): array
    {
        $normalizedDays = $this->normalizeDays($days);

        if (! config('billing.upcoming_reminders.enabled', true)) {
            return [
                'enabled' => false,
                'provider' => $this->billingSubscriptions->providerEffective(),
                'scanned' => 0,
                'candidates' => [],
                'sent' => 0,
                'skipped' => 0,
                'already_sent' => 0,
                'failed' => 0,
                'days' => $normalizedDays,
            ];
        }

        if (! Schema::hasTable('billing_cycle_reminder_logs')) {
            return [
                'enabled' => true,
                'provider' => $this->billingSubscriptions->providerEffective(),
                'scanned' => 0,
                'candidates' => [],
                'sent' => 0,
                'skipped' => 0,
                'already_sent' => 0,
                'failed' => 0,
                'days' => $normalizedDays,
                'missing_table' => true,
            ];
        }

        if (! $this->billingSubscriptions->isStripe()) {
            return [
                'enabled' => true,
                'provider' => $this->billingSubscriptions->providerEffective(),
                'scanned' => 0,
                'candidates' => [],
                'sent' => 0,
                'skipped' => 0,
                'already_sent' => 0,
                'failed' => 0,
                'days' => $normalizedDays,
            ];
        }

        $windowEnd = now()->addDays(max($normalizedDays))->endOfDay();
        $query = StripeSubscription::query()
            ->with('user')
            ->whereIn('status', ['active', 'trialing'])
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '>=', now()->startOfDay())
            ->where('current_period_end', '<=', $windowEnd)
            ->orderBy('current_period_end');

        if ($tenantId) {
            $query->where('user_id', $tenantId);
        }

        $subscriptions = $query->get();

        $result = [
            'enabled' => true,
            'provider' => 'stripe',
            'scanned' => $subscriptions->count(),
            'candidates' => [],
            'sent' => 0,
            'skipped' => 0,
            'already_sent' => 0,
            'failed' => 0,
            'days' => $normalizedDays,
        ];

        foreach ($subscriptions as $subscription) {
            $owner = $subscription->user;
            if (! $owner instanceof User || ! $owner->email || $owner->isSuspended()) {
                $result['skipped']++;

                continue;
            }

            $billingDate = $subscription->current_period_end instanceof Carbon
                ? $subscription->current_period_end->copy()->startOfDay()
                : null;
            if (! $billingDate) {
                $result['skipped']++;

                continue;
            }

            $daysUntilBilling = now()->startOfDay()->diffInDays($billingDate, false);
            if (! in_array($daysUntilBilling, $normalizedDays, true)) {
                $result['skipped']++;

                continue;
            }

            $payload = $this->buildReminderPayload($owner, $subscription, $billingDate, $daysUntilBilling);
            if ($payload === null) {
                $result['skipped']++;

                continue;
            }

            $result['candidates'][] = [
                'user_id' => $owner->id,
                'company_name' => $owner->company_name ?: $owner->email,
                'email' => $owner->email,
                'billing_date' => $billingDate->toDateString(),
                'days_before' => $daysUntilBilling,
                'plan_name' => $payload['planName'],
                'formatted_total' => $payload['formattedTotal'],
            ];

            if ($this->alreadySent($payload['reminderKey'])) {
                $result['already_sent']++;

                continue;
            }

            if ($dryRun) {
                continue;
            }

            $sent = NotificationDispatcher::send(
                $owner,
                new UpcomingBillingReminderNotification($payload),
                [
                    'user_id' => $owner->id,
                    'stripe_subscription_id' => $subscription->stripe_id,
                ]
            );

            if (! $sent) {
                $result['failed']++;

                continue;
            }

            BillingCycleReminderLog::query()->create([
                'user_id' => $owner->id,
                'provider' => 'stripe',
                'provider_subscription_id' => $subscription->stripe_id,
                'billing_date' => $billingDate,
                'days_before' => $daysUntilBilling,
                'reminder_key' => $payload['reminderKey'],
                'payload' => [
                    'plan_name' => $payload['planName'],
                    'formatted_total' => $payload['formattedTotal'],
                    'line_items' => $payload['lineItems'],
                ],
                'sent_at' => now(),
            ]);

            $result['sent']++;
        }

        return $result;
    }

    private function buildReminderPayload(
        User $owner,
        StripeSubscription $subscription,
        Carbon $billingDate,
        int $daysUntilBilling,
    ): ?array {
        try {
            $preview = $this->stripeBilling->previewUpcomingInvoice($owner, $subscription);
        } catch (\Throwable $exception) {
            Log::warning('Unable to preview upcoming Stripe invoice for billing reminder.', [
                'user_id' => $owner->id,
                'stripe_subscription_id' => $subscription->stripe_id,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! is_array($preview)) {
            return null;
        }

        $currency = strtoupper((string) ($preview['currency'] ?? $subscription->currency_code ?? $owner->businessCurrencyCode()));
        $locale = LocalePreference::forUser($owner);
        $totalCents = $this->normalizeMinorAmount($preview['total'] ?? $preview['amount_due'] ?? null);
        if ($totalCents <= 0) {
            return null;
        }

        $subtotalCents = $this->normalizeMinorAmount($preview['subtotal'] ?? null);
        $taxCents = $this->resolveTaxCents($preview, $totalCents, $subtotalCents);
        $planPrice = $subscription->price_id
            ? $this->billingPlans->resolveByStripePriceId($subscription->price_id)
            : null;
        $planName = $planPrice?->planName
            ?: (is_string($subscription->plan_code) && $subscription->plan_code !== ''
                ? ucwords(str_replace('_', ' ', $subscription->plan_code))
                : 'Malikia Pro subscription');

        $invoiceLines = collect($preview['lines']['data'] ?? []);
        $mainLine = $invoiceLines->first(fn (array $line) => ($line['price']['id'] ?? null) === $subscription->price_id);
        $seatQuantity = is_numeric($mainLine['quantity'] ?? null)
            ? max(1, (int) $mainLine['quantity'])
            : $this->billingSubscriptions->resolveBillableQuantity($owner, $subscription->plan_code);

        $lineItems = $invoiceLines
            ->map(fn (array $line) => $this->formatLineItem($line, $currency))
            ->filter()
            ->values()
            ->take(6)
            ->all();

        $billingPeriod = (string) ($planPrice?->billingPeriod->value ?? $subscription->billing_period ?? 'monthly');
        $billingDateLabel = str_starts_with($locale, 'fr')
            ? $billingDate->format('d/m/Y')
            : $billingDate->format('M j, Y');

        return [
            'companyName' => $owner->company_name ?: config('app.name'),
            'companyLogo' => $owner->company_logo_url,
            'recipientName' => $owner->name ?: ($owner->company_name ?: 'there'),
            'billingDate' => $billingDate->toDateString(),
            'billingDateLabel' => $billingDateLabel,
            'daysUntilBilling' => $daysUntilBilling,
            'planName' => $planName,
            'billingPeriod' => $billingPeriod,
            'seatQuantity' => $seatQuantity,
            'currencyCode' => $currency,
            'formattedTotal' => CurrencyFormatter::format($this->minorToMajor($totalCents), $currency),
            'formattedSubtotal' => CurrencyFormatter::format($this->minorToMajor($subtotalCents), $currency),
            'formattedTax' => $taxCents > 0
                ? CurrencyFormatter::format($this->minorToMajor($taxCents), $currency)
                : null,
            'lineItems' => $lineItems,
            'lineItemCount' => count($lineItems),
            'manageBillingUrl' => route('settings.billing.edit'),
            'supportEmail' => config('mail.from.address'),
            'reminderKey' => sprintf(
                'billing:%s:%s:%s',
                $subscription->stripe_id,
                $billingDate->toDateString(),
                $daysUntilBilling
            ),
        ];
    }

    private function formatLineItem(array $line, string $currency): ?array
    {
        $amountCents = $this->normalizeMinorAmount($line['amount'] ?? null);
        if ($amountCents === 0) {
            return null;
        }

        $label = trim((string) ($line['description'] ?? ''));
        if ($label === '') {
            $label = trim((string) data_get($line, 'price.nickname', 'Recurring charge'));
        }

        if ($label === '') {
            $label = 'Recurring charge';
        }

        $quantity = is_numeric($line['quantity'] ?? null) ? (int) $line['quantity'] : null;

        return [
            'label' => $label,
            'quantity' => $quantity,
            'formatted_amount' => CurrencyFormatter::format($this->minorToMajor($amountCents), $currency),
        ];
    }

    private function normalizeDays(array $days): array
    {
        $configured = $days !== [] ? $days : (array) config('billing.upcoming_reminders.days', [7, 3, 1]);

        $normalized = collect($configured)
            ->flatMap(function ($value) {
                if (is_string($value)) {
                    return array_map('trim', explode(',', $value));
                }

                return [$value];
            })
            ->map(fn ($value) => (int) $value)
            ->filter(fn (int $value) => $value >= 1 && $value <= 30)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();

        return $normalized !== [] ? $normalized : [7, 3, 1];
    }

    private function normalizeMinorAmount(mixed $value): int
    {
        if (! is_numeric($value)) {
            return 0;
        }

        return (int) round((float) $value);
    }

    private function resolveTaxCents(array $preview, int $totalCents, int $subtotalCents): int
    {
        $explicitTax = $preview['tax'] ?? null;
        if (is_numeric($explicitTax)) {
            return max(0, (int) round((float) $explicitTax));
        }

        $excludingTax = $preview['total_excluding_tax'] ?? null;
        if (is_numeric($excludingTax)) {
            return max(0, $totalCents - (int) round((float) $excludingTax));
        }

        if ($subtotalCents > 0 && $totalCents >= $subtotalCents) {
            return max(0, $totalCents - $subtotalCents);
        }

        return 0;
    }

    private function minorToMajor(int $amount): float
    {
        return $amount / 100;
    }

    private function alreadySent(string $reminderKey): bool
    {
        return BillingCycleReminderLog::query()
            ->where('reminder_key', $reminderKey)
            ->exists();
    }
}
