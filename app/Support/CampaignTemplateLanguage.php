<?php

namespace App\Support;

use App\Models\Campaign;
use Illuminate\Support\Str;

class CampaignTemplateLanguage
{
    /**
     * @return array<int, string>
     */
    public static function supported(): array
    {
        return ['FR', 'EN', 'ES'];
    }

    public static function normalize(?string $language, string $fallback = 'EN'): string
    {
        $value = Str::upper(trim((string) $language));

        if (Str::startsWith($value, 'FR')) {
            return 'FR';
        }

        if (Str::startsWith($value, 'ES')) {
            return 'ES';
        }

        if (Str::startsWith($value, 'EN')) {
            return 'EN';
        }

        return in_array($fallback, self::supported(), true) ? $fallback : 'EN';
    }

    public static function fromLocale(?string $locale): string
    {
        return match (LocalePreference::normalize($locale)) {
            'fr' => 'FR',
            'es' => 'ES',
            default => 'EN',
        };
    }

    public static function defaultModeForLocale(?string $locale): string
    {
        return match (self::fromLocale($locale)) {
            'FR' => Campaign::LANGUAGE_MODE_FR,
            'EN' => Campaign::LANGUAGE_MODE_EN,
            'ES' => Campaign::LANGUAGE_MODE_ES,
            default => Campaign::LANGUAGE_MODE_PREFERRED,
        };
    }
}
