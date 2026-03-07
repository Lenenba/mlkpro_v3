<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Invoices\CreateInvoicePaymentAction;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\ActionEmailNotification;
use App\Services\StripeInvoiceService;
use App\Services\TenantPaymentMethodGuardService;
use App\Support\NotificationDispatcher;
use App\Support\TenantPaymentMethodsResolver;
use App\Support\TipAssigneeResolver;
use App\Support\TipCalculator;
use App\Support\TipSettingsResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PortalInvoiceController extends Controller
{
    private function portalCustomer(Request $request): Customer
    {
        $customer = $request->user()?->customerProfile;
        if (! $customer) {
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
            'payments.tipAssignee:id,name',
        ]);

        $owner = User::find($invoice->user_id);

        return Inertia::render('Portal/InvoiceShow', [
            'invoice' => $invoice,
            'company' => [
                'name' => $owner?->company_name ?: config('app.name'),
                'logo_url' => $owner?->company_logo_url,
            ],
            'paymentMethodSettings' => TenantPaymentMethodsResolver::forAccountId((int) $invoice->user_id),
        ]);
    }

    public function storePayment(Request $request, Invoice $invoice)
    {
        $customer = $this->portalCustomer($request);
        [$canPay, $message] = $this->resolvePaymentAvailability($invoice, $customer);
        if (! $canPay) {
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
            'tip_enabled' => 'nullable|boolean',
            'tip_mode' => ['nullable', Rule::in(['none', 'percent', 'fixed'])],
            'tip_percent' => 'nullable|numeric|min:0',
            'tip_amount' => 'nullable|numeric|min:0',
            'method' => 'nullable|string|max:50',
            'reference' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:255',
        ]);

        $methodDecision = app(TenantPaymentMethodGuardService::class)->evaluate(
            (int) $invoice->user_id,
            $validated['method'] ?? null,
            'invoice_portal'
        );
        if (! $methodDecision['allowed']) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'code' => TenantPaymentMethodGuardService::ERROR_CODE,
                    'message' => TenantPaymentMethodGuardService::ERROR_MESSAGE,
                ], 422);
            }

            return redirect()->back()->withErrors([
                'method' => TenantPaymentMethodGuardService::ERROR_MESSAGE,
                'code' => TenantPaymentMethodGuardService::ERROR_CODE,
            ]);
        }

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

        $result = app(CreateInvoicePaymentAction::class)->execute(
            $invoice,
            $validated,
            (string) ($methodDecision['canonical_method'] ?? 'cash'),
            $request->user(),
            (int) $invoice->user_id,
            'Payment recorded by client'
        );

        $payment = $result['payment'];
        $invoice = $result['invoice'];
        $isCashPayment = $result['is_cash_payment'];

        $owner = User::find($invoice->user_id);
        if ($owner && $owner->email) {
            $customerLabel = $customer->company_name
                ?: trim(($customer->first_name ?? '').' '.($customer->last_name ?? ''));
            $tipAmount = (float) ($payment->tip_amount ?? 0);
            $notificationTitle = $isCashPayment
                ? 'Cash payment pending collection'
                : 'Payment received from client';
            $notificationMessage = $isCashPayment
                ? ($customerLabel
                    ? $customerLabel.' recorded a cash payment pending collection.'
                    : 'A client recorded a cash payment pending collection.')
                : ($customerLabel
                    ? $customerLabel.' recorded a payment.'
                    : 'A client recorded a payment.');

            $details = [
                ['label' => 'Invoice', 'value' => $invoice->number ?? $invoice->id],
                ['label' => 'Amount', 'value' => '$'.number_format((float) $payment->amount, 2)],
            ];
            if ($tipAmount > 0) {
                $details[] = ['label' => 'Tip', 'value' => '$'.number_format($tipAmount, 2)];
                $details[] = ['label' => 'Total charged', 'value' => '$'.number_format((float) $payment->amount + $tipAmount, 2)];
            }
            $details[] = ['label' => 'Balance due', 'value' => '$'.number_format((float) $invoice->balance_due, 2)];

            NotificationDispatcher::send($owner, new ActionEmailNotification(
                $notificationTitle,
                $notificationMessage,
                $details,
                route('invoice.show', $invoice->id),
                'View invoice',
                $notificationTitle
            ), [
                'invoice_id' => $invoice->id,
                'payment_id' => $payment->id,
            ]);
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $result['message'],
                'invoice' => [
                    'id' => $invoice->id,
                    'status' => $invoice->status,
                    'total' => $invoice->total,
                    'balance_due' => $invoice->balance_due,
                ],
                'payment' => [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'tip_amount' => $payment->tip_amount,
                    'method' => $payment->method,
                    'paid_at' => $payment->paid_at,
                ],
            ]);
        }

        return redirect()->back()->with('success', $result['message']);
    }

    public function createStripeCheckout(Request $request, Invoice $invoice)
    {
        $customer = $this->portalCustomer($request);
        [$canPay, $message] = $this->resolvePaymentAvailability($invoice, $customer);
        if (! $canPay) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => $message,
                ], 422);
            }

            return redirect()->back()->withErrors([
                'status' => $message,
            ]);
        }

        $methodDecision = app(TenantPaymentMethodGuardService::class)->evaluate(
            (int) $invoice->user_id,
            'stripe',
            'invoice_portal'
        );
        if (! $methodDecision['allowed']) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'code' => TenantPaymentMethodGuardService::ERROR_CODE,
                    'message' => TenantPaymentMethodGuardService::ERROR_MESSAGE,
                ], 422);
            }

            return redirect()->back()->withErrors([
                'method' => TenantPaymentMethodGuardService::ERROR_MESSAGE,
                'code' => TenantPaymentMethodGuardService::ERROR_CODE,
            ]);
        }

        $validated = $request->validate([
            'amount' => 'nullable|numeric|min:0.01',
            'tip_enabled' => 'nullable|boolean',
            'tip_mode' => ['nullable', Rule::in(['none', 'percent', 'fixed'])],
            'tip_percent' => 'nullable|numeric|min:0',
            'tip_amount' => 'nullable|numeric|min:0',
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
        $baseAmount = $amount !== null ? $amount : (float) $invoice->balance_due;
        $tipSettings = TipSettingsResolver::forAccountId((int) $invoice->user_id);
        $tip = TipCalculator::resolve($baseAmount, $validated, $tipSettings);
        $tip['tip_assignee_user_id'] = TipAssigneeResolver::resolveForInvoice($invoice);

        $stripeService = app(StripeInvoiceService::class);
        if (! $stripeService->isConfigured()) {
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
        $successUrl .= (str_contains($successUrl, '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = URL::route('dashboard', ['stripe' => 'cancel', 'invoice' => $invoice->id]);

        $session = $stripeService->createCheckoutSession($invoice, $successUrl, $cancelUrl, $amount, $tip);
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
