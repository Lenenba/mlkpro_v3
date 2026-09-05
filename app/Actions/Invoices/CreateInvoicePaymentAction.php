<?php

namespace App\Actions\Invoices;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Models\Work;
use App\Services\OfferPackages\CustomerPackageService;
use App\Services\TipAllocationService;
use App\Support\TipAssigneeResolver;
use App\Support\TipCalculator;
use App\Support\TipSettingsResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateInvoicePaymentAction
{
    public function execute(
        Invoice $invoice,
        array $attributes,
        string $method,
        mixed $activityActor = null,
        ?int $paymentUserId = null,
        ?string $createdDescription = null,
        bool $clientInitiated = false,
    ): array {
        if ($clientInitiated && in_array($method, ['card', 'stripe'], true)) {
            throw ValidationException::withMessages([
                'method' => __('public.invoice.messages.card_requires_checkout'),
            ]);
        }

        return DB::transaction(function () use ($invoice, $attributes, $method, $activityActor, $paymentUserId, $createdDescription, $clientInitiated): array {
            $lockedInvoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $key = trim((string) ($attributes['idempotency_key'] ?? ''));
            $key = $key !== '' ? hash('sha256', $key) : null;
            $fingerprint = $this->requestFingerprint($attributes, $method, $activityActor, $clientInitiated);

            if ($key !== null) {
                $existing = $lockedInvoice->payments()->where('idempotency_key', $key)->first();
                if ($existing) {
                    if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => __('public.invoice.messages.idempotency_conflict'),
                        ]);
                    }

                    return $this->result($existing, $lockedInvoice, $lockedInvoice->status, true);
                }
            }

            if (in_array($lockedInvoice->status, ['draft', 'void'], true)) {
                throw ValidationException::withMessages([
                    'status' => __('public.invoice.messages.cannot_pay'),
                ]);
            }

            $amount = round((float) $attributes['amount'], 2);
            if ($amount <= 0 || $amount > (float) $lockedInvoice->balance_due) {
                throw ValidationException::withMessages([
                    'amount' => __('public.invoice.messages.amount_exceeds_balance_due'),
                ]);
            }

            return $this->createPayment($lockedInvoice, $attributes, $method, $activityActor, $paymentUserId, $createdDescription, $clientInitiated, $key, $fingerprint);
        });
    }

    private function createPayment(
        Invoice $invoice,
        array $attributes,
        string $method,
        mixed $activityActor,
        ?int $paymentUserId,
        ?string $createdDescription,
        bool $clientInitiated,
        ?string $idempotencyKey,
        string $requestFingerprint,
    ): array {
        $amount = round((float) $attributes['amount'], 2);
        $tipSettings = TipSettingsResolver::forAccountId((int) $invoice->user_id);
        $tip = TipCalculator::resolve($amount, $attributes, $tipSettings);
        $tipAssigneeUserId = TipAssigneeResolver::resolveForInvoice($invoice);
        $isCashPayment = strtolower(trim($method)) === 'cash';
        $paymentStatus = $isCashPayment || $clientInitiated
            ? Payment::STATUS_PENDING
            : ($attributes['status'] ?? Payment::STATUS_COMPLETED);
        $paidAt = $paymentStatus === Payment::STATUS_PENDING
            ? null
            : ($attributes['paid_at'] ?? now());

        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'user_id' => $paymentUserId ?? $invoice->user_id,
            'amount' => $amount,
            'currency_code' => $invoice->currency_code,
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
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => $requestFingerprint,
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

        if ($invoice->status === 'paid') {
            app(CustomerPackageService::class)->fulfillPaidInvoice(
                $invoice,
                $activityActor instanceof User ? $activityActor : null
            );
        }

        return $this->result($payment, $invoice, $previousStatus, false);
    }

    private function result(Payment $payment, Invoice $invoice, string $previousStatus, bool $replayed): array
    {
        $isCashPayment = $payment->method === 'cash';
        $isPending = $payment->status === Payment::STATUS_PENDING;

        return [
            'payment' => $payment,
            'invoice' => $invoice,
            'previous_status' => $previousStatus,
            'is_cash_payment' => $isCashPayment,
            'pending_confirmation' => $isPending,
            'replayed' => $replayed,
            'message' => $isPending
                ? ($isCashPayment
                    ? 'Cash payment recorded as pending collection.'
                    : __('public.invoice.messages.payment_pending_confirmation'))
                : 'Payment recorded successfully.',
        ];
    }

    private function requestFingerprint(array $attributes, string $method, mixed $actor, bool $clientInitiated): string
    {
        return hash('sha256', json_encode([
            'amount' => round((float) $attributes['amount'], 2),
            'method' => $method,
            'status' => $attributes['status'] ?? null,
            'tip_enabled' => (bool) ($attributes['tip_enabled'] ?? false),
            'tip_mode' => $attributes['tip_mode'] ?? 'none',
            'tip_percent' => isset($attributes['tip_percent']) ? (float) $attributes['tip_percent'] : null,
            'tip_amount' => (float) ($attributes['tip_amount'] ?? 0),
            'reference' => (string) ($attributes['reference'] ?? ''),
            'notes' => (string) ($attributes['notes'] ?? ''),
            'paid_at' => ! empty($attributes['paid_at']) ? Carbon::parse($attributes['paid_at'])->toIso8601String() : null,
            'actor_id' => $actor instanceof User ? $actor->id : null,
            'client_initiated' => $clientInitiated,
        ], JSON_THROW_ON_ERROR));
    }
}
