<?php

namespace App\Http\Controllers\Billing;

use App\Exceptions\StripeQueueCheckoutVerificationException;
use App\Http\Controllers\Controller;
use App\Services\AssistantCreditService;
use App\Services\StripeBillingService;
use App\Services\StripeInvoiceService;
use App\Services\StripeSaleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');
        $secret = config('services.stripe.webhook_secret');

        if (! $secret && ! in_array((string) config('app.env'), ['local', 'testing'], true)) {
            Log::critical('Stripe webhook rejected because STRIPE_WEBHOOK_SECRET is not configured.');

            return response()->json(['error' => 'Stripe webhook is not configured'], 503);
        }

        if ($secret) {
            try {
                $event = Webhook::constructEvent($payload, $signature, $secret);
            } catch (SignatureVerificationException $exception) {
                Log::warning('Stripe webhook signature verification failed.', [
                    'message' => $exception->getMessage(),
                ]);

                return response()->json(['error' => 'Invalid signature'], 400);
            } catch (\UnexpectedValueException $exception) {
                Log::warning('Stripe webhook payload invalid.', [
                    'message' => $exception->getMessage(),
                ]);

                return response()->json(['error' => 'Invalid payload'], 400);
            }
        } else {
            $decoded = json_decode($payload, true);
            if (! is_array($decoded)) {
                return response()->json(['error' => 'Invalid payload'], 400);
            }
            $event = $decoded;
        }

        $type = is_array($event) ? ($event['type'] ?? null) : ($event->type ?? null);
        $data = is_array($event) ? ($event['data']['object'] ?? []) : ($event->data->object ?? null);
        $eventStripeAccountId = is_array($event) ? ($event['account'] ?? null) : ($event->account ?? null);

        if (in_array($type, [
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
        ], true)) {
            $subscription = is_array($data) ? $data : $data->toArray();
            app(StripeBillingService::class)->syncFromStripeSubscription($subscription);
        }

        if (in_array($type, [
            'checkout.session.expired',
            'checkout.session.async_payment_failed',
        ], true)) {
            $session = is_array($data) ? $data : $data->toArray();
            $terminalStatus = $type === 'checkout.session.expired'
                ? \App\Models\ReservationQueuePaymentAttempt::STATUS_EXPIRED
                : \App\Models\ReservationQueuePaymentAttempt::STATUS_FAILED;
            try {
                app(StripeInvoiceService::class)->closeQueueCheckoutAttemptFromStripe(
                    $session,
                    $terminalStatus,
                    is_string($eventStripeAccountId) ? $eventStripeAccountId : null
                );
            } catch (StripeQueueCheckoutVerificationException $exception) {
                Log::warning('Stripe queue Checkout terminal webhook rejected.', [
                    'event_type' => $type,
                    'stripe_account_id' => $eventStripeAccountId,
                    'message' => $exception->getMessage(),
                ]);

                return response()->json(['error' => 'Stripe queue payment verification failed'], 400);
            }
        }

        if (in_array($type, [
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
        ], true)) {
            $session = is_array($data) ? $data : $data->toArray();
            $creditPackSize = (int) config('services.stripe.ai_credit_pack', 0);
            if ($creditPackSize > 0
                && app(AssistantCreditService::class)->grantFromStripeSession($session, $creditPackSize)) {
                return response()->json(['received' => true]);
            }

            try {
                app(StripeInvoiceService::class)->recordPaymentFromCheckoutSession(
                    $session,
                    is_string($eventStripeAccountId) ? $eventStripeAccountId : null
                );
            } catch (StripeQueueCheckoutVerificationException $exception) {
                Log::warning('Stripe queue Checkout webhook rejected.', [
                    'event_type' => $type,
                    'stripe_account_id' => $eventStripeAccountId,
                    'message' => $exception->getMessage(),
                ]);

                return response()->json(['error' => 'Stripe queue payment verification failed'], 400);
            }
            app(StripeSaleService::class)->recordPaymentFromCheckoutSession($session);
        }

        if ($type === 'payment_intent.succeeded') {
            $intent = is_array($data) ? $data : $data->toArray();
            try {
                app(StripeInvoiceService::class)->recordPaymentFromPaymentIntent(
                    $intent,
                    is_string($eventStripeAccountId) ? $eventStripeAccountId : null
                );
            } catch (StripeQueueCheckoutVerificationException $exception) {
                Log::warning('Stripe queue payment intent webhook rejected.', [
                    'event_type' => $type,
                    'stripe_account_id' => $eventStripeAccountId,
                    'message' => $exception->getMessage(),
                ]);

                return response()->json(['error' => 'Stripe queue payment verification failed'], 400);
            }
            app(StripeSaleService::class)->recordPaymentFromPaymentIntent($intent);
        }

        return response()->json(['received' => true]);
    }
}
