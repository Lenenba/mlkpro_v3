<?php

namespace App\Enums;

enum CampaignChannel: string
{
    case EMAIL = 'EMAIL';
    case SMS = 'SMS';
    case IN_APP = 'IN_APP';
    case WHATSAPP = 'WHATSAPP';

    public static function values(bool $includeExperimental = false): array
    {
        $values = [
            self::EMAIL->value,
            self::SMS->value,
            self::IN_APP->value,
        ];

        if ($includeExperimental) {
            $values[] = self::WHATSAPP->value;
        }

        return $values;
    }
}

