<?php

namespace App\Enums;

enum CurrencyCode: string
{
    case CAD = 'CAD';
    case EUR = 'EUR';
    case USD = 'USD';

    public static function default(): self
    {
        return self::CAD;
    }

    public static function values(): array
    {
        return array_map(
            static fn (self $currency): string => $currency->value,
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

        return self::tryFrom(strtoupper(trim($value)));
    }

    public function stripeValue(): string
    {
        return strtolower($this->value);
    }

    public function decimalPlaces(): int
    {
        return 2;
    }
}
