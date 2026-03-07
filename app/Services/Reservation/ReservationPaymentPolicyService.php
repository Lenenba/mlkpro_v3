<?php

namespace App\Services\Reservation;

use App\Models\Reservation;

class ReservationPaymentPolicyService
{
    public function metadataForStatusTransition(
        Reservation $reservation,
        string $nextStatus,
        array $settings = []
    ): ?array {
        $metadata = is_array($reservation->metadata) ? $reservation->metadata : [];
        $policy = $this->normalizePaymentPolicy($metadata['payment_policy'] ?? null);

        if (
            ! $policy['deposit_required']
            && $policy['deposit_amount'] <= 0
            && ! $policy['no_show_fee_enabled']
            && $policy['no_show_fee_amount'] <= 0
            && ! empty($settings)
        ) {
            $policy = $this->paymentPolicyFromSettings($settings);
        }

        $metadata['payment_policy'] = $policy;

        $state = is_array($metadata['payment_state'] ?? null) ? $metadata['payment_state'] : [];
        $state['deposit_status'] = (string) (
            $state['deposit_status']
            ?? ($policy['deposit_required'] ? 'required' : 'not_required')
        );
        $state['deposit_due_amount'] = $policy['deposit_required'] ? $policy['deposit_amount'] : 0.0;
        $state['no_show_fee_status'] = (string) (
            $state['no_show_fee_status']
            ?? ($policy['no_show_fee_enabled'] ? 'not_applied' : 'not_applicable')
        );
        $state['no_show_fee_amount'] = $policy['no_show_fee_enabled'] ? $policy['no_show_fee_amount'] : 0.0;

        if ($nextStatus === Reservation::STATUS_NO_SHOW) {
            if ($policy['no_show_fee_enabled']) {
                $state['no_show_fee_status'] = 'charge_required';
                $state['no_show_fee_recorded_at'] = now('UTC')->toIso8601String();
            }
            if ($policy['deposit_required']) {
                $state['deposit_status'] = 'forfeited';
            }
        } elseif ($nextStatus === Reservation::STATUS_COMPLETED) {
            if ($policy['deposit_required'] && $state['deposit_status'] === 'required') {
                $state['deposit_status'] = 'due_on_invoice';
            }
            if ($policy['no_show_fee_enabled']) {
                $state['no_show_fee_status'] = 'waived';
                unset($state['no_show_fee_recorded_at']);
            }
        } elseif ($nextStatus === Reservation::STATUS_CANCELLED) {
            if ($policy['deposit_required'] && $state['deposit_status'] === 'required') {
                $state['deposit_status'] = 'refundable';
            }
            if ($policy['no_show_fee_enabled'] && $state['no_show_fee_status'] === 'charge_required') {
                $state['no_show_fee_status'] = 'waived';
                unset($state['no_show_fee_recorded_at']);
            }
        } elseif ($policy['no_show_fee_enabled'] && $state['no_show_fee_status'] === 'charge_required') {
            $state['no_show_fee_status'] = 'not_applied';
            unset($state['no_show_fee_recorded_at']);
        }

        $metadata['payment_state'] = $state;

        return $metadata ?: null;
    }

    public function mergePolicyMetadata(array $metadata, array $settings): array
    {
        $policy = $this->paymentPolicyFromSettings($settings);
        $metadata['payment_policy'] = $policy;

        $state = is_array($metadata['payment_state'] ?? null) ? $metadata['payment_state'] : [];
        $state['deposit_status'] = (string) (
            $state['deposit_status']
            ?? ($policy['deposit_required'] ? 'required' : 'not_required')
        );
        $state['deposit_due_amount'] = $policy['deposit_required'] ? $policy['deposit_amount'] : 0.0;
        $state['no_show_fee_status'] = (string) (
            $state['no_show_fee_status']
            ?? ($policy['no_show_fee_enabled'] ? 'not_applied' : 'not_applicable')
        );
        $state['no_show_fee_amount'] = $policy['no_show_fee_enabled'] ? $policy['no_show_fee_amount'] : 0.0;

        $metadata['payment_state'] = $state;

        return $metadata;
    }

    private function normalizeMoney(mixed $value): float
    {
        return max(0, round((float) $value, 2));
    }

    private function normalizePaymentPolicy(mixed $value): array
    {
        $policy = is_array($value) ? $value : [];
        $depositAmount = $this->normalizeMoney($policy['deposit_amount'] ?? 0);
        $noShowFeeAmount = $this->normalizeMoney($policy['no_show_fee_amount'] ?? 0);

        return [
            'deposit_required' => (bool) ($policy['deposit_required'] ?? false) && $depositAmount > 0,
            'deposit_amount' => $depositAmount,
            'no_show_fee_enabled' => (bool) ($policy['no_show_fee_enabled'] ?? false) && $noShowFeeAmount > 0,
            'no_show_fee_amount' => $noShowFeeAmount,
            'captured_at' => $policy['captured_at'] ?? now('UTC')->toIso8601String(),
        ];
    }

    private function paymentPolicyFromSettings(array $settings): array
    {
        return $this->normalizePaymentPolicy([
            'deposit_required' => (bool) ($settings['deposit_required'] ?? false),
            'deposit_amount' => $settings['deposit_amount'] ?? 0,
            'no_show_fee_enabled' => (bool) ($settings['no_show_fee_enabled'] ?? false),
            'no_show_fee_amount' => $settings['no_show_fee_amount'] ?? 0,
            'captured_at' => now('UTC')->toIso8601String(),
        ]);
    }
}
