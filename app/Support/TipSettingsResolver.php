<?php

namespace App\Support;

use App\Models\User;

class TipSettingsResolver
{
    public static function defaults(): array
    {
        return self::sanitize([
            'max_percent' => config('tips.max_percent', 30),
            'max_fixed_amount' => config('tips.max_fixed_amount', 200),
            'default_percent' => config('tips.default_percent', 10),
            'quick_percents' => config('tips.quick_percents', [5, 10, 15, 20]),
            'quick_fixed_amounts' => config('tips.quick_fixed_amounts', [2, 5, 10]),
            'allocation_strategy' => config('tips.allocation_strategy', 'primary'),
            'partial_refund_rule' => config('tips.partial_refund_rule', 'prorata'),
        ]);
    }

    public static function forAccountId(?int $accountId): array
    {
        if (!$accountId || $accountId <= 0) {
            return self::defaults();
        }

        $owner = User::query()->select(['id', 'company_store_settings'])->find($accountId);
        if (!$owner) {
            return self::defaults();
        }

        return self::forUser($owner);
    }

    public static function forUser(?User $user): array
    {
        $defaults = self::defaults();
        if (!$user) {
            return $defaults;
        }

        $storeSettings = is_array($user->company_store_settings) ? $user->company_store_settings : [];
        $tips = isset($storeSettings['tips']) && is_array($storeSettings['tips'])
            ? $storeSettings['tips']
            : [];

        return self::sanitize(array_replace($defaults, $tips));
    }

    public static function sanitize(array $settings): array
    {
        $maxPercent = self::positiveOrDefault($settings['max_percent'] ?? null, 30);
        $maxFixed = self::positiveOrDefault($settings['max_fixed_amount'] ?? null, 200);
        $defaultPercent = self::clamp(
            self::positiveOrDefault($settings['default_percent'] ?? null, 10),
            0,
            $maxPercent > 0 ? $maxPercent : 100
        );

        $quickPercents = self::sanitizeQuickValues(
            $settings['quick_percents'] ?? [],
            $maxPercent > 0 ? $maxPercent : null
        );
        if (empty($quickPercents)) {
            $quickPercents = [5, 10, 15, 20];
        }

        $quickFixed = self::sanitizeQuickValues(
            $settings['quick_fixed_amounts'] ?? [],
            $maxFixed > 0 ? $maxFixed : null
        );
        if (empty($quickFixed)) {
            $quickFixed = [2, 5, 10];
        }

        $allocationStrategy = strtolower(trim((string) ($settings['allocation_strategy'] ?? 'primary')));
        if (!in_array($allocationStrategy, ['primary', 'split'], true)) {
            $allocationStrategy = 'primary';
        }

        $partialRefundRule = strtolower(trim((string) ($settings['partial_refund_rule'] ?? 'prorata')));
        if (!in_array($partialRefundRule, ['prorata', 'manual'], true)) {
            $partialRefundRule = 'prorata';
        }

        return [
            'max_percent' => round($maxPercent, 2),
            'max_fixed_amount' => round($maxFixed, 2),
            'default_percent' => round($defaultPercent, 2),
            'quick_percents' => $quickPercents,
            'quick_fixed_amounts' => $quickFixed,
            'allocation_strategy' => $allocationStrategy,
            'partial_refund_rule' => $partialRefundRule,
        ];
    }

    private static function sanitizeQuickValues(mixed $values, ?float $max = null): array
    {
        if (!is_array($values)) {
            return [];
        }

        $normalized = collect($values)
            ->filter(fn($value) => is_numeric($value))
            ->map(fn($value) => round((float) $value, 2))
            ->filter(fn($value) => $value > 0)
            ->map(function (float $value) use ($max) {
                if ($max !== null && $max > 0) {
                    return min($value, $max);
                }

                return $value;
            })
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $normalized;
    }

    private static function positiveOrDefault(mixed $value, float $default): float
    {
        if (!is_numeric($value)) {
            return $default;
        }

        $parsed = (float) $value;
        if ($parsed < 0) {
            return $default;
        }

        return $parsed;
    }

    private static function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}

