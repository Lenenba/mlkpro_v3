<?php

namespace App\Http\Controllers\Reservation;

use App\Exceptions\StripeQueueCheckoutVerificationException;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationQueueItem;
use App\Models\ReservationQueuePaymentAttempt;
use App\Services\StripeInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ReservationQueueStripeCheckoutController extends Controller
{
    public function __construct(private readonly StripeInvoiceService $stripeInvoiceService) {}

    public function complete(Request $request, ReservationQueuePaymentAttempt $attempt)
    {
        $this->authorizeAttempt($request, $attempt);
        $sessionId = $request->query('session_id');

        try {
            $payment = $this->stripeInvoiceService->reconcileQueueCheckoutAttempt(
                $attempt,
                is_string($sessionId) ? $sessionId : null
            );

            return $this->respond($request, $attempt, $payment, $payment ? 'success' : 'pending');
        } catch (StripeQueueCheckoutVerificationException $exception) {
            Log::warning('Stripe queue checkout browser return failed verification.', [
                'attempt_id' => $attempt->id,
                'queue_item_id' => $attempt->reservation_queue_item_id,
                'message' => $exception->getMessage(),
            ]);

            return $this->respond($request, $attempt, null, 'error', $exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::warning('Stripe queue checkout browser return could not be reconciled.', [
                'attempt_id' => $attempt->id,
                'queue_item_id' => $attempt->reservation_queue_item_id,
                'message' => $exception->getMessage(),
            ]);

            return $this->respond(
                $request,
                $attempt,
                null,
                'pending',
                'Stripe is still confirming this payment. The ticket will update automatically.',
                503
            );
        }
    }

    public function status(Request $request, ReservationQueuePaymentAttempt $attempt)
    {
        $this->authorizeAttempt($request, $attempt);

        try {
            $attempt->refresh();
            if ($attempt->status === ReservationQueuePaymentAttempt::STATUS_FAILED) {
                return response()->json(
                    $this->payload($attempt, null, 'error', $attempt->last_error),
                    422
                );
            }
            if (in_array($attempt->status, [
                ReservationQueuePaymentAttempt::STATUS_CANCELLED,
                ReservationQueuePaymentAttempt::STATUS_EXPIRED,
            ], true)) {
                return response()->json($this->payload($attempt, null, 'cancel'));
            }

            $payment = $this->stripeInvoiceService->reconcileQueueCheckoutAttempt($attempt);
            $payload = $this->payload($attempt, $payment, $payment ? 'success' : 'pending');

            return response()->json($payload, $payload['state'] === 'error' ? 422 : 200);
        } catch (StripeQueueCheckoutVerificationException $exception) {
            return response()->json($this->payload($attempt, null, 'error', $exception->getMessage()), 422);
        } catch (\Throwable $exception) {
            Log::warning('Stripe queue checkout polling failed.', [
                'attempt_id' => $attempt->id,
                'queue_item_id' => $attempt->reservation_queue_item_id,
                'message' => $exception->getMessage(),
            ]);

            return response()->json($this->payload(
                $attempt,
                null,
                'pending',
                'Stripe confirmation is temporarily unavailable.'
            ), 503);
        }
    }

    public function cancel(Request $request, ReservationQueuePaymentAttempt $attempt)
    {
        $this->authorizeAttempt($request, $attempt);

        try {
            $payment = $this->stripeInvoiceService->cancelQueueCheckoutAttempt($attempt);
            $state = $payment ? 'success' : 'cancel';

            return $this->respond($request, $attempt, $payment, $state);
        } catch (StripeQueueCheckoutVerificationException $exception) {
            return $this->respond($request, $attempt, null, 'error', $exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::warning('Stripe queue checkout cancellation could not be confirmed.', [
                'attempt_id' => $attempt->id,
                'queue_item_id' => $attempt->reservation_queue_item_id,
                'message' => $exception->getMessage(),
            ]);

            return $this->respond(
                $request,
                $attempt,
                null,
                'pending',
                'The Stripe session could not be cancelled safely yet. No other payment method has been enabled.',
                503
            );
        }
    }

    private function authorizeAttempt(Request $request, ReservationQueuePaymentAttempt $attempt): void
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $this->authorize('viewAny', Reservation::class);
        if ($user->isClient() || (int) $user->accountOwnerId() !== (int) $attempt->account_id) {
            abort(404);
        }
    }

    private function respond(
        Request $request,
        ReservationQueuePaymentAttempt $attempt,
        ?Payment $payment,
        string $state,
        ?string $message = null,
        int $jsonStatus = 200
    ) {
        $payload = $this->payload($attempt, $payment, $state, $message);
        $state = (string) $payload['state'];
        if ($request->expectsJson()) {
            return response()->json($payload, $state === 'error' && $jsonStatus < 400 ? 422 : $jsonStatus);
        }

        $flashKey = match ($state) {
            'success' => 'success',
            'pending', 'cancel' => 'warning',
            default => 'error',
        };
        $redirectMessage = $message ?: match ($state) {
            'success' => 'Stripe payment confirmed. The invoice and receipt are ready.',
            'pending' => 'Stripe is still confirming this payment. The ticket will update automatically.',
            'cancel' => 'Stripe Checkout was cancelled. You may now choose another payment method.',
            default => 'Stripe payment could not be verified. No payment was recorded.',
        };

        return redirect()->route('reservation.index', array_filter([
            'queue_checkout' => $attempt->reservation_queue_item_id,
            'stripe' => $state,
            'invoice_id' => $attempt->invoice_id,
            'stripe_attempt' => $attempt->public_id,
        ]))->with($flashKey, $redirectMessage);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        ReservationQueuePaymentAttempt $attempt,
        ?Payment $payment,
        string $state,
        ?string $message = null
    ): array {
        $attempt = $attempt->fresh(['queueItem', 'invoice', 'payment']) ?: $attempt;
        $payment = $payment ?: $attempt->payment;
        $queueItem = $attempt->queueItem;
        $invoice = $attempt->invoice;

        $isComplete = $payment
            && in_array($payment->status, Payment::settledStatuses(), true)
            && (string) $queueItem?->status === ReservationQueueItem::STATUS_DONE
            && (string) $invoice?->status === 'paid';
        if ($isComplete) {
            $state = 'success';
        } elseif ($attempt->status === ReservationQueuePaymentAttempt::STATUS_FAILED) {
            $state = 'error';
            $message = $message ?: $attempt->last_error;
        } elseif (in_array($attempt->status, [
            ReservationQueuePaymentAttempt::STATUS_CANCELLED,
            ReservationQueuePaymentAttempt::STATUS_EXPIRED,
        ], true)) {
            $state = 'cancel';
        } elseif ($state === 'success') {
            $state = 'pending';
        }

        return [
            'state' => $state,
            'message' => $message,
            'poll_after_ms' => $state === 'pending' ? 2500 : null,
            'attempt' => [
                'id' => $attempt->public_id,
                'status' => $attempt->status,
                'last_error' => $attempt->last_error,
            ],
            'queue_item' => $queueItem ? [
                'id' => $queueItem->id,
                'status' => $queueItem->status,
            ] : null,
            'invoice' => $invoice ? [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => $invoice->status,
                'receipt_url' => $isComplete ? route('invoice.pdf', $invoice->id) : null,
            ] : null,
            'payment' => $payment ? [
                'id' => $payment->id,
                'status' => $payment->status,
                'amount' => (float) $payment->amount,
                'tip_amount' => (float) $payment->tip_amount,
                'charged_total' => (float) $payment->charged_total,
                'currency_code' => $payment->currency_code,
            ] : null,
        ];
    }
}
