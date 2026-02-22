<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LoyaltyPointLedger;
use App\Models\LoyaltyProgram;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoyaltyPointService
{
    private const DEFAULT_PROGRAM = [
        'is_enabled' => true,
        'points_per_currency_unit' => 1,
        'minimum_spend' => 0,
        'rounding_mode' => LoyaltyProgram::ROUND_FLOOR,
        'points_label' => 'points',
    ];

    public function awardForPayment(Payment $payment): void
    {
        if (!$this->isSettled($payment)) {
            return;
        }

        $customer = $this->resolveCustomer($payment);
        if (!$customer) {
            return;
        }

        $accountId = $this->resolveAccountId($payment, $customer);
        if (!$accountId) {
            return;
        }

        $program = $this->resolveProgramForAccount($accountId);

        if (!$program || !$program->is_enabled) {
            return;
        }

        $amount = round(max(0, (float) ($payment->amount ?? 0)), 2);
        if ($amount <= 0 || $amount < (float) $program->minimum_spend) {
            return;
        }

        $points = $this->calculatePoints($amount, (float) $program->points_per_currency_unit, (string) $program->rounding_mode);
        if ($points <= 0) {
            return;
        }

        DB::transaction(function () use ($payment, $customer, $accountId, $amount, $points): void {
            $ledger = LoyaltyPointLedger::query()->firstOrCreate(
                [
                    'payment_id' => $payment->id,
                    'event' => LoyaltyPointLedger::EVENT_ACCRUAL,
                ],
                [
                    'user_id' => $accountId,
                    'customer_id' => $customer->id,
                    'points' => $points,
                    'amount' => $amount,
                    'meta' => [
                        'payment_status' => $payment->status,
                    ],
                    'processed_at' => now(),
                ]
            );

            if (!$ledger->wasRecentlyCreated) {
                return;
            }

            $customerForUpdate = Customer::query()->lockForUpdate()->find($customer->id);
            if (!$customerForUpdate) {
                return;
            }

            $customerForUpdate->loyalty_points_balance = max(
                0,
                (int) ($customerForUpdate->loyalty_points_balance ?? 0) + $points
            );
            $customerForUpdate->save();
        });
    }

    public function resolveProgramForAccount(int $accountId, bool $createIfMissing = true): ?LoyaltyProgram
    {
        if ($accountId <= 0) {
            return null;
        }

        $query = LoyaltyProgram::query()->where('user_id', $accountId);
        if (!$createIfMissing) {
            return $query->first();
        }

        return $query->firstOrCreate(
            ['user_id' => $accountId],
            self::DEFAULT_PROGRAM
        );
    }

    public function calculateMaxRedeemablePoints(float $amount, LoyaltyProgram $program): int
    {
        $rate = (float) $program->points_per_currency_unit;
        if ($rate <= 0) {
            return 0;
        }

        return (int) floor(max(0, $amount) * $rate);
    }

    public function calculateRedeemAmount(int $points, LoyaltyProgram $program): float
    {
        $rate = (float) $program->points_per_currency_unit;
        if ($rate <= 0 || $points <= 0) {
            return 0.0;
        }

        return round($points / $rate, 2);
    }

    public function redeemForSale(Sale $sale, int $requestedPoints, float $maxAmount): array
    {
        if ($requestedPoints <= 0) {
            return [
                'points' => 0,
                'amount' => 0.0,
            ];
        }

        if (!$sale->customer_id) {
            throw ValidationException::withMessages([
                'loyalty_points_redeem' => 'Selectionnez un client pour utiliser ses points.',
            ]);
        }

        $program = $this->resolveProgramForAccount((int) $sale->user_id, true);
        if (!$program || !$program->is_enabled) {
            throw ValidationException::withMessages([
                'loyalty_points_redeem' => 'Le programme fidelite est desactive.',
            ]);
        }

        $rate = (float) $program->points_per_currency_unit;
        if ($rate <= 0) {
            throw ValidationException::withMessages([
                'loyalty_points_redeem' => 'Le taux de fidelite est invalide.',
            ]);
        }

        $maxAmount = round(max(0, $maxAmount), 2);
        $maxPointsByAmount = $this->calculateMaxRedeemablePoints($maxAmount, $program);
        if ($maxPointsByAmount <= 0) {
            throw ValidationException::withMessages([
                'loyalty_points_redeem' => 'Aucun point ne peut etre applique sur ce montant.',
            ]);
        }

        return DB::transaction(function () use ($sale, $requestedPoints, $maxPointsByAmount, $maxAmount, $program): array {
            $customer = Customer::query()->lockForUpdate()->find($sale->customer_id);
            if (!$customer || (int) $customer->user_id !== (int) $sale->user_id) {
                throw ValidationException::withMessages([
                    'customer_id' => 'Client invalide pour ce compte.',
                ]);
            }

            $availablePoints = max(0, (int) ($customer->loyalty_points_balance ?? 0));
            $allowedPoints = min($availablePoints, $maxPointsByAmount);
            if ($requestedPoints > $allowedPoints) {
                throw ValidationException::withMessages([
                    'loyalty_points_redeem' => "Points insuffisants ou montant depasse. Maximum autorise: {$allowedPoints}.",
                ]);
            }

            $redeemAmount = min($maxAmount, $this->calculateRedeemAmount($requestedPoints, $program));
            if ($redeemAmount <= 0) {
                throw ValidationException::withMessages([
                    'loyalty_points_redeem' => 'Montant de reduction invalide.',
                ]);
            }

            LoyaltyPointLedger::query()->create([
                'user_id' => $sale->user_id,
                'customer_id' => $customer->id,
                'payment_id' => null,
                'event' => LoyaltyPointLedger::EVENT_REDEMPTION,
                'points' => -1 * abs($requestedPoints),
                'amount' => $redeemAmount,
                'meta' => [
                    'sale_id' => $sale->id,
                    'sale_number' => $sale->number,
                    'source' => $sale->source,
                ],
                'processed_at' => now(),
            ]);

            $customer->loyalty_points_balance = max(0, $availablePoints - $requestedPoints);
            $customer->save();

            return [
                'points' => $requestedPoints,
                'amount' => $redeemAmount,
            ];
        });
    }

    public function releaseSaleRedemption(Sale $sale, string $reason = 'sale_canceled'): array
    {
        return DB::transaction(function () use ($sale, $reason): array {
            $saleForUpdate = Sale::query()->lockForUpdate()->find($sale->id);
            if (!$saleForUpdate) {
                return [
                    'points' => 0,
                    'amount' => 0.0,
                ];
            }

            $points = max(0, (int) ($saleForUpdate->loyalty_points_redeemed ?? 0));
            $amount = round(max(0, (float) ($saleForUpdate->loyalty_discount_total ?? 0)), 2);

            if ($points <= 0) {
                if ((int) $saleForUpdate->loyalty_points_redeemed !== 0 || (float) $saleForUpdate->loyalty_discount_total !== 0.0) {
                    $saleForUpdate->forceFill([
                        'loyalty_points_redeemed' => 0,
                        'loyalty_discount_total' => 0,
                    ])->save();
                }

                $sale->refresh();

                return [
                    'points' => 0,
                    'amount' => 0.0,
                ];
            }

            if (!$saleForUpdate->customer_id) {
                $saleForUpdate->forceFill([
                    'loyalty_points_redeemed' => 0,
                    'loyalty_discount_total' => 0,
                ])->save();

                $sale->refresh();

                return [
                    'points' => 0,
                    'amount' => 0.0,
                ];
            }

            $customer = $saleForUpdate->customer_id
                ? Customer::query()->lockForUpdate()->find($saleForUpdate->customer_id)
                : null;

            if ($customer && (int) $customer->user_id === (int) $saleForUpdate->user_id) {
                $customer->loyalty_points_balance = max(
                    0,
                    (int) ($customer->loyalty_points_balance ?? 0) + $points
                );
                $customer->save();
            }

            LoyaltyPointLedger::query()->create([
                'user_id' => $saleForUpdate->user_id,
                'customer_id' => $saleForUpdate->customer_id,
                'payment_id' => null,
                'event' => LoyaltyPointLedger::EVENT_REDEMPTION_REVERSAL,
                'points' => $points,
                'amount' => $amount,
                'meta' => [
                    'sale_id' => $saleForUpdate->id,
                    'sale_number' => $saleForUpdate->number,
                    'reason' => $reason,
                ],
                'processed_at' => now(),
            ]);

            $saleForUpdate->forceFill([
                'loyalty_points_redeemed' => 0,
                'loyalty_discount_total' => 0,
            ])->save();

            $sale->refresh();

            return [
                'points' => $points,
                'amount' => $amount,
            ];
        });
    }

    public function refundForPayment(Payment $payment): void
    {
        if ($payment->status !== Payment::STATUS_REFUNDED) {
            return;
        }

        $accrual = LoyaltyPointLedger::query()
            ->where('payment_id', $payment->id)
            ->where('event', LoyaltyPointLedger::EVENT_ACCRUAL)
            ->first();

        if (!$accrual || (int) $accrual->points <= 0) {
            return;
        }

        DB::transaction(function () use ($payment, $accrual): void {
            $refund = LoyaltyPointLedger::query()->firstOrCreate(
                [
                    'payment_id' => $payment->id,
                    'event' => LoyaltyPointLedger::EVENT_REFUND,
                ],
                [
                    'user_id' => $accrual->user_id,
                    'customer_id' => $accrual->customer_id,
                    'points' => -1 * abs((int) $accrual->points),
                    'amount' => (float) $accrual->amount,
                    'meta' => [
                        'payment_status' => $payment->status,
                    ],
                    'processed_at' => now(),
                ]
            );

            if (!$refund->wasRecentlyCreated) {
                return;
            }

            $customerForUpdate = Customer::query()->lockForUpdate()->find($accrual->customer_id);
            if (!$customerForUpdate) {
                return;
            }

            $customerForUpdate->loyalty_points_balance = max(
                0,
                (int) ($customerForUpdate->loyalty_points_balance ?? 0) + (int) $refund->points
            );
            $customerForUpdate->save();
        });
    }

    private function isSettled(Payment $payment): bool
    {
        return in_array($payment->status, Payment::settledStatuses(), true);
    }

    private function resolveCustomer(Payment $payment): ?Customer
    {
        if ($payment->customer_id) {
            return Customer::query()->find($payment->customer_id);
        }

        if ($payment->sale_id) {
            $sale = Sale::query()->select(['id', 'customer_id'])->find($payment->sale_id);
            if ($sale?->customer_id) {
                return Customer::query()->find($sale->customer_id);
            }
        }

        if ($payment->invoice_id) {
            $invoice = Invoice::query()->select(['id', 'customer_id'])->find($payment->invoice_id);
            if ($invoice?->customer_id) {
                return Customer::query()->find($invoice->customer_id);
            }
        }

        return null;
    }

    private function resolveAccountId(Payment $payment, Customer $customer): ?int
    {
        if ($customer->user_id) {
            return (int) $customer->user_id;
        }

        if ($payment->sale_id) {
            $saleUserId = Sale::query()->whereKey($payment->sale_id)->value('user_id');
            if ($saleUserId) {
                return (int) $saleUserId;
            }
        }

        if ($payment->invoice_id) {
            $invoiceUserId = Invoice::query()->whereKey($payment->invoice_id)->value('user_id');
            if ($invoiceUserId) {
                return (int) $invoiceUserId;
            }
        }

        return $payment->user_id ? (int) $payment->user_id : null;
    }

    private function calculatePoints(float $amount, float $pointsPerCurrencyUnit, string $roundingMode): int
    {
        $raw = $amount * max(0, $pointsPerCurrencyUnit);

        return match ($roundingMode) {
            LoyaltyProgram::ROUND_CEIL => (int) ceil($raw),
            LoyaltyProgram::ROUND_ROUND => (int) round($raw),
            default => (int) floor($raw),
        };
    }
}
