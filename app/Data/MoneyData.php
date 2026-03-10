<?php

namespace App\Data;

use App\Enums\CurrencyCode;

final readonly class MoneyData
{
    public function __construct(
        public CurrencyCode $currencyCode,
        public string $amount,
    ) {}

    public static function fromNumeric(mixed $amount, CurrencyCode|string $currencyCode): self
    {
        $currency = $currencyCode instanceof CurrencyCode
            ? $currencyCode
            : (CurrencyCode::tryFromMixed($currencyCode) ?? CurrencyCode::default());

        return new self(
            $currency,
            number_format((float) $amount, $currency->decimalPlaces(), '.', '')
        );
    }

    public function asFloat(): float
    {
        return (float) $this->amount;
    }

    public function minorAmount(): int
    {
        return (int) round($this->asFloat() * (10 ** $this->currencyCode->decimalPlaces()));
    }

    public function stripeCurrency(): string
    {
        return $this->currencyCode->stripeValue();
    }
}
