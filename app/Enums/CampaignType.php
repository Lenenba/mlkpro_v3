<?php

namespace App\Enums;

enum CampaignType: string
{
    case NEW_OFFER = 'NEW_OFFER';
    case BACK_AVAILABLE = 'BACK_AVAILABLE';
    case PROMOTION = 'PROMOTION';
    case CROSS_SELL = 'CROSS_SELL';
    case WINBACK = 'WINBACK';
    case ANNOUNCEMENT = 'ANNOUNCEMENT';

    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    public static function normalize(?string $value): ?self
    {
        $candidate = strtoupper((string) $value);
        if ($candidate === '') {
            return null;
        }

        $legacyMap = [
            'NEW_PRODUCT' => self::NEW_OFFER,
            'BACK_IN_STOCK' => self::BACK_AVAILABLE,
        ];

        if (isset($legacyMap[$candidate])) {
            return $legacyMap[$candidate];
        }

        return self::tryFrom($candidate);
    }
}

