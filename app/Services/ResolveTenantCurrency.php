<?php

namespace App\Services;

use App\Data\TenantCurrencyData;
use App\Enums\CurrencyCode;
use App\Models\User;

class ResolveTenantCurrency
{
    public function forUser(User $user): TenantCurrencyData
    {
        $accountOwnerId = $user->accountOwnerId();
        $owner = $accountOwnerId === (int) $user->id
            ? $user
            : User::query()->findOrFail($accountOwnerId);

        return new TenantCurrencyData(
            $owner->id,
            CurrencyCode::tryFromMixed($owner->currency_code) ?? CurrencyCode::default(),
            $owner->company_country,
            $owner->locale,
        );
    }

    public function forAccountId(int $accountId): TenantCurrencyData
    {
        return $this->forUser(User::query()->findOrFail($accountId));
    }
}
