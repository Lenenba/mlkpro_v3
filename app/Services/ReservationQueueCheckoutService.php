<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ReservationQueueItem;
use App\Models\User;
use App\Support\TipAssigneeResolver;
use App\Support\TipCalculator;
use App\Support\TipSettingsResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class ReservationQueueCheckoutService
{
    public function __construct(
        private readonly ReservationQueueService $queueService,
        private readonly TenantPaymentMethodGuardService $paymentMethodGuard,
        private readonly TipAllocationService $tipAllocationService,
        private readonly ReservationQueueInvoiceService $queueInvoiceService,
        private readonly StripeInvoiceService $stripeInvoiceService,
        private readonly QueueInvoiceReceiptService $receiptService
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function checkout(
        ReservationQueueItem $item,
        array $attributes,
        User $actor,
        array $settings,
        array $context = []
    ): array {
        $result = DB::transaction(function () use ($item, $attributes, $actor, $settings, $context): array {
            $locked = ReservationQueueItem::query()
                ->with([
                    'service:id,user_id,name,price,currency_code,tax_rate',
                    'teamMember:id,user_id',
                ])
                ->lockForUpdate()
                ->findOrFail($item->id);

            $existingPayment = Payment::query()
                ->where('reservation_queue_item_id', $locked->id)
                ->lockForUpdate()
                ->first();

            if ($existingPayment) {
                $invoice = $existingPayment->invoice ?: $this->queueInvoiceService->findOrCreateForQueueItem($locked);
                if (! $existingPayment->invoice_id) {
                    $existingPayment->forceFill([
                        'invoice_id' => $invoice->id,
                        'customer_id' => $invoice->customer_id,
                    ])->save();
                    $invoice->refreshPaymentStatus();
                }

                if (! in_array($existingPayment->status, Payment::settledStatuses(), true)) {
                    throw ValidationException::withMessages([
                        'payment' => ['A payment is already awaiting confirmation for this queue item.'],
                    ]);
                }

                $queueItem = (string) $locked->status === ReservationQueueItem::STATUS_DONE
                    ? $locked
                    : $this->queueService->transition(
                        $locked,
                        'done',
                        $actor,
                        $settings,
                        array_replace($context, ['checkout_settled' => true])
                    );

                return [
                    'queue_item' => $queueItem,
                    'payment' => $existingPayment,
                    'invoice' => $invoice->fresh(['items', 'payments']),
                    'already_paid' => true,
                    'requires_stripe' => false,
                ];
            }

            if ((string) $locked->status !== ReservationQueueItem::STATUS_AWAITING_PAYMENT) {
                throw ValidationException::withMessages([
                    'queue' => ['Finish the service before recording its payment.'],
                ]);
            }

            $checkout = $this->queueService->checkoutSummary($locked);
            $subtotal = (float) ($checkout['subtotal'] ?? $checkout['base_amount'] ?? 0);
            $amount = (float) ($checkout['invoice_total'] ?? $checkout['base_amount'] ?? 0);
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'payment' => ['No payment is required for this queue item.'],
                ]);
            }

            $method = $this->paymentMethodGuard->evaluate(
                (int) $locked->account_id,
                $attributes['method'] ?? null,
                'walk_in'
            );
            if (! ($method['allowed'] ?? false)) {
                throw ValidationException::withMessages([
                    'method' => [(string) ($method['error_message'] ?? TenantPaymentMethodGuardService::ERROR_MESSAGE)],
                ]);
            }

            $tip = TipCalculator::resolve(
                $subtotal,
                $attributes,
                TipSettingsResolver::forAccountId((int) $locked->account_id)
            );
            $tip['tip_base_amount'] = round(max(0, $subtotal), 2);
            $tip['charged_total'] = round($amount + (float) $tip['tip_amount'], 2);
            $invoice = $this->queueInvoiceService->findOrCreateForQueueItem($locked);
            if (array_key_exists('receipt_delivery', $attributes)) {
                $invoice->forceFill([
                    'receipt_delivery' => $this->receiptDelivery($attributes['receipt_delivery'] ?? null),
                ])->save();
            }

            $tipAssigneeUserId = TipAssigneeResolver::resolveForInvoice($invoice);
            $tip['tip_assignee_user_id'] = $tip['tip_amount'] > 0 ? $tipAssigneeUserId : null;

            if (($method['normalized_business_method'] ?? null) === 'stripe') {
                if (! $this->stripeInvoiceService->isConfigured()) {
                    throw ValidationException::withMessages([
                        'method' => ['Stripe is not configured for this account.'],
                    ]);
                }

                return [
                    'queue_item' => $locked,
                    'payment' => null,
                    'invoice' => $invoice->fresh(['items', 'payments']),
                    'already_paid' => false,
                    'requires_stripe' => true,
                    'stripe_amount' => $amount,
                    'stripe_tip' => $tip,
                ];
            }

            $this->stripeInvoiceService->ensureNoActiveQueueCheckoutAttempt($locked);

            $payment = Payment::query()->create([
                'invoice_id' => $invoice->id,
                'reservation_queue_item_id' => $locked->id,
                'customer_id' => $invoice->customer_id,
                'user_id' => $locked->account_id,
                'amount' => $amount,
                'currency_code' => $checkout['currency_code'],
                'tip_amount' => $tip['tip_amount'],
                'tip_type' => $tip['tip_type'],
                'tip_percent' => $tip['tip_percent'],
                'tip_base_amount' => $tip['tip_base_amount'],
                'charged_total' => $tip['charged_total'],
                'tip_assignee_user_id' => $tip['tip_assignee_user_id'],
                'method' => $method['canonical_method'],
                'provider' => 'manual',
                'status' => Payment::STATUS_COMPLETED,
                'reference' => $this->nullableString($attributes['reference'] ?? null),
                'notes' => $this->checkoutNotes($locked, $attributes['notes'] ?? null),
                'paid_at' => now('UTC'),
            ]);

            $this->tipAllocationService->syncForPayment($payment);
            $previousInvoiceStatus = $invoice->status;
            $invoice->refreshPaymentStatus();
            $queueItem = $this->queueService->transition(
                $locked,
                'done',
                $actor,
                $settings,
                array_replace($context, ['checkout_settled' => true])
            );

            ActivityLog::record($actor, $payment, 'queue_checkout_completed', [
                'account_id' => (int) $locked->account_id,
                'queue_item_id' => (int) $locked->id,
                'reservation_id' => $locked->reservation_id ? (int) $locked->reservation_id : null,
                'service_id' => $locked->service_id ? (int) $locked->service_id : null,
                'queue_number' => $locked->queue_number,
                'invoice_id' => $invoice->id,
                'amount' => $payment->amount,
                'tip_amount' => $payment->tip_amount,
                'charged_total' => $payment->charged_total,
                'method' => $payment->method,
            ], 'Queue checkout completed');

            return [
                'queue_item' => $queueItem,
                'payment' => $payment,
                'invoice' => $invoice->fresh(['items', 'payments']),
                'already_paid' => false,
                'requires_stripe' => false,
                'previous_invoice_status' => $previousInvoiceStatus,
            ];
        });

        if (($result['requires_stripe'] ?? false) === true) {
            $invoice = $result['invoice'];
            if (! $invoice instanceof Invoice) {
                throw ValidationException::withMessages([
                    'payment' => ['Unable to prepare the invoice for Stripe checkout.'],
                ]);
            }

            $attempt = $this->stripeInvoiceService->prepareQueueCheckoutAttempt(
                $invoice,
                $item,
                (float) $result['stripe_amount'],
                (array) $result['stripe_tip']
            );
            $successUrl = URL::route('reservation.queue.stripe.return', [
                'attempt' => $attempt->public_id,
            ]);
            $successUrl .= (str_contains($successUrl, '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}';
            $cancelUrl = URL::route('reservation.queue.stripe.cancel', [
                'attempt' => $attempt->public_id,
            ]);
            $session = $this->stripeInvoiceService->startQueueCheckoutAttempt(
                $attempt,
                $successUrl,
                $cancelUrl
            );

            if (empty($session['url'])) {
                throw ValidationException::withMessages([
                    'payment' => ['Unable to start Stripe checkout.'],
                ]);
            }

            $result['checkout_url'] = $session['url'];
            $result['stripe_attempt'] = [
                'id' => $attempt->public_id,
                'status' => $attempt->fresh()->status,
                'status_url' => URL::route('reservation.queue.stripe.status', [
                    'attempt' => $attempt->public_id,
                ]),
            ];

            return $result;
        }

        $invoice = $result['invoice'] ?? null;
        if ($invoice instanceof Invoice) {
            $result['receipt'] = $this->receiptService->deliver($invoice, $actor);
        }

        return $result;
    }

    private function checkoutNotes(ReservationQueueItem $item, mixed $notes): string
    {
        $reference = $item->queue_number ?: '#'.$item->id;
        $prefix = 'Queue checkout '.$reference;
        $notes = $this->nullableString($notes);

        return $notes ? $prefix."\n".$notes : $prefix;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function receiptDelivery(mixed $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($value, [QueueInvoiceReceiptService::DELIVERY_EMAIL, QueueInvoiceReceiptService::DELIVERY_SMS], true)
            ? $value
            : null;
    }
}
