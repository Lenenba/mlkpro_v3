<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ActivityLog;
use App\Models\Work;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    /**
     * Store a payment for an invoice.
     */
    public function store(Request $request, Invoice $invoice)
    {
        if ($invoice->user_id !== Auth::id()) {
            abort(403);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'nullable|string|max:50',
            'status' => ['nullable', Rule::in(['pending', 'completed', 'failed', 'refunded'])],
            'reference' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:255',
            'paid_at' => 'nullable|date',
        ]);

        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'user_id' => $request->user()->id,
            'amount' => $validated['amount'],
            'method' => $validated['method'] ?? null,
            'status' => $validated['status'] ?? 'completed',
            'reference' => $validated['reference'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'paid_at' => $validated['paid_at'] ?? now(),
        ]);

        $previousStatus = $invoice->status;
        $invoice->refreshPaymentStatus();

        ActivityLog::record($request->user(), $payment, 'created', [
            'invoice_id' => $invoice->id,
            'amount' => $payment->amount,
            'method' => $payment->method,
        ], 'Payment recorded');

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

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Payment recorded successfully.',
                'payment' => $payment->fresh(),
                'invoice' => $invoice->fresh(),
            ], 201);
        }

        return redirect()->back()->with('success', 'Payment recorded successfully.');
    }
}
