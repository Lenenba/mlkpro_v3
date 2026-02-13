<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use App\Services\SalePaymentService;
use App\Services\TenantPaymentMethodGuardService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SalePaymentController extends Controller
{
    public function store(Request $request, Sale $sale)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        [$accountOwner, $canManage, $canPos] = $this->resolveSalesAccess($user);
        $accountId = $accountOwner->id;
        $canAccessAll = $canManage || $canPos;

        if ($sale->user_id !== $accountId) {
            abort(404);
        }
        if (!$canAccessAll && $sale->created_by_user_id !== $user->id) {
            abort(404);
        }

        if ($sale->status === Sale::STATUS_CANCELED) {
            throw ValidationException::withMessages([
                'payment' => 'Commande annulee.',
            ]);
        }
        if ($sale->status === Sale::STATUS_PAID) {
            throw ValidationException::withMessages([
                'payment' => 'Commande deja payee.',
            ]);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'nullable|string|max:50',
        ]);

        $methodDecision = app(TenantPaymentMethodGuardService::class)->evaluate(
            (int) $accountId,
            $validated['method'] ?? null,
            'sale_manual'
        );
        if (!$methodDecision['allowed']) {
            throw ValidationException::withMessages([
                'method' => TenantPaymentMethodGuardService::ERROR_MESSAGE,
                'code' => TenantPaymentMethodGuardService::ERROR_CODE,
            ]);
        }

        $sale->loadSum(['payments as payments_sum_amount' => fn($query) => $query->whereIn('status', Payment::settledStatuses())], 'amount');
        $balanceDue = $sale->balance_due;
        $amount = (float) $validated['amount'];
        if ($amount > $balanceDue) {
            throw ValidationException::withMessages([
                'amount' => 'Le montant depasse le solde a payer.',
            ]);
        }
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Aucun solde a payer.',
            ]);
        }

        $method = $methodDecision['canonical_method'] ?? 'cash';
        $updatedSale = app(SalePaymentService::class)->recordManualPayment($sale, $amount, $method, $user);
        $message = $method === 'cash'
            ? 'Paiement cash en attente d encaissement.'
            : 'Paiement enregistre.';

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $message,
                'sale' => $updatedSale,
            ]);
        }

        return redirect()
            ->back()
            ->with('success', $message);
    }

    private function resolveSalesAccess(User $user): array
    {
        $ownerId = $user->accountOwnerId();
        $owner = $ownerId === $user->id
            ? $user
            : User::query()->find($ownerId);

        if (!$owner || $owner->company_type !== 'products') {
            abort(403);
        }

        $canManage = $user->id === $owner->id;
        $canPos = $canManage;

        if (!$canManage) {
            $membership = $user->relationLoaded('teamMembership')
                ? $user->teamMembership
                : $user->teamMembership()->first();
            $canManage = $membership?->hasPermission('sales.manage') ?? false;
            $canPos = $membership?->hasPermission('sales.pos') ?? false;
            if (!$canManage && !$canPos) {
                abort(403);
            }
        }

        return [$owner, $canManage, $canPos];
    }
}
