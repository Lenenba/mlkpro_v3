<?php

namespace App\Enums;

enum CampaignOfferMode: string
{
    case PRODUCTS = 'PRODUCTS';
    case SERVICES = 'SERVICES';
    case MIXED = 'MIXED';

    public static function values(): array
    {
        return array_map(static fn (self $mode): string => $mode->value, self::cases());
    }
}

