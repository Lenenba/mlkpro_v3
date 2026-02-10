<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class TipCalculator
{
    public static function resolve(float $baseAmount, array $input = [], ?array $settings = null): array
    {
        $baseAmount = self::roundMoney(max(0, $baseAmount));
        $settings = $settings ? TipSettingsResolver::sanitize($settings) : TipSettingsResolver::defaults();
        $maxPercent = (float) ($settings['max_percent'] ?? 30);
        $maxFixed = (float) ($settings['max_fixed_amount'] ?? 200);

        $mode = strtolower(trim((string) ($input['tip_mode'] ?? '')));
        $tipEnabled = self::parseBoolean($input['tip_enabled'] ?? null);
        $tipPercent = self::parseNullableFloat($input['tip_percent'] ?? null);
        $tipAmountInput = self::parseNullableFloat($input['tip_amount'] ?? null);

        if ($mode === '') {
            if ($tipEnabled === false) {
                $mode = 'none';
            } elseif ($tipPercent !== null && $tipPercent > 0) {
                $mode = 'percent';
            } elseif ($tipAmountInput !== null && $tipAmountInput > 0) {
                $mode = 'fixed';
            } else {
                $mode = 'none';
            }
        }

        if (!in_array($mode, ['none', 'percent', 'fixed'], true)) {
            throw ValidationException::withMessages([
                'tip_mode' => 'Invalid tip mode.',
            ]);
        }

        $tipAmount = 0.0;
        $finalPercent = null;
        $tipType = 'none';

        if ($mode === 'percent') {
            if ($tipPercent === null) {
                throw ValidationException::withMessages([
                    'tip_percent' => 'Tip percent is required.',
                ]);
            }
            if ($tipPercent < 0) {
                throw ValidationException::withMessages([
                    'tip_percent' => 'Tip percent must be zero or greater.',
                ]);
            }
            if ($maxPercent > 0 && $tipPercent > $maxPercent) {
                throw ValidationException::withMessages([
                    'tip_percent' => 'Tip percent exceeds the allowed limit.',
                ]);
            }

            $finalPercent = round($tipPercent, 2);
            $tipAmount = self::roundMoney($baseAmount * ($finalPercent / 100));
            $tipType = $tipAmount > 0 ? 'percent' : 'none';
        } elseif ($mode === 'fixed') {
            $tipAmountInput = $tipAmountInput ?? 0;
            if ($tipAmountInput < 0) {
                throw ValidationException::withMessages([
                    'tip_amount' => 'Tip amount must be zero or greater.',
                ]);
            }
            if ($maxFixed > 0 && $tipAmountInput > $maxFixed) {
                throw ValidationException::withMessages([
                    'tip_amount' => 'Tip amount exceeds the allowed limit.',
                ]);
            }

            $tipAmount = self::roundMoney($tipAmountInput);
            $tipType = $tipAmount > 0 ? 'fixed' : 'none';
        }

        if ($maxFixed > 0 && $tipAmount > $maxFixed) {
            throw ValidationException::withMessages([
                'tip_amount' => 'Tip amount exceeds the allowed limit.',
            ]);
        }

        return [
            'tip_type' => $tipType,
            'tip_percent' => $tipType === 'percent' ? $finalPercent : null,
            'tip_amount' => $tipType === 'none' ? 0.0 : $tipAmount,
            'tip_base_amount' => $baseAmount,
            'charged_total' => self::roundMoney($baseAmount + $tipAmount),
        ];
    }

    private static function parseBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $parsed;
    }

    private static function parseNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private static function roundMoney(float $value): float
    {
        return round($value, 2);
    }
}
