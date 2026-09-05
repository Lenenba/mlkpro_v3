<?php

namespace App\Services;

use App\Exceptions\ReceiptDeliveryInProgressException;
use App\Jobs\DeliverQueueInvoiceReceipt;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\InvoiceAvailableNotification;
use App\Support\LocalePreference;
use App\Support\NotificationDispatcher;
use App\Support\QueueWorkload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

class QueueInvoiceReceiptService
{
    private const SENDING_LEASE_GRACE_SECONDS = 60;

    private const MIN_SENDING_LEASE_SECONDS = 120;

    public const DELIVERY_EMAIL = 'email';

    public const DELIVERY_SMS = 'sms';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENDING = 'sending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    /**
     * Queue a receipt for delivery. `receipt_delivered_at` is deliberately only
     * written by the worker after the mail transport or SMS provider accepts it.
     *
     * @return array{delivered: bool, queued: bool, channel: string|null, delivery_status: string|null, reason: string|null, receipt_url: string}
     */
    public function deliver(Invoice $invoice, ?User $actor = null, ?string $channel = null): array
    {
        $invoice->loadMissing(['customer', 'user', 'items', 'payments']);
        $channel = $this->normalizeChannel($channel ?? $invoice->receipt_delivery);
        $result = $this->result($invoice, $channel);

        if (! $channel) {
            $result['reason'] = 'not_requested';

            return $result;
        }

        if ((string) $invoice->status !== 'paid') {
            $result['reason'] = 'invoice_not_paid';

            return $result;
        }

        if ($invoice->receipt_delivered_at) {
            $result['delivered'] = true;
            $result['delivery_status'] = self::STATUS_DELIVERED;
            $result['reason'] = 'already_delivered';

            return $result;
        }

        if (in_array((string) $invoice->receipt_delivery_status, [self::STATUS_QUEUED, self::STATUS_SENDING], true)) {
            $result['queued'] = true;
            $result['delivery_status'] = (string) $invoice->receipt_delivery_status;
            $result['reason'] = 'already_queued';

            return $result;
        }

        $claimed = Invoice::query()
            ->whereKey($invoice->id)
            ->whereNull('receipt_delivered_at')
            ->where(function ($query): void {
                $query->whereNull('receipt_delivery_status')
                    ->orWhere('receipt_delivery_status', self::STATUS_FAILED);
            })
            ->update([
                'receipt_delivery' => $channel,
                'receipt_delivery_status' => self::STATUS_QUEUED,
                'receipt_delivery_queued_at' => now('UTC'),
                'receipt_delivery_started_at' => null,
                'receipt_delivery_claim_token' => null,
                'receipt_delivery_last_error' => null,
            ]);

        if (! $claimed) {
            $invoice->refresh();

            return $this->result($invoice, $channel);
        }

        try {
            DeliverQueueInvoiceReceipt::dispatch((int) $invoice->id, $actor?->id, $channel);
        } catch (Throwable $exception) {
            $this->markDeliveryFailed((int) $invoice->id, $actor?->id, $channel, $exception);

            return $this->result($invoice->fresh(), $channel, 'queue_dispatch_failed');
        }

        $invoice->refresh();

        return $this->result($invoice, $channel);
    }

    public function receiptUrl(Invoice $invoice): string
    {
        return URL::temporarySignedRoute(
            'public.invoices.receipt',
            now()->addDays(90),
            ['invoice' => $invoice->id]
        );
    }

