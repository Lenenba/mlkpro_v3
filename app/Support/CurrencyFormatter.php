<?php

namespace App\Support;

use App\Enums\CurrencyCode;
use NumberFormatter;

class CurrencyFormatter
{
    private const LOCALE_BY_CURRENCY = [
        CurrencyCode::CAD->value => 'en_CA',
        CurrencyCode::USD->value => 'en_US',
        CurrencyCode::EUR->value => 'fr_FR',
    ];

    public static function format(float|int|string|null $amount, CurrencyCode|string|null $currencyCode): string
    {
        $currency = self::normalizeCurrencyCode($currencyCode);
        $numericAmount = is_numeric($amount) ? (float) $amount : 0.0;
        $formatter = self::formatterFor($currency);

        if ($formatter) {
            $formatted = $formatter->formatCurrency($numericAmount, $currency->value);
            if (is_string($formatted) && $formatted !== '') {
                return $formatted;
            }
        }

        $normalized = number_format($numericAmount, 2, '.', ',');

        return match ($currency) {
            CurrencyCode::EUR => $normalized.' €',
            default => '$'.$normalized,
        };
    }

    public static function localeFor(CurrencyCode|string|null $currencyCode): string
    {
        $currency = self::normalizeCurrencyCode($currencyCode);

        return self::LOCALE_BY_CURRENCY[$currency->value] ?? self::LOCALE_BY_CURRENCY[CurrencyCode::default()->value];
    }

    public static function normalizeCurrencyCode(CurrencyCode|string|null $currencyCode): CurrencyCode
    {
        return CurrencyCode::tryFromMixed($currencyCode) ?? CurrencyCode::default();
    }

    private static function formatterFor(CurrencyCode $currency): ?NumberFormatter
    {
        if (! class_exists(NumberFormatter::class)) {
            return null;
        }

        return new NumberFormatter(self::localeFor($currency), NumberFormatter::CURRENCY);
    }
}
