<?php

namespace App\Http\Controllers\Billing;

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
            if (!is_array($decoded)) {
                return response()->json(['error' => 'Invalid payload'], 400);
            }
            $event = $decoded;
        }

        $type = is_array($event) ? ($event['type'] ?? null) : ($event->type ?? null);
        $data = is_array($event) ? ($event['data']['object'] ?? []) : ($event->data->object ?? null);

        if (in_array($type, [
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
        ], true)) {
            $subscription = is_array($data) ? $data : $data->toArray();
            app(StripeBillingService::class)->syncFromStripeSubscription($subscription);
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

            app(StripeInvoiceService::class)->recordPaymentFromCheckoutSession($session);
            app(StripeSaleService::class)->recordPaymentFromCheckoutSession($session);
        }

        if ($type === 'payment_intent.succeeded') {
            $intent = is_array($data) ? $data : $data->toArray();
            app(StripeInvoiceService::class)->recordPaymentFromPaymentIntent($intent);
            app(StripeSaleService::class)->recordPaymentFromPaymentIntent($intent);
        }

        return response()->json(['received' => true]);
    }
}
