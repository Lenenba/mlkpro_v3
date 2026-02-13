<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ActivityLog;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use App\Services\SalePaymentService;
use App\Services\TenantPaymentMethodGuardService;
use App\Services\TipAllocationService;
use App\Support\TipCalculator;
use App\Support\TipAssigneeResolver;
use App\Support\TipSettingsResolver;
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
            'tip_enabled' => 'nullable|boolean',
            'tip_mode' => ['nullable', Rule::in(['none', 'percent', 'fixed'])],
            'tip_percent' => 'nullable|numeric|min:0',
            'tip_amount' => 'nullable|numeric|min:0',
            'method' => 'nullable|string|max:50',
            'status' => ['nullable', Rule::in([
                Payment::STATUS_PENDING,
                Payment::STATUS_PAID,
                Payment::STATUS_COMPLETED,
                Payment::STATUS_FAILED,
                Payment::STATUS_REFUNDED,
                Payment::STATUS_REVERSED,
            ])],
            'reference' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:255',
            'paid_at' => 'nullable|date',
        ]);

        $methodDecision = app(TenantPaymentMethodGuardService::class)->evaluate(
            (int) $invoice->user_id,
            $validated['method'] ?? null,
            'invoice_manual'
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
        $tipSettings = TipSettingsResolver::forAccountId((int) $invoice->user_id);
        $tip = TipCalculator::resolve($amount, $validated, $tipSettings);
        $tipAssigneeUserId = TipAssigneeResolver::resolveForInvoice($invoice);
        $isCashPayment = ($methodDecision['canonical_method'] ?? null) === 'cash';
        $paymentStatus = $isCashPayment
            ? Payment::STATUS_PENDING
            : ($validated['status'] ?? Payment::STATUS_COMPLETED);
        $paidAt = $isCashPayment
            ? null
            : ($validated['paid_at'] ?? now());

        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'user_id' => $request->user()->id,
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

        ActivityLog::record($request->user(), $payment, 'created', [
            'invoice_id' => $invoice->id,
            'amount' => $payment->amount,
            'tip_amount' => $payment->tip_amount,
            'tip_type' => $payment->tip_type,
            'tip_percent' => $payment->tip_percent,
            'charged_total' => $payment->charged_total,
            'tip_assignee_user_id' => $payment->tip_assignee_user_id,
            'method' => $payment->method,
            'status' => $payment->status,
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

        $successMessage = $isCashPayment
            ? 'Cash payment recorded as pending collection.'
            : 'Payment recorded successfully.';

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $successMessage,
                'payment' => $payment->fresh(),
                'invoice' => $invoice->fresh(),
            ], 201);
        }

        return redirect()->back()->with('success', $successMessage);
    }

    public function markAsPaid(Request $request, Payment $payment)
    {
        $actor = $request->user();
        if (!$actor) {
            abort(401);
        }

        $payment->loadMissing([
            'invoice:id,user_id,status,work_id,total',
            'sale:id,user_id,status,fulfillment_status,total,paid_at,payment_provider',
        ]);

        $accountId = $this->resolvePaymentAccountId($payment);
        if (!$accountId || $actor->accountOwnerId() !== $accountId) {
            abort(403);
        }

        $this->ensureCashSettlementAccess($actor, $payment, $accountId);

        if (strtolower((string) ($payment->method ?? '')) !== 'cash') {
            return $this->rejectMarkPaid($request, 'Only cash payments can be marked as paid.');
        }

        if ($payment->status !== Payment::STATUS_PENDING) {
            return $this->rejectMarkPaid($request, 'This payment is not pending.');
        }

        $payment->forceFill([
            'status' => Payment::STATUS_PAID,
            'paid_at' => now(),
        ])->save();

        $this->refreshPaymentContextAfterSettlement($payment, $actor);

        ActivityLog::record($actor, $payment, 'cash_marked_paid', [
            'payment_id' => $payment->id,
            'invoice_id' => $payment->invoice_id,
            'sale_id' => $payment->sale_id,
            'amount' => (float) $payment->amount,
            'method' => $payment->method,
            'status' => $payment->status,
            'marked_paid_at' => $payment->paid_at?->toIso8601String(),
            'actor_id' => $actor->id,
        ], 'Cash payment marked as paid');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Cash payment marked as paid.',
                'payment' => $payment->fresh(),
            ]);
        }

        return redirect()->back()->with('success', 'Cash payment marked as paid.');
    }

    public function reverseTip(Request $request, Payment $payment)
    {
        $user = $request->user();
        if (!$user) {
            abort(403);
        }

        $this->ensureTipReversalAccess($user, $payment);

        $validated = $request->validate([
            'amount' => 'nullable|numeric|min:0.01',
            'rule' => ['nullable', Rule::in(['prorata', 'manual'])],
            'reason' => 'nullable|string|max:255',
            'allocations' => 'nullable|array',
            'allocations.*.user_id' => 'required_with:allocations|integer|exists:users,id',
            'allocations.*.amount' => 'required_with:allocations|numeric|min:0.01',
        ]);

        $tipAmount = round((float) ($payment->tip_amount ?? 0), 2);
        $alreadyReversed = round((float) ($payment->tip_reversed_amount ?? 0), 2);
        $remaining = round(max(0, $tipAmount - $alreadyReversed), 2);
        if ($tipAmount <= 0 || $remaining <= 0) {
            return redirect()->back()->withErrors([
                'amount' => 'No remaining tip amount to reverse.',
            ]);
        }

        $amount = isset($validated['amount']) ? round((float) $validated['amount'], 2) : $remaining;
        if ($amount <= 0 || $amount > $remaining) {
            return redirect()->back()->withErrors([
                'amount' => 'Tip reversal amount exceeds the remaining tip.',
            ]);
        }

        $settings = TipSettingsResolver::forAccountId((int) $payment->user_id);
        $rule = (string) ($validated['rule'] ?? ($settings['partial_refund_rule'] ?? 'prorata'));
        if (!in_array($rule, ['prorata', 'manual'], true)) {
            $rule = 'prorata';
        }

        $manualAllocations = collect($validated['allocations'] ?? [])
            ->mapWithKeys(function (array $row) {
                $userId = (int) ($row['user_id'] ?? 0);
                $amount = (float) ($row['amount'] ?? 0);
                if ($userId <= 0 || $amount <= 0) {
                    return [];
                }

                return [$userId => round($amount, 2)];
            })
            ->all();

        $result = app(TipAllocationService::class)->reverseForPayment(
            $payment,
            $amount,
            $rule,
            $manualAllocations
        );

        $newTotalReversed = round((float) ($result['total_reversed'] ?? $alreadyReversed), 2);
        $isFullReversal = $tipAmount > 0 && $newTotalReversed >= $tipAmount;
        $nextStatus = $payment->status;
        if ($isFullReversal) {
            $nextStatus = Payment::STATUS_REFUNDED;
        } elseif ($newTotalReversed > 0 && in_array($payment->status, Payment::settledStatuses(), true)) {
            $nextStatus = Payment::STATUS_REVERSED;
        }

        $payment->forceFill([
            'tip_reversed_amount' => $newTotalReversed,
            'tip_reversed_at' => $newTotalReversed > 0 ? now() : null,
            'tip_reversal_rule' => $rule,
            'tip_reversal_reason' => $validated['reason'] ?? null,
            'status' => $nextStatus,
        ])->save();

        ActivityLog::record($user, $payment, 'tip_reversed', [
            'tip_amount' => $tipAmount,
            'previous_tip_reversed_amount' => $alreadyReversed,
            'reversed_delta' => (float) ($result['reversed_delta'] ?? 0),
            'tip_reversed_amount' => $newTotalReversed,
            'tip_reversal_rule' => $rule,
            'tip_reversal_reason' => $validated['reason'] ?? null,
            'status' => $payment->status,
        ], 'Tip reversal recorded');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Tip reversed successfully.',
                'payment' => $payment->fresh('tipAllocations'),
            ]);
        }

        return redirect()->back()->with('success', 'Tip reversed successfully.');
    }

    private function ensureTipReversalAccess($user, Payment $payment): void
    {
        $accountId = $user->accountOwnerId();
        if ((int) $payment->user_id !== (int) $accountId) {
            abort(403);
        }

        $membership = TeamMember::query()
            ->forAccount($accountId)
            ->active()
            ->where('user_id', $user->id)
            ->first();

        $isOwner = $user->id === $accountId && $user->isOwner();
        $isTeamAdmin = $membership && $membership->role === 'admin';

        if (!$isOwner && !$isTeamAdmin) {
            abort(403);
        }
    }

    private function rejectMarkPaid(Request $request, string $message)
    {
        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $message,
            ], 422);
        }

        return redirect()->back()->withErrors([
            'payment' => $message,
        ]);
    }

    private function resolvePaymentAccountId(Payment $payment): ?int
    {
        if ($payment->invoice) {
            return (int) $payment->invoice->user_id;
        }

        if ($payment->sale) {
            return (int) $payment->sale->user_id;
        }

        if (!$payment->user_id) {
            return null;
        }

        $owner = User::query()->find($payment->user_id);
        if (!$owner) {
            return null;
        }

        return (int) $owner->accountOwnerId();
    }

    private function ensureCashSettlementAccess(User $user, Payment $payment, int $accountId): void
    {
        $membership = TeamMember::query()
            ->forAccount($accountId)
            ->active()
            ->where('user_id', $user->id)
            ->first();

        $isOwner = $user->id === $accountId;
        $isTeamAdmin = (bool) ($membership && $membership->role === 'admin');
        $canManageSales = (bool) ($membership
            && ($membership->hasPermission('sales.manage') || $membership->hasPermission('sales.pos')));

        if ($payment->sale_id) {
            if (!$isOwner && !$isTeamAdmin && !$canManageSales) {
                abort(403);
            }

            return;
        }

        if (!$isOwner && !$isTeamAdmin) {
            abort(403);
        }
    }

    private function refreshPaymentContextAfterSettlement(Payment $payment, User $actor): void
    {
        if ($payment->invoice) {
            $invoice = $payment->invoice;
            $previousStatus = $invoice->status;
            $invoice->refreshPaymentStatus();

            if ($previousStatus !== $invoice->status) {
                ActivityLog::record($actor, $invoice, 'status_changed', [
                    'from' => $previousStatus,
                    'to' => $invoice->status,
                ], 'Invoice status updated');
            }

            if ($invoice->status === 'paid' && $invoice->work) {
                $invoice->work->status = Work::STATUS_CLOSED;
                $invoice->work->save();
            }
        }

        if ($payment->sale) {
            app(SalePaymentService::class)->refreshAfterManualPaymentSettlement(
                $payment->sale,
                $payment,
                $actor
            );
        }
    }
}
