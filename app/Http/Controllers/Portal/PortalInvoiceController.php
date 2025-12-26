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
use Illuminate\Http\Request;

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

    public function storePayment(Request $request, Invoice $invoice)
    {
        $customer = $this->portalCustomer($request);
        if ($customer->auto_validate_invoices) {
            return redirect()->back()->withErrors([
                'status' => 'Invoice actions are handled by the company.',
            ]);
        }

        if ($invoice->customer_id !== $customer->id) {
            abort(403);
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
}
