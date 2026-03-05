<?php

namespace App\Enums;

enum CampaignAudienceSourceLogic: string
{
    case UNION = 'UNION';
    case INTERSECT = 'INTERSECT';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases()
        );
    }

    public static function normalize(?string $value): self
    {
        $candidate = strtoupper(trim((string) $value));

        return match ($candidate) {
            self::INTERSECT->value => self::INTERSECT,
            default => self::UNION,
        };
    }
}