    /**
     * Perform the actual provider call from the queued job.
     */
    public function deliverQueued(int $invoiceId, ?int $actorUserId, ?string $channel = null): void
    {
        $invoice = Invoice::query()
            ->with(['customer', 'user', 'items', 'payments'])
            ->find($invoiceId);
        if (! $invoice || $invoice->receipt_delivered_at) {
            return;
        }

        $channel = $this->normalizeChannel($channel ?? $invoice->receipt_delivery);
        $actor = $actorUserId ? User::query()->find($actorUserId) : null;
        if (! $channel || (string) $invoice->status !== 'paid') {
            $this->recordFailure(
                $invoice,
                $actor,
                $channel,
                ! $channel ? 'invalid_channel' : 'invoice_not_paid',
                [],
                true
            );

            return;
        }

        if ((string) $invoice->receipt_delivery_status === self::STATUS_SENDING) {
            $this->recoverStaleSendingClaim((int) $invoice->id);
            $invoice->refresh();

            if ((string) $invoice->receipt_delivery_status === self::STATUS_SENDING) {
                throw new ReceiptDeliveryInProgressException;
            }
        }

        $claimToken = (string) Str::uuid();
        $claimStartedAt = now('UTC');

        $claimed = Invoice::query()
            ->whereKey($invoice->id)
            ->whereNull('receipt_delivered_at')
            ->where('receipt_delivery_status', self::STATUS_QUEUED)
            ->update([
                'receipt_delivery_status' => self::STATUS_SENDING,
                'receipt_delivery_started_at' => $claimStartedAt,
                'receipt_delivery_claim_token' => $claimToken,
                'receipt_delivery_attempts' => DB::raw('receipt_delivery_attempts + 1'),
                'receipt_delivery_last_error' => null,
            ]);
        if (! $claimed) {
            return;
        }

        $invoice->refresh()->loadMissing(['customer', 'user', 'items', 'payments']);

        try {
            if ($channel === self::DELIVERY_EMAIL) {
                $this->deliverEmailNow($invoice, $actor, $claimToken);

                return;
            }

            $this->deliverSmsNow($invoice, $actor, $claimToken);
        } catch (Throwable $exception) {
            $this->releaseSendingClaimForRetry(
                (int) $invoice->id,
                $claimToken,
                $exception
            );

            throw $exception;
        }
    }

    public function markDeliveryFailed(
        int $invoiceId,
        ?int $actorUserId,
        ?string $channel,
        Throwable $exception
    ): void {
        $invoice = Invoice::query()->find($invoiceId);
        if (! $invoice || $invoice->receipt_delivered_at) {
            return;
        }

        $actor = $actorUserId ? User::query()->find($actorUserId) : null;
        $reason = $this->errorMessage($exception) ?: 'delivery_attempts_exhausted';
        $this->recordFailure(
            $invoice,
            $actor,
            $this->normalizeChannel($channel ?? $invoice->receipt_delivery),
            $reason,
            ['error' => $reason],
            true
        );
    }

    private function deliverEmailNow(Invoice $invoice, ?User $actor, string $claimToken): void
    {
        $email = $this->emailFor($invoice);
        if (! $email) {
            $this->recordFailure($invoice, $actor, self::DELIVERY_EMAIL, 'missing_email', [], true, $claimToken);

            return;
        }

        $isFrench = str_starts_with(LocalePreference::forCustomer($invoice->customer, $invoice->user), 'fr');
        $title = $isFrench ? 'Votre reçu de paiement' : 'Your payment receipt';
        $intro = $isFrench
            ? 'Votre paiement a été confirmé. Votre reçu est joint à ce message.'
            : 'Your payment has been confirmed. Your receipt is attached to this message.';
        $sent = NotificationDispatcher::sendNowToMail($email, new InvoiceAvailableNotification(
            $invoice,
            $title,
            $intro,
            $this->receiptDetails($invoice, $isFrench),
            $this->receiptUrl($invoice),
            $isFrench ? 'Télécharger le reçu' : 'Download receipt',
            $title
        ), [
            'invoice_id' => $invoice->id,
            'channel' => self::DELIVERY_EMAIL,
            'receipt_delivery' => true,
        ]);

        if (! $sent) {
            $this->recordRetryableFailure($invoice, $actor, self::DELIVERY_EMAIL, 'email_transport_failed', [
                'email' => $email,
            ], $claimToken);

            throw new \RuntimeException('email_transport_failed');
        }

        $this->recordDelivered($invoice, $actor, self::DELIVERY_EMAIL, [
            'email' => $email,
        ], $claimToken);
    }

