<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentTipAllocation;
use App\Support\TipAssigneeResolver;
use App\Support\TipSettingsResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class TipAllocationService
{
    public const RULE_PRORATA = 'prorata';
    public const RULE_MANUAL = 'manual';

    private ?bool $supportsAllocations = null;

    public function syncForPayment(Payment $payment, ?string $strategy = null): void
    {
        if (!$this->supportsAllocations()) {
            return;
        }

        $tipAmount = $this->roundMoney((float) ($payment->tip_amount ?? 0));
        if ($tipAmount <= 0) {
            $payment->tipAllocations()->delete();
            return;
        }

        $settings = TipSettingsResolver::forAccountId((int) $payment->user_id);
        $strategy = $this->normalizeStrategy($strategy ?: (string) ($settings['allocation_strategy'] ?? 'primary'));
        $weights = $this->resolveWeights($payment, $strategy);
        if (empty($weights)) {
            $payment->tipAllocations()->delete();
            return;
        }

        $allocations = $this->buildTipAllocations($tipAmount, $weights);
        if (empty($allocations)) {
            $payment->tipAllocations()->delete();
            return;
        }

        $existing = $payment->tipAllocations()->get()->keyBy('user_id');
        $seenUserIds = [];

        foreach ($allocations as $userId => $values) {
            $seenUserIds[] = $userId;
            $existingModel = $existing->get($userId);
            $existingReversed = $existingModel ? (float) ($existingModel->reversed_amount ?? 0) : 0.0;

            PaymentTipAllocation::query()->updateOrCreate(
                [
                    'payment_id' => $payment->id,
                    'user_id' => $userId,
                ],
                [
                    'amount' => $values['amount'],
                    'percent' => $values['percent'],
                    'reversed_amount' => min($values['amount'], $existingReversed),
                ]
            );
        }

        $payment->tipAllocations()
            ->whereNotIn('user_id', $seenUserIds)
            ->delete();

        if (!$payment->tip_assignee_user_id) {
            $primaryUserId = array_key_first($weights);
            if ($primaryUserId) {
                $payment->forceFill(['tip_assignee_user_id' => $primaryUserId])->save();
            }
        }

        $tipReversedAmount = $this->roundMoney((float) ($payment->tip_reversed_amount ?? 0));
        if ($tipReversedAmount > 0) {
            $this->reverseForPayment($payment->fresh(), $tipReversedAmount, self::RULE_PRORATA, [], true);
        }
    }

    public function reverseForPayment(
        Payment $payment,
        float $reversalAmount,
        string $rule = self::RULE_PRORATA,
        array $manualAmounts = [],
        bool $replaceExisting = false
    ): array {
        if (!$this->supportsAllocations()) {
            return [
                'reversed_delta' => 0.0,
                'total_reversed' => $this->roundMoney((float) ($payment->tip_reversed_amount ?? 0)),
            ];
        }

        $payment->loadMissing('tipAllocations');
        if ($payment->tipAllocations->isEmpty()) {
            $this->syncForPayment($payment);
            $payment->load('tipAllocations');
        }

        if ($payment->tipAllocations->isEmpty()) {
            return [
                'reversed_delta' => 0.0,
                'total_reversed' => $this->roundMoney((float) ($payment->tip_reversed_amount ?? 0)),
            ];
        }

        $tipAmount = $this->roundMoney((float) ($payment->tip_amount ?? 0));
        if ($tipAmount <= 0) {
            return [
                'reversed_delta' => 0.0,
                'total_reversed' => 0.0,
            ];
        }

        $rule = in_array($rule, [self::RULE_PRORATA, self::RULE_MANUAL], true) ? $rule : self::RULE_PRORATA;
        $reversalAmount = $this->roundMoney(max(0, $reversalAmount));

        $currentTotalReversed = $this->roundMoney(
            (float) $payment->tipAllocations->sum(fn(PaymentTipAllocation $allocation) => (float) ($allocation->reversed_amount ?? 0))
        );

        $targetTotal = $replaceExisting
            ? min($tipAmount, $reversalAmount)
            : min($tipAmount, $this->roundMoney($currentTotalReversed + $reversalAmount));
        $delta = $this->roundMoney(max(0, $targetTotal - $currentTotalReversed));

        if ($replaceExisting) {
            foreach ($payment->tipAllocations as $allocation) {
                $allocation->forceFill(['reversed_amount' => 0])->save();
            }
            $payment->unsetRelation('tipAllocations');
            $payment->load('tipAllocations');
            $currentTotalReversed = 0.0;
            $delta = $targetTotal;
        }

        if ($delta <= 0) {
            return [
                'reversed_delta' => 0.0,
                'total_reversed' => $targetTotal,
            ];
        }

        $allocations = $payment->tipAllocations->keyBy('user_id');
        $remainingCapacities = $allocations->map(function (PaymentTipAllocation $allocation) {
            $amount = (float) ($allocation->amount ?? 0);
            $reversed = (float) ($allocation->reversed_amount ?? 0);

            return max(0, $this->roundMoney($amount - $reversed));
        });

        $capacityTotal = $this->roundMoney((float) $remainingCapacities->sum());
        $delta = min($delta, $capacityTotal);
        if ($delta <= 0) {
            return [
                'reversed_delta' => 0.0,
                'total_reversed' => $currentTotalReversed,
            ];
        }

        $increments = [];
        if ($rule === self::RULE_MANUAL) {
            $increments = $this->buildManualIncrements($remainingCapacities, $manualAmounts, $delta);
        } else {
            $increments = $this->distributeAmount($delta, $remainingCapacities->all());
        }

        foreach ($increments as $userId => $increment) {
            $model = $allocations->get((int) $userId);
            if (!$model || $increment <= 0) {
                continue;
            }

            $newReversed = $this->roundMoney((float) ($model->reversed_amount ?? 0) + (float) $increment);
            $model->forceFill([
                'reversed_amount' => min((float) ($model->amount ?? 0), $newReversed),
            ])->save();
        }

        $payment->unsetRelation('tipAllocations');
        $payment->load('tipAllocations');
        $newTotal = $this->roundMoney(
            (float) $payment->tipAllocations->sum(fn(PaymentTipAllocation $allocation) => (float) ($allocation->reversed_amount ?? 0))
        );

        return [
            'reversed_delta' => $this->roundMoney(max(0, $newTotal - $currentTotalReversed)),
            'total_reversed' => $newTotal,
        ];
    }

    private function resolveWeights(Payment $payment, string $strategy): array
    {
        $payment->loadMissing([
            'invoice.items.assignee:id,user_id',
            'invoice.work.teamMembers:id,user_id',
        ]);

        $itemCounts = collect($payment->invoice?->items ?? [])
            ->map(fn($item) => (int) ($item->assignee?->user_id ?? 0))
            ->filter(fn(int $userId) => $userId > 0)
            ->countBy()
            ->map(fn($count) => (float) $count)
            ->all();

        $workUserIds = collect($payment->invoice?->work?->teamMembers ?? [])
            ->map(fn($teamMember) => (int) ($teamMember->user_id ?? 0))
            ->filter(fn(int $userId) => $userId > 0)
            ->unique()
            ->values();

        if ($strategy === 'split') {
            if (!empty($itemCounts) && count($itemCounts) > 1) {
                return $this->normalizeWeights($itemCounts);
            }

            if ($workUserIds->count() > 1) {
                return $this->normalizeWeights(
                    $workUserIds->mapWithKeys(fn(int $userId) => [$userId => 1.0])->all()
                );
            }

            if (!empty($itemCounts)) {
                return $this->normalizeWeights($itemCounts);
            }
        }

        $assigneeUserId = (int) ($payment->tip_assignee_user_id ?? 0);
        if ($assigneeUserId <= 0 && !empty($itemCounts)) {
            $assigneeUserId = (int) collect($itemCounts)->sortDesc()->keys()->first();
        }
        if ($assigneeUserId <= 0 && $workUserIds->isNotEmpty()) {
            $assigneeUserId = (int) $workUserIds->first();
        }
        if ($assigneeUserId <= 0 && $payment->invoice) {
            $assigneeUserId = (int) (TipAssigneeResolver::resolveForInvoice($payment->invoice) ?? 0);
        }

        if ($assigneeUserId > 0) {
            return [$assigneeUserId => 1.0];
        }

        return [];
    }

    private function buildTipAllocations(float $tipAmount, array $weights): array
    {
        $weights = $this->normalizeWeights($weights);
        if ($tipAmount <= 0 || empty($weights)) {
            return [];
        }

        $distribution = $this->distributeAmount($tipAmount, $weights);
        if (empty($distribution)) {
            return [];
        }

        $rows = [];
        foreach ($distribution as $userId => $amount) {
            if ($amount <= 0) {
                continue;
            }

            $percent = $tipAmount > 0 ? $this->roundMoney(($amount / $tipAmount) * 100) : null;
            $rows[(int) $userId] = [
                'amount' => $this->roundMoney($amount),
                'percent' => $percent,
            ];
        }

        return $rows;
    }

    private function distributeAmount(float $total, array $weights): array
    {
        $total = $this->roundMoney(max(0, $total));
        $weights = $this->normalizeWeights($weights);
        if ($total <= 0 || empty($weights)) {
            return [];
        }

        $weightSum = array_sum($weights);
        if ($weightSum <= 0) {
            return [];
        }

        $userIds = array_keys($weights);
        $result = [];
        $remaining = $total;

        foreach ($userIds as $index => $userId) {
            $userId = (int) $userId;
            $isLast = $index === (count($userIds) - 1);
            $weight = (float) $weights[$userId];

            $amount = $isLast
                ? $remaining
                : $this->roundMoney($total * ($weight / $weightSum));

            $amount = min($remaining, max(0, $amount));
            $result[$userId] = $amount;
            $remaining = $this->roundMoney($remaining - $amount);
        }

        if ($remaining > 0 && !empty($result)) {
            $lastUserId = array_key_last($result);
            $result[$lastUserId] = $this->roundMoney($result[$lastUserId] + $remaining);
        }

        return $result;
    }

    private function buildManualIncrements(Collection $remainingCapacities, array $manualAmounts, float $expected): array
    {
        $normalized = collect($manualAmounts)
            ->mapWithKeys(function ($value, $key) {
                $userId = is_numeric($key) ? (int) $key : (int) ($value['user_id'] ?? 0);
                $amount = is_array($value) ? ($value['amount'] ?? 0) : $value;
                if ($userId <= 0 || !is_numeric($amount)) {
                    return [];
                }

                return [$userId => $this->roundMoney((float) $amount)];
            })
            ->filter(fn(float $amount) => $amount > 0);

        if ($normalized->isEmpty()) {
            throw ValidationException::withMessages([
                'allocations' => 'Manual split amounts are required.',
            ]);
        }

        foreach ($normalized as $userId => $amount) {
            $capacity = (float) ($remainingCapacities->get((int) $userId) ?? 0);
            if ($capacity <= 0) {
                throw ValidationException::withMessages([
                    'allocations' => 'Manual split contains an invalid team member.',
                ]);
            }
            if ($amount > $capacity) {
                throw ValidationException::withMessages([
                    'allocations' => 'Manual split exceeds available tip for at least one team member.',
                ]);
            }
        }

        $manualTotal = $this->roundMoney((float) $normalized->sum());
        if (abs($manualTotal - $expected) > 0.01) {
            throw ValidationException::withMessages([
                'amount' => 'Manual split total must match the reversal amount.',
            ]);
        }

        return $normalized->all();
    }

    private function normalizeWeights(array $weights): array
    {
        return collect($weights)
            ->mapWithKeys(function ($weight, $userId) {
                if (!is_numeric($userId) || !is_numeric($weight)) {
                    return [];
                }

                $userId = (int) $userId;
                $weight = (float) $weight;
                if ($userId <= 0 || $weight <= 0) {
                    return [];
                }

                return [$userId => $weight];
            })
            ->sortDesc()
            ->all();
    }

    private function normalizeStrategy(string $strategy): string
    {
        $strategy = strtolower(trim($strategy));
        return in_array($strategy, ['primary', 'split'], true) ? $strategy : 'primary';
    }

    private function supportsAllocations(): bool
    {
        if ($this->supportsAllocations !== null) {
            return $this->supportsAllocations;
        }

        $this->supportsAllocations = Schema::hasTable('payment_tip_allocations');
        return $this->supportsAllocations;
    }

    private function roundMoney(float $value): float
    {
        return round($value, 2);
    }
}

