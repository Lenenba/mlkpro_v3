<?php

namespace App\Enums;

enum BillingPeriod: string
{
    case MONTHLY = 'monthly';
    case YEARLY = 'yearly';

    public static function default(): self
    {
        return self::MONTHLY;
    }

    public static function values(): array
    {
        return array_map(
            static fn (self $period): string => $period->value,
            self::cases()
        );
    }

    public static function tryFromMixed(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }
}
