<?php

namespace App\Http\Controllers;

use App\Actions\Invoices\CreateInvoicePaymentAction;
use App\Http\Requests\Payments\ReverseTipRequest;
use App\Http\Requests\Payments\StoreInvoicePaymentRequest;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use App\Services\OfferPackages\CustomerPackageService;
use App\Services\SalePaymentService;
use App\Services\TenantPaymentMethodGuardService;
use App\Services\TipAllocationService;
use App\Support\TipSettingsResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    /**
     * Store a payment for an invoice.
     */
    public function store(StoreInvoicePaymentRequest $request, Invoice $invoice)
    {
        if ($invoice->user_id !== Auth::id()) {
            abort(403);
        }

        $validated = $request->validated();

        $methodDecision = app(TenantPaymentMethodGuardService::class)->evaluate(
            (int) $invoice->user_id,
            $validated['method'] ?? null,
            'invoice_manual'
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

        $result = app(CreateInvoicePaymentAction::class)->execute(
            $invoice,
            $validated,
            (string) ($methodDecision['canonical_method'] ?? 'cash'),
            $request->user(),
            (int) $request->user()->id,
            'Payment recorded'
        );

        $payment = $result['payment'];
        $invoice = $result['invoice'];
        $successMessage = $result['message'];

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
        if (! $actor) {
            abort(401);
        }

        $payment->loadMissing([
            'invoice:id,user_id,status,work_id,total',
            'sale:id,user_id,status,fulfillment_status,total,paid_at,payment_provider',
        ]);

        $accountId = $this->resolvePaymentAccountId($payment);
        if (! $accountId || $actor->accountOwnerId() !== $accountId) {
            abort(403);
        }

        $this->ensureCashSettlementAccess($actor, $payment, $accountId);

        return DB::transaction(function () use ($request, $payment, $actor) {
            $invoice = $payment->invoice_id
                ? Invoice::query()->whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail()
                : null;
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($invoice) {
                $payment->setRelation('invoice', $invoice);
            }

            $method = strtolower((string) ($payment->method ?? ''));
            if ($method !== 'cash' && (! $invoice || ! in_array($method, ['bank_transfer', 'check'], true))) {
                return $this->rejectMarkPaid($request, 'Only manual payments can be marked as paid.');
            }

            if ($payment->status !== Payment::STATUS_PENDING) {
                return $this->rejectMarkPaid($request, 'This payment is not pending.');
            }

            if ($invoice && in_array($invoice->status, ['draft', 'void'], true)) {
                return $this->rejectMarkPaid($request, __('public.invoice.messages.cannot_pay'));
            }

            if ($invoice && (float) $payment->amount > (float) $invoice->balance_due) {
                return $this->rejectMarkPaid($request, __('public.invoice.messages.amount_exceeds_balance_due'));
            }

            $payment->forceFill([
                'status' => Payment::STATUS_PAID,
                'paid_at' => now(),
            ])->save();

            $this->refreshPaymentContextAfterSettlement($payment, $actor);

            ActivityLog::record($actor, $payment, $method === 'cash' ? 'cash_marked_paid' : 'payment_marked_paid', [
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'sale_id' => $payment->sale_id,
                'amount' => (float) $payment->amount,
                'method' => $payment->method,
                'status' => $payment->status,
                'marked_paid_at' => $payment->paid_at?->toIso8601String(),
                'actor_id' => $actor->id,
            ], 'Manual payment marked as paid');

            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Payment marked as paid.',
                    'payment' => $payment->fresh(),
                ]);
            }

            return redirect()->back()->with('success', 'Payment marked as paid.');
        });
    }

    public function reverseTip(ReverseTipRequest $request, Payment $payment)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $this->ensureTipReversalAccess($user, $payment);

        $validated = $request->validated();

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
        if (! in_array($rule, ['prorata', 'manual'], true)) {
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

        $payment->forceFill([
            'tip_reversed_amount' => $newTotalReversed,
            'tip_reversed_at' => $newTotalReversed > 0 ? now() : null,
            'tip_reversal_rule' => $rule,
            'tip_reversal_reason' => $validated['reason'] ?? null,
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

        if (! $isOwner && ! $isTeamAdmin) {
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

        if (! $payment->user_id) {
            return null;
        }

        $owner = User::query()->find($payment->user_id);
        if (! $owner) {
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
            if (! $isOwner && ! $isTeamAdmin && ! $canManageSales) {
                abort(403);
            }

            return;
        }

        if (! $isOwner && ! $isTeamAdmin) {
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

            if ($invoice->status === 'paid') {
                app(CustomerPackageService::class)->fulfillPaidInvoice($invoice, $actor);
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
