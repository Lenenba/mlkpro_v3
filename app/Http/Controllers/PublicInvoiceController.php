<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Models\Work;
use App\Notifications\ActionEmailNotification;
use App\Support\NotificationDispatcher;
use App\Services\TenantPaymentMethodGuardService;
use App\Services\TipAllocationService;
use App\Support\TipCalculator;
use App\Support\TipAssigneeResolver;
use App\Support\TipSettingsResolver;
use App\Support\TenantPaymentMethodsResolver;
use App\Services\StripeInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
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
            'payments.tipAssignee:id,name',
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
        $tipSettings = TipSettingsResolver::forAccountId((int) $invoice->user_id);

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
                'payments' => $invoice->payments
                    ->sortByDesc(fn($payment) => $payment->paid_at ?? $payment->created_at)
                    ->take(10)
                    ->values()
                    ->map(function ($payment) {
                        $tipAmount = (float) ($payment->tip_amount ?? 0);
                        $chargedTotal = $payment->charged_total !== null
                            ? (float) $payment->charged_total
                            : round((float) ($payment->amount ?? 0) + $tipAmount, 2);

                        return [
                            'id' => $payment->id,
                            'amount' => (float) ($payment->amount ?? 0),
                            'tip_amount' => $tipAmount,
                            'tip_type' => $payment->tip_type,
                            'charged_total' => $chargedTotal,
                            'status' => $payment->status,
                            'method' => $payment->method,
                            'paid_at' => $payment->paid_at,
                            'tip_assignee_name' => $payment->tipAssignee?->name,
                        ];
                    }),
            ],
            'company' => [
                'name' => $owner?->company_name ?: config('app.name'),
                'logo_url' => $owner?->company_logo_url,
            ],
            'allowPayment' => $canPay,
            'paymentMessage' => $paymentMessage,
            'paymentUrl' => $paymentUrl,
            'stripeCheckoutUrl' => $stripeCheckoutUrl,
            'tips' => $tipSettings,
            'paymentMethodSettings' => TenantPaymentMethodsResolver::forAccountId((int) $invoice->user_id),
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
            'invoice_public'
        );
        if (!$methodDecision['allowed']) {
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
            return redirect()->back()->withErrors([
                'amount' => 'Amount exceeds the balance due.',
            ]);
        }
        $tipSettings = TipSettingsResolver::forAccountId((int) $invoice->user_id);
        $tip = TipCalculator::resolve($amount, $validated, $tipSettings);
        $tipAssigneeUserId = TipAssigneeResolver::resolveForInvoice($invoice);
        $isCashPayment = ($methodDecision['canonical_method'] ?? null) === 'cash';
        $paymentStatus = $isCashPayment ? Payment::STATUS_PENDING : Payment::STATUS_COMPLETED;
        $paidAt = $isCashPayment ? null : now();

        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'user_id' => $invoice->user_id,
            'amount' => $amount,
            'tip_amount' => $tip['tip_amount'],
            'tip_type' => $tip['tip_type'],
            'tip_percent' => $tip['tip_percent'],
            'tip_base_amount' => $tip['tip_base_amount'],
            'charged_total' => $tip['charged_total'],
            'tip_assignee_user_id' => $tip['tip_amount'] > 0 ? $tipAssigneeUserId : null,
            'method' => $methodDecision['canonical_method'],
            'status' => $paymentStatus,
            'reference' => $validated['reference'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'paid_at' => $paidAt,
        ]);

        app(TipAllocationService::class)->syncForPayment($payment);

        $previousStatus = $invoice->status;
        $invoice->refreshPaymentStatus();

        ActivityLog::record(null, $payment, 'created', [
            'invoice_id' => $invoice->id,
            'amount' => $payment->amount,
            'tip_amount' => $payment->tip_amount,
            'tip_type' => $payment->tip_type,
            'tip_percent' => $payment->tip_percent,
            'charged_total' => $payment->charged_total,
            'tip_assignee_user_id' => $payment->tip_assignee_user_id,
            'method' => $payment->method,
            'status' => $payment->status,
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
            $tipAmount = (float) ($payment->tip_amount ?? 0);
            $notificationTitle = $isCashPayment
                ? 'Cash payment pending collection'
                : 'Payment received from client';
            $notificationMessage = $isCashPayment
                ? ($customerLabel
                    ? $customerLabel . ' recorded a cash payment pending collection.'
                    : 'A client recorded a cash payment pending collection.')
                : ($customerLabel
                    ? $customerLabel . ' recorded a payment.'
                    : 'A client recorded a payment.');

            $details = [
                ['label' => 'Invoice', 'value' => $invoice->number ?? $invoice->id],
                ['label' => 'Amount', 'value' => '$' . number_format((float) $payment->amount, 2)],
            ];
            if ($tipAmount > 0) {
                $details[] = ['label' => 'Tip', 'value' => '$' . number_format($tipAmount, 2)];
                $details[] = ['label' => 'Total charged', 'value' => '$' . number_format((float) $payment->amount + $tipAmount, 2)];
            }
            $details[] = ['label' => 'Balance due', 'value' => '$' . number_format((float) $invoice->balance_due, 2)];

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

        $successMessage = $isCashPayment
            ? 'Cash payment recorded as pending collection.'
            : 'Payment recorded successfully.';

        return redirect()->back()->with('success', $successMessage);
    }

    public function createStripeCheckout(Request $request, Invoice $invoice)
    {
        $invoice->load('customer');
        $customer = $invoice->customer;

        [$canPay, $message] = $this->resolvePaymentAvailability($invoice, $customer);
        if (!$canPay) {
            return redirect()->back()->with('error', $message);
        }

        $methodDecision = app(TenantPaymentMethodGuardService::class)->evaluate(
            (int) $invoice->user_id,
            'stripe',
            'invoice_public'
        );
        if (!$methodDecision['allowed']) {
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

        $session = $stripeService->createCheckoutSession($invoice, $successUrl, $cancelUrl, $amount, $tip);
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
