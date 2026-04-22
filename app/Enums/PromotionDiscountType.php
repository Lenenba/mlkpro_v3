<?php

namespace App\Enums;

enum PromotionDiscountType: string
{
    case PERCENTAGE = 'percentage';
    case FIXED = 'fixed';

    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
