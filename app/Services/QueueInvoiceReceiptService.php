<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\InvoiceAvailableNotification;
use App\Support\LocalePreference;
use App\Support\NotificationDispatcher;
use Illuminate\Support\Facades\URL;

class QueueInvoiceReceiptService
{
    public const DELIVERY_EMAIL = 'email';

    public const DELIVERY_SMS = 'sms';

    /**
     * @return array{delivered: bool, channel: string|null, reason: string|null, receipt_url: string}
     */
    public function deliver(Invoice $invoice, ?User $actor = null, ?string $channel = null): array
    {
        $invoice->loadMissing(['customer', 'user']);
        $channel = $this->normalizeChannel($channel ?? $invoice->receipt_delivery);
        $result = [
            'delivered' => false,
            'channel' => $channel,
            'reason' => null,
            'receipt_url' => $this->receiptUrl($invoice),
        ];

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
            $result['reason'] = 'already_delivered';

            return $result;
        }

        return $channel === self::DELIVERY_EMAIL
            ? $this->deliverEmail($invoice, $actor, $result)
            : $this->deliverSms($invoice, $actor, $result);
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
     * @param  array{delivered: bool, channel: string|null, reason: string|null, receipt_url: string}  $result
     * @return array{delivered: bool, channel: string|null, reason: string|null, receipt_url: string}
     */
    private function deliverEmail(Invoice $invoice, ?User $actor, array $result): array
    {
        $email = $this->emailFor($invoice);
        if (! $email) {
            return $this->recordFailure($invoice, $actor, $result, 'missing_email');
        }

        $isFrench = str_starts_with(LocalePreference::forUser($invoice->user), 'fr');
        $title = $isFrench ? 'Votre reçu de paiement' : 'Your payment receipt';
        $intro = $isFrench
            ? 'Votre paiement a été confirmé. Votre reçu est joint à ce message.'
            : 'Your payment has been confirmed. Your receipt is attached to this message.';
        $sent = NotificationDispatcher::sendToMail($email, new InvoiceAvailableNotification(
            $invoice,
            $title,
            $intro,
            $this->receiptDetails($invoice, $isFrench),
            $result['receipt_url'],
            $isFrench ? 'Télécharger le reçu' : 'Download receipt',
            $title
        ), [
            'invoice_id' => $invoice->id,
            'channel' => self::DELIVERY_EMAIL,
        ]);

        if (! $sent) {
            return $this->recordFailure($invoice, $actor, $result, 'email_dispatch_failed', [
                'email' => $email,
            ]);
        }

        return $this->recordDelivered($invoice, $actor, $result, [
            'email' => $email,
        ]);
    }

    /**
     * @param  array{delivered: bool, channel: string|null, reason: string|null, receipt_url: string}  $result
     * @return array{delivered: bool, channel: string|null, reason: string|null, receipt_url: string}
     */
    private function deliverSms(Invoice $invoice, ?User $actor, array $result): array
    {
        $phone = $this->phoneFor($invoice);
        if (! $phone) {
            return $this->recordFailure($invoice, $actor, $result, 'missing_phone');
        }

        $isFrench = str_starts_with(LocalePreference::forUser($invoice->user), 'fr');
        $number = $invoice->number ?: '#'.$invoice->id;
        $message = $isFrench
            ? "Paiement confirmé pour la facture {$number}. Reçu : {$result['receipt_url']}"
            : "Payment confirmed for invoice {$number}. Receipt: {$result['receipt_url']}";
        $sent = app(SmsNotificationService::class)->sendWithResult($phone, $message);

        if (! ($sent['ok'] ?? false)) {
            return $this->recordFailure($invoice, $actor, $result, (string) ($sent['reason'] ?? 'sms_dispatch_failed'), [
                'phone' => $phone,
                'provider_status' => $sent['status'] ?? null,
            ]);
        }

        return $this->recordDelivered($invoice, $actor, $result, [
            'phone' => $phone,
            'provider_message_id' => $sent['sid'] ?? null,
        ]);
    }

    /**
     * @param  array{delivered: bool, channel: string|null, reason: string|null, receipt_url: string}  $result
     * @param  array<string, mixed>  $context
     * @return array{delivered: bool, channel: string|null, reason: string|null, receipt_url: string}
     */
    private function recordDelivered(Invoice $invoice, ?User $actor, array $result, array $context): array
    {
        $invoice->forceFill([
            'receipt_delivery' => $result['channel'],
            'receipt_delivered_at' => now('UTC'),
        ])->save();

        ActivityLog::record($actor, $invoice, 'payment_receipt_sent', array_filter(array_merge($context, [
            'invoice_id' => $invoice->id,
            'channel' => $result['channel'],
        ]), static fn ($value) => $value !== null), 'Payment receipt sent');

        $result['delivered'] = true;

        return $result;
    }

    /**
     * @param  array{delivered: bool, channel: string|null, reason: string|null, receipt_url: string}  $result
     * @param  array<string, mixed>  $context
     * @return array{delivered: bool, channel: string|null, reason: string|null, receipt_url: string}
     */
    private function recordFailure(Invoice $invoice, ?User $actor, array $result, string $reason, array $context = []): array
    {
        ActivityLog::record($actor, $invoice, 'payment_receipt_failed', array_filter(array_merge($context, [
            'invoice_id' => $invoice->id,
            'channel' => $result['channel'],
            'reason' => $reason,
        ]), static fn ($value) => $value !== null), 'Payment receipt delivery failed');

        $result['reason'] = $reason;

        return $result;
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
        return [
            $isFrench ? 'Facture' : 'Invoice' => (string) ($invoice->number ?: '#'.$invoice->id),
            $isFrench ? 'Total payé' : 'Total paid' => number_format((float) $invoice->amount_paid, 2),
        ];
    }

    private function normalizeChannel(mixed $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($value, [self::DELIVERY_EMAIL, self::DELIVERY_SMS], true) ? $value : null;
    }
}
