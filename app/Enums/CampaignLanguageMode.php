<?php

namespace App\Enums;

enum CampaignLanguageMode: string
{
    case PREFERRED = 'PREFERRED';
    case FR = 'FR';
    case EN = 'EN';
    case BOTH = 'BOTH';

    public static function values(): array
    {
        return array_map(static fn (self $mode): string => $mode->value, self::cases());
    }
}

