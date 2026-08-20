<?php

namespace App\Enums;

use InvalidArgumentException;

enum DemoDataVolume: string
{
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';

    public static function normalize(self|string|null $value, self $default = self::Medium): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        if ($normalized === '') {
            return $default;
        }

        return match ($normalized) {
            self::Small->value, 'light' => self::Small,
            self::Medium->value, 'standard' => self::Medium,
            self::Large->value, 'immersive' => self::Large,
            default => throw new InvalidArgumentException(sprintf('Unsupported demo data volume [%s].', $value)),
        };
    }

    public static function fromLegacyProfile(?string $profile): self
    {
        return self::normalize($profile);
    }

    public function select(int $small, int $medium, int $large): int
    {
        return match ($this) {
            self::Small => $small,
            self::Medium => $medium,
            self::Large => $large,
        };
    }
}
