<?php

namespace App\Data;

use App\Enums\CurrencyCode;

final readonly class TenantCurrencyData
{
    public function __construct(
        public int $tenantId,
        public CurrencyCode $currencyCode,
        public ?string $country,
        public ?string $locale,
    ) {}
}
