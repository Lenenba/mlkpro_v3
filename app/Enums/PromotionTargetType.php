<?php

namespace App\Enums;

enum PromotionTargetType: string
{
    case GLOBAL = 'global';
    case CLIENT = 'client';
    case PRODUCT = 'product';
    case SERVICE = 'service';

    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    public function requiresTargetId(): bool
    {
        return $this !== self::GLOBAL;
    }

    public function specificityRank(): int
    {
        return match ($this) {
            self::GLOBAL => 1,
            self::CLIENT, self::PRODUCT, self::SERVICE => 2,
        };
    }
}
