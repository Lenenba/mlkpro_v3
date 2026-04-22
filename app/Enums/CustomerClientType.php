<?php

namespace App\Enums;

enum CustomerClientType: string
{
    case COMPANY = 'company';
    case INDIVIDUAL = 'individual';

    public static function default(): self
    {
        return self::INDIVIDUAL;
    }

    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    public static function infer(mixed $value, ?string $companyName = null): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value)) {
            $resolved = self::tryFrom(strtolower(trim($value)));
            if ($resolved instanceof self) {
                return $resolved;
            }
        }

        return trim((string) $companyName) !== ''
            ? self::COMPANY
            : self::default();
    }
}
