<?php

namespace App\Enums;

enum OfferType: string
{
    case PRODUCT = 'product';
    case SERVICE = 'service';

    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}