    private function deliverSmsNow(Invoice $invoice, ?User $actor, string $claimToken): void
    {
        $phone = $this->phoneFor($invoice);
        if (! $phone) {
            $this->recordFailure($invoice, $actor, self::DELIVERY_SMS, 'missing_phone', [], true, $claimToken);

            return;
        }

        $isFrench = str_starts_with(LocalePreference::forCustomer($invoice->customer, $invoice->user), 'fr');
        $number = $invoice->number ?: '#'.$invoice->id;
        $receiptUrl = $this->receiptUrl($invoice);
        $message = $isFrench
            ? "Paiement confirmé pour la facture {$number}. Reçu : {$receiptUrl}"
            : "Payment confirmed for invoice {$number}. Receipt: {$receiptUrl}";
        $sent = app(SmsNotificationService::class)->sendWithResult($phone, $message);

        if (! ($sent['ok'] ?? false)) {
            $reason = (string) ($sent['reason'] ?? 'sms_provider_failed');
            $this->recordRetryableFailure($invoice, $actor, self::DELIVERY_SMS, $reason, [
                'phone' => $phone,
                'provider_status' => $sent['status'] ?? null,
            ], $claimToken);

            throw new \RuntimeException($reason);
        }

        $this->recordDelivered($invoice, $actor, self::DELIVERY_SMS, [
            'phone' => $phone,
            'provider_message_id' => $sent['sid'] ?? null,
        ], $claimToken);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recordDelivered(
        Invoice $invoice,
        ?User $actor,
        string $channel,
        array $context,
        string $claimToken
    ): void {
        $deliveredAt = now('UTC');
        $recorded = Invoice::query()
            ->whereKey($invoice->id)
            ->whereNull('receipt_delivered_at')
            ->where('receipt_delivery_status', self::STATUS_SENDING)
            ->where('receipt_delivery_claim_token', $claimToken)
            ->update([
                'receipt_delivery' => $channel,
                'receipt_delivery_status' => self::STATUS_DELIVERED,
                'receipt_delivery_started_at' => null,
                'receipt_delivery_claim_token' => null,
                'receipt_delivery_last_error' => null,
                'receipt_delivered_at' => $deliveredAt,
            ]);
        if (! $recorded) {
            return;
        }

        $invoice->refresh();

        ActivityLog::record($actor, $invoice, 'payment_receipt_sent', array_filter(array_merge($context, [
            'invoice_id' => $invoice->id,
            'channel' => $channel,
            'attempts' => (int) $invoice->receipt_delivery_attempts,
        ]), static fn ($value) => $value !== null), 'Payment receipt sent');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recordRetryableFailure(
        Invoice $invoice,
        ?User $actor,
        string $channel,
        string $reason,
        array $context,
        string $claimToken
    ): void {
        $this->recordFailure($invoice, $actor, $channel, $reason, $context, false, $claimToken);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recordFailure(
        Invoice $invoice,
        ?User $actor,
        ?string $channel,
        string $reason,
        array $context,
        bool $terminal,
        ?string $claimToken = null
    ): void {
        $query = Invoice::query()
            ->whereKey($invoice->id)
            ->whereNull('receipt_delivered_at');
        if ($claimToken !== null) {
            $query->where('receipt_delivery_status', self::STATUS_SENDING)
                ->where('receipt_delivery_claim_token', $claimToken);
        } else {
            $query->where(function ($query): void {
                $query->whereNull('receipt_delivery_status')
                    ->orWhereIn('receipt_delivery_status', [self::STATUS_QUEUED, self::STATUS_FAILED]);
            });
        }

        $recorded = $query->update([
            'receipt_delivery' => $channel ?? $invoice->receipt_delivery,
            'receipt_delivery_status' => $terminal ? self::STATUS_FAILED : self::STATUS_QUEUED,
            'receipt_delivery_queued_at' => $terminal ? $invoice->receipt_delivery_queued_at : now('UTC'),
            'receipt_delivery_started_at' => null,
            'receipt_delivery_claim_token' => null,
            'receipt_delivery_last_error' => $this->truncateError($reason),
        ]);
        if (! $recorded) {
            return;
        }

        $invoice->refresh();

        ActivityLog::record($actor, $invoice, 'payment_receipt_failed', array_filter(array_merge($context, [
            'invoice_id' => $invoice->id,
            'channel' => $channel,
            'reason' => $reason,
            'terminal' => $terminal,
            'attempts' => (int) $invoice->receipt_delivery_attempts,
        ]), static fn ($value) => $value !== null), 'Payment receipt delivery failed');
    }

    private function releaseSendingClaimForRetry(int $invoiceId, string $claimToken, Throwable $exception): void
    {
        Invoice::query()
            ->whereKey($invoiceId)
            ->whereNull('receipt_delivered_at')
            ->where('receipt_delivery_status', self::STATUS_SENDING)
            ->where('receipt_delivery_claim_token', $claimToken)
            ->update([
                'receipt_delivery_status' => self::STATUS_QUEUED,
                'receipt_delivery_queued_at' => now('UTC'),
                'receipt_delivery_started_at' => null,
                'receipt_delivery_claim_token' => null,
                'receipt_delivery_last_error' => $this->errorMessage($exception),
            ]);
    }

    private function recoverStaleSendingClaim(int $invoiceId): void
    {
        $staleBefore = now('UTC')->subSeconds($this->sendingLeaseSeconds());

        Invoice::query()
            ->whereKey($invoiceId)
            ->whereNull('receipt_delivered_at')
            ->where('receipt_delivery_status', self::STATUS_SENDING)
            ->whereNotNull('receipt_delivery_started_at')
            ->where('receipt_delivery_started_at', '<=', $staleBefore)
            ->update([
                'receipt_delivery_status' => self::STATUS_QUEUED,
                'receipt_delivery_queued_at' => now('UTC'),
                'receipt_delivery_started_at' => null,
                'receipt_delivery_claim_token' => null,
                'receipt_delivery_last_error' => 'stale_sending_claim_recovered',
            ]);
    }

    private function sendingLeaseSeconds(): int
    {
        return max(
            self::MIN_SENDING_LEASE_SECONDS,
            QueueWorkload::timeout('notifications') + self::SENDING_LEASE_GRACE_SECONDS
        );
    }

    /**
     * @return array{delivered: bool, queued: bool, channel: string|null, delivery_status: string|null, reason: string|null, receipt_url: string}
     */
    private function result(Invoice $invoice, ?string $channel, ?string $reason = null): array
    {
        $delivered = $invoice->receipt_delivered_at !== null;
        $status = $delivered
            ? self::STATUS_DELIVERED
            : ($invoice->receipt_delivery_status ?: null);

        return [
            'delivered' => $delivered,
            'queued' => in_array($status, [self::STATUS_QUEUED, self::STATUS_SENDING], true),
            'channel' => $channel,
            'delivery_status' => $status,
            'reason' => $reason ?? $this->deliveryReason($invoice, $status),
            'receipt_url' => $this->receiptUrl($invoice),
        ];
    }

    private function deliveryReason(Invoice $invoice, ?string $status): ?string
    {
        return match ($status) {
            self::STATUS_DELIVERED => 'delivered',
            self::STATUS_QUEUED, self::STATUS_SENDING => 'queued',
            self::STATUS_FAILED => $invoice->receipt_delivery_last_error ?: 'delivery_failed',
            default => null,
        };
    }

    private function emailFor(Invoice $invoice): ?string
    {
        $email = $invoice->customer?->email ?: data_get($invoice->customer_snapshot, 'email');

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function phoneFor(Invoice $invoice): ?string
    {
        $phone = $invoice->customer?->phone ?: data_get($invoice->customer_snapshot, 'phone');

        return is_string($phone) && trim($phone) !== '' ? trim($phone) : null;
    }

    /**
     * @return array<string, string>
     */
    private function receiptDetails(Invoice $invoice, bool $isFrench): array
    {
        $invoice->loadMissing(['items', 'payments']);
        $subtotal = $invoice->subtotal !== null
            ? (float) $invoice->subtotal
            : (float) $invoice->items->sum('total');
        $invoiceTotal = (float) $invoice->total;
        if ($subtotal <= 0 && $invoiceTotal > 0) {
            $subtotal = $invoiceTotal;
        }
        $taxTotal = $invoice->tax_total !== null
            ? max(0, (float) $invoice->tax_total)
            : max(0, round($invoiceTotal - $subtotal, 2));
        $payments = $invoice->payments->whereIn('status', Payment::settledStatuses());
        $tipTotal = round((float) $payments->sum(
            static fn (Payment $payment): float => $payment->tip_net_amount
        ), 2);
        $chargedTotal = round((float) $payments->sum(
            static fn (Payment $payment): float => $payment->charged_net_amount
        ), 2);

        return [
            $isFrench ? 'Facture' : 'Invoice' => (string) ($invoice->number ?: '#'.$invoice->id),
            $isFrench ? 'Sous-total' : 'Subtotal' => $this->formatAmount($subtotal, $invoice),
            $isFrench ? 'Taxes' : 'Taxes' => $this->formatAmount($taxTotal, $invoice),
            $isFrench ? 'Total de la facture' : 'Invoice total' => $this->formatAmount($invoiceTotal, $invoice),
            $isFrench ? 'Pourboire' : 'Tip' => $this->formatAmount($tipTotal, $invoice),
            $isFrench ? 'Total encaissé' : 'Charged total' => $this->formatAmount($chargedTotal, $invoice),
        ];
    }

    private function formatAmount(float $amount, Invoice $invoice): string
    {
        return number_format($amount, 2).' '.strtoupper((string) ($invoice->currency_code ?: 'CAD'));
    }

    private function normalizeChannel(mixed $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($value, [self::DELIVERY_EMAIL, self::DELIVERY_SMS], true) ? $value : null;
    }

    private function errorMessage(Throwable $exception): string
    {
        return $this->truncateError($exception->getMessage());
    }

    private function truncateError(string $value): string
    {
        return mb_substr(trim($value), 0, 255);
    }
}
