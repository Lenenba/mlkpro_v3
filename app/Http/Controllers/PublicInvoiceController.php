<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Models\Work;
use App\Notifications\ActionEmailNotification;
use App\Support\NotificationDispatcher;
use App\Services\StripeInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class PublicInvoiceController extends Controller
{
    private const LINK_TTL_DAYS = 7;

    public function show(Request $request, Invoice $invoice): Response
    {
        $invoice->load([
            'customer:id,company_name,first_name,last_name,email,phone,auto_validate_invoices',
            'work:id,job_title,quote_id',
            'work.quote:id,property_id',
            'work.quote.property:id,street1,city,state,zip,country',
            'items',
            'payments',
        ]);

        $sessionId = $request->query('session_id');
        if ($sessionId) {
            $stripeService = app(StripeInvoiceService::class);
            if ($stripeService->isConfigured()) {
                $connectAccountId = $stripeService->resolveConnectedAccountId($invoice);
                $payment = $stripeService->syncFromCheckoutSessionId($sessionId, $connectAccountId);
                if ($payment && $payment->invoice_id === $invoice->id) {
                    $invoice->refresh();
                }
            }
        }

        $owner = User::find($invoice->user_id);
        $customer = $invoice->customer;

        [$canPay, $paymentMessage] = $this->resolvePaymentAvailability($invoice, $customer);

        $expiresAt = $this->resolveExpiry($request);
        $paymentUrl = URL::temporarySignedRoute(
            'public.invoices.pay',
            $expiresAt,
            ['invoice' => $invoice->id]
        );
        $stripeService = app(StripeInvoiceService::class);
        $stripeCheckoutUrl = null;
        if ($stripeService->isConfigured()) {
            $stripeCheckoutUrl = URL::temporarySignedRoute(
                'public.invoices.stripe',
                $expiresAt,
                ['invoice' => $invoice->id]
            );
        }

        return Inertia::render('Public/InvoicePay', [
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => $invoice->status,
                'total' => (float) $invoice->total,
                'amount_paid' => (float) $invoice->amount_paid,
                'balance_due' => (float) $invoice->balance_due,
                'created_at' => $invoice->created_at,
                'customer' => $customer ? [
                    'company_name' => $customer->company_name,
                    'first_name' => $customer->first_name,
                    'last_name' => $customer->last_name,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                ] : null,
                'work' => $invoice->work ? [
                    'job_title' => $invoice->work->job_title,
                ] : null,
            ],
            'company' => [
                'name' => $owner?->company_name ?: config('app.name'),
                'logo_url' => $owner?->company_logo_url,
            ],
            'allowPayment' => $canPay,
            'paymentMessage' => $paymentMessage,
            'paymentUrl' => $paymentUrl,
            'stripeCheckoutUrl' => $stripeCheckoutUrl,
        ]);
    }

    public function storePayment(Request $request, Invoice $invoice)
    {
        $invoice->load('customer');
        $customer = $invoice->customer;

        [$canPay, $message] = $this->resolvePaymentAvailability($invoice, $customer);
        if (!$canPay) {
            return redirect()->back()->withErrors([
                'status' => $message,
            ]);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'nullable|string|max:50',
            'reference' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:255',
        ]);

        $amount = (float) $validated['amount'];
        if ($amount > (float) $invoice->balance_due) {
            return redirect()->back()->withErrors([
                'amount' => 'Amount exceeds the balance due.',
            ]);
        }

        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'user_id' => $invoice->user_id,
            'amount' => $amount,
            'method' => $validated['method'] ?? null,
            'status' => 'completed',
            'reference' => $validated['reference'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'paid_at' => now(),
        ]);

        $previousStatus = $invoice->status;
        $invoice->refreshPaymentStatus();

        ActivityLog::record(null, $payment, 'created', [
            'invoice_id' => $invoice->id,
            'amount' => $payment->amount,
            'method' => $payment->method,
        ], 'Payment recorded by client (public link)');

        if ($previousStatus !== $invoice->status) {
            ActivityLog::record(null, $invoice, 'status_changed', [
                'from' => $previousStatus,
                'to' => $invoice->status,
            ], 'Invoice status updated');
        }

        if ($invoice->status === 'paid' && $invoice->work) {
            $invoice->work->status = Work::STATUS_CLOSED;
            $invoice->work->save();
        }

        $owner = User::find($invoice->user_id);
        if ($owner && $owner->email) {
            $customerLabel = $customer?->company_name
                ?: trim(($customer?->first_name ?? '') . ' ' . ($customer?->last_name ?? ''));

            NotificationDispatcher::send($owner, new ActionEmailNotification(
                'Payment received from client',
                $customerLabel ? $customerLabel . ' recorded a payment.' : 'A client recorded a payment.',
                [
                    ['label' => 'Invoice', 'value' => $invoice->number ?? $invoice->id],
                    ['label' => 'Amount', 'value' => '$' . number_format((float) $payment->amount, 2)],
                    ['label' => 'Balance due', 'value' => '$' . number_format((float) $invoice->balance_due, 2)],
                ],
                route('invoice.show', $invoice->id),
                'View invoice',
                'Payment received from client'
            ), [
                'invoice_id' => $invoice->id,
                'payment_id' => $payment->id,
            ]);
        }

        return redirect()->back()->with('success', 'Payment recorded successfully.');
    }

    public function createStripeCheckout(Request $request, Invoice $invoice)
    {
        $invoice->load('customer');
        $customer = $invoice->customer;

        [$canPay, $message] = $this->resolvePaymentAvailability($invoice, $customer);
        if (!$canPay) {
            return redirect()->back()->with('error', $message);
        }

        $stripeService = app(StripeInvoiceService::class);
        if (!$stripeService->isConfigured()) {
            return redirect()->back()->with('error', 'Stripe is not configured.');
        }

        $expiresAt = $this->resolveExpiry($request);
        $successUrl = URL::temporarySignedRoute(
            'public.invoices.show',
            $expiresAt,
            ['invoice' => $invoice->id, 'stripe' => 'success']
        );
        $successUrl .= (str_contains($successUrl, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = URL::temporarySignedRoute(
            'public.invoices.show',
            $expiresAt,
            ['invoice' => $invoice->id, 'stripe' => 'cancel']
        );

        $session = $stripeService->createCheckoutSession($invoice, $successUrl, $cancelUrl);
        if (empty($session['url'])) {
            return redirect()->back()->with('error', 'Unable to start Stripe checkout.');
        }

        if ($request->header('X-Inertia')) {
            return Inertia::location($session['url']);
        }

        return redirect()->away($session['url']);
    }

    private function resolveExpiry(Request $request): Carbon
    {
        $expires = $request->query('expires');
        if (is_numeric($expires)) {
            return Carbon::createFromTimestamp((int) $expires);
        }

        return now()->addDays(self::LINK_TTL_DAYS);
    }

    private function resolvePaymentAvailability(Invoice $invoice, $customer = null): array
    {
        if ($invoice->status === 'void' || $invoice->status === 'draft') {
            return [false, 'This invoice cannot be paid.'];
        }

        if ($invoice->balance_due <= 0) {
            return [false, 'This invoice is already paid.'];
        }

        if ($customer && $customer->auto_validate_invoices) {
            return [false, 'Invoice actions are handled by the company.'];
        }

        return [true, null];
    }
}
