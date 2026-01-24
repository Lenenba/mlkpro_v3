<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Models\Work;
use App\Notifications\ActionEmailNotification;
use App\Support\NotificationDispatcher;
use App\Services\StripeInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

class PortalInvoiceController extends Controller
{
    private function portalCustomer(Request $request): Customer
    {
        $customer = $request->user()?->customerProfile;
        if (!$customer) {
            abort(403);
        }

        return $customer;
    }

    public function show(Request $request, Invoice $invoice)
    {
        $customer = $this->portalCustomer($request);
        if ($invoice->customer_id !== $customer->id) {
            abort(403);
        }

        $invoice->load([
            'customer.properties',
            'items',
            'work.products',
            'work.quote.property',
            'payments',
        ]);

        $owner = User::find($invoice->user_id);

        return Inertia::render('Portal/InvoiceShow', [
            'invoice' => $invoice,
            'company' => [
                'name' => $owner?->company_name ?: config('app.name'),
                'logo_url' => $owner?->company_logo_url,
            ],
        ]);
    }

    public function storePayment(Request $request, Invoice $invoice)
    {
        $customer = $this->portalCustomer($request);
        [$canPay, $message] = $this->resolvePaymentAvailability($invoice, $customer);
        if (!$canPay) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => $message,
                ], 422);
            }

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

        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'user_id' => $invoice->user_id,
            'amount' => $validated['amount'],
            'method' => $validated['method'] ?? null,
            'status' => 'completed',
            'reference' => $validated['reference'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'paid_at' => now(),
        ]);

        $previousStatus = $invoice->status;
        $invoice->refreshPaymentStatus();

        ActivityLog::record($request->user(), $payment, 'created', [
            'invoice_id' => $invoice->id,
            'amount' => $payment->amount,
            'method' => $payment->method,
        ], 'Payment recorded by client');

        if ($previousStatus !== $invoice->status) {
            ActivityLog::record($request->user(), $invoice, 'status_changed', [
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
            $customerLabel = $customer->company_name
                ?: trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));

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

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Payment recorded successfully.',
                'invoice' => [
                    'id' => $invoice->id,
                    'status' => $invoice->status,
                    'total' => $invoice->total,
                    'balance_due' => $invoice->balance_due,
                ],
                'payment' => [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'method' => $payment->method,
                    'paid_at' => $payment->paid_at,
                ],
            ]);
        }

        return redirect()->back()->with('success', 'Payment recorded successfully.');
    }

    public function createStripeCheckout(Request $request, Invoice $invoice)
    {
        $customer = $this->portalCustomer($request);
        [$canPay, $message] = $this->resolvePaymentAvailability($invoice, $customer);
        if (!$canPay) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => $message,
                ], 422);
            }

            return redirect()->back()->withErrors([
                'status' => $message,
            ]);
        }

        $validated = $request->validate([
            'amount' => 'nullable|numeric|min:0.01',
        ]);

        $amount = null;
        if (isset($validated['amount'])) {
            $amount = (float) $validated['amount'];
            if ($amount > (float) $invoice->balance_due) {
                if ($this->shouldReturnJson($request)) {
                    return response()->json([
                        'message' => 'Amount exceeds the balance due.',
                    ], 422);
                }

                return redirect()->back()->withErrors([
                    'amount' => 'Amount exceeds the balance due.',
                ]);
            }
        }

        $stripeService = app(StripeInvoiceService::class);
        if (!$stripeService->isConfigured()) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Stripe is not configured.',
                ], 422);
            }

            return redirect()->back()->withErrors([
                'status' => 'Stripe is not configured.',
            ]);
        }

        $successUrl = URL::route('dashboard', ['stripe' => 'success', 'invoice' => $invoice->id]);
        $successUrl .= (str_contains($successUrl, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = URL::route('dashboard', ['stripe' => 'cancel', 'invoice' => $invoice->id]);

        $session = $stripeService->createCheckoutSession($invoice, $successUrl, $cancelUrl, $amount);
        if (empty($session['url'])) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Unable to start Stripe checkout.',
                ], 422);
            }

            return redirect()->back()->withErrors([
                'status' => 'Unable to start Stripe checkout.',
            ]);
        }

        if ($request->header('X-Inertia')) {
            return Inertia::location($session['url']);
        }

        return redirect()->away($session['url']);
    }

    private function resolvePaymentAvailability(Invoice $invoice, Customer $customer): array
    {
        if ($invoice->customer_id !== $customer->id) {
            abort(403);
        }

        if ($customer->auto_validate_invoices) {
            return [false, 'Invoice actions are handled by the company.'];
        }

        if ($invoice->status === 'void' || $invoice->status === 'draft') {
            return [false, 'This invoice cannot be paid.'];
        }

        if ($invoice->balance_due <= 0) {
            return [false, 'This invoice is already paid.'];
        }

        return [true, null];
    }
}
