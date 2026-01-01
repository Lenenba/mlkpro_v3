<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Models\Work;
use App\Notifications\ActionEmailNotification;
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

        $owner = User::find($invoice->user_id);
        $customer = $invoice->customer;

        $canPay = true;
        $paymentMessage = null;
        if ($invoice->status === 'void' || $invoice->status === 'draft') {
            $canPay = false;
            $paymentMessage = 'This invoice cannot be paid.';
        } elseif ($invoice->balance_due <= 0) {
            $canPay = false;
            $paymentMessage = 'This invoice is already paid.';
        } elseif ($customer && $customer->auto_validate_invoices) {
            $canPay = false;
            $paymentMessage = 'Invoice actions are handled by the company.';
        }

        $expiresAt = $this->resolveExpiry($request);
        $paymentUrl = URL::temporarySignedRoute(
            'public.invoices.pay',
            $expiresAt,
            ['invoice' => $invoice->id]
        );

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
        ]);
    }

    public function storePayment(Request $request, Invoice $invoice)
    {
        $invoice->load('customer');
        $customer = $invoice->customer;

        if ($invoice->status === 'void' || $invoice->status === 'draft') {
            return redirect()->back()->withErrors([
                'status' => 'This invoice cannot be paid.',
            ]);
        }

        if ($invoice->balance_due <= 0) {
            return redirect()->back()->withErrors([
                'status' => 'This invoice is already paid.',
            ]);
        }

        if ($customer && $customer->auto_validate_invoices) {
            return redirect()->back()->withErrors([
                'status' => 'Invoice actions are handled by the company.',
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

            $owner->notify(new ActionEmailNotification(
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
            ));
        }

        return redirect()->back()->with('success', 'Payment recorded successfully.');
    }

    private function resolveExpiry(Request $request): Carbon
    {
        $expires = $request->query('expires');
        if (is_numeric($expires)) {
            return Carbon::createFromTimestamp((int) $expires);
        }

        return now()->addDays(self::LINK_TTL_DAYS);
    }
}
