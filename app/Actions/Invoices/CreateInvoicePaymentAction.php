<?php

namespace App\Actions\Invoices;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Work;
use App\Services\TipAllocationService;
use App\Support\TipAssigneeResolver;
use App\Support\TipCalculator;
use App\Support\TipSettingsResolver;

class CreateInvoicePaymentAction
{
    public function execute(
        Invoice $invoice,
        array $attributes,
        string $method,
        mixed $activityActor = null,
        ?int $paymentUserId = null,
        ?string $createdDescription = null
    ): array {
        $amount = (float) $attributes['amount'];
        $tipSettings = TipSettingsResolver::forAccountId((int) $invoice->user_id);
        $tip = TipCalculator::resolve($amount, $attributes, $tipSettings);
        $tipAssigneeUserId = TipAssigneeResolver::resolveForInvoice($invoice);
        $isCashPayment = strtolower(trim($method)) === 'cash';
        $paymentStatus = $isCashPayment
            ? Payment::STATUS_PENDING
            : ($attributes['status'] ?? Payment::STATUS_COMPLETED);
        $paidAt = $isCashPayment
            ? null
            : ($attributes['paid_at'] ?? now());

        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'user_id' => $paymentUserId ?? $invoice->user_id,
            'amount' => $amount,
            'tip_amount' => $tip['tip_amount'],
            'tip_type' => $tip['tip_type'],
            'tip_percent' => $tip['tip_percent'],
            'tip_base_amount' => $tip['tip_base_amount'],
            'charged_total' => $tip['charged_total'],
            'tip_assignee_user_id' => $tip['tip_amount'] > 0 ? $tipAssigneeUserId : null,
            'method' => $method,
            'status' => $paymentStatus,
            'reference' => $attributes['reference'] ?? null,
            'notes' => $attributes['notes'] ?? null,
            'paid_at' => $paidAt,
        ]);

        app(TipAllocationService::class)->syncForPayment($payment);

        $previousStatus = $invoice->status;
        $invoice->refreshPaymentStatus();

        ActivityLog::record($activityActor, $payment, 'created', [
            'invoice_id' => $invoice->id,
            'amount' => $payment->amount,
            'tip_amount' => $payment->tip_amount,
            'tip_type' => $payment->tip_type,
            'tip_percent' => $payment->tip_percent,
            'charged_total' => $payment->charged_total,
            'tip_assignee_user_id' => $payment->tip_assignee_user_id,
            'method' => $payment->method,
            'status' => $payment->status,
        ], $createdDescription ?: 'Payment recorded');

        if ($previousStatus !== $invoice->status) {
            ActivityLog::record($activityActor, $invoice, 'status_changed', [
                'from' => $previousStatus,
                'to' => $invoice->status,
            ], 'Invoice status updated');
        }

        if ($invoice->status === 'paid' && $invoice->work) {
            $invoice->work->status = Work::STATUS_CLOSED;
            $invoice->work->save();
        }

        return [
            'payment' => $payment,
            'invoice' => $invoice,
            'previous_status' => $previousStatus,
            'is_cash_payment' => $isCashPayment,
            'message' => $isCashPayment
                ? 'Cash payment recorded as pending collection.'
                : 'Payment recorded successfully.',
        ];
    }
}
