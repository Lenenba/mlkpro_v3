<?php

namespace App\Services;

use App\Enums\CurrencyCode;
use App\Exceptions\Billing\TenantCurrencyChangeNotAllowedException;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Reservation;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PreventUnsafeTenantCurrencyChange
{
    public function ensureCanChange(User $tenant, CurrencyCode|string|null $nextCurrency): void
    {
        $targetCurrency = CurrencyCode::tryFromMixed($nextCurrency) ?? CurrencyCode::default();
        $currentCurrency = CurrencyCode::tryFromMixed($tenant->currency_code) ?? CurrencyCode::default();

        if ($currentCurrency === $targetCurrency) {
            return;
        }

        if (! $this->hasBusinessActivity($tenant)) {
            return;
        }

        throw new TenantCurrencyChangeNotAllowedException(
            'Tenant currency cannot be changed after business activity exists.'
        );
    }

    public function hasBusinessActivity(User $tenant): bool
    {
        return Product::query()->where('user_id', $tenant->id)->exists()
            || Quote::query()->where('user_id', $tenant->id)->exists()
            || DB::table('invoices')->where('user_id', $tenant->id)->exists()
            || Sale::query()->where('user_id', $tenant->id)->exists()
            || Payment::query()->where('user_id', $tenant->id)->exists()
            || DB::table('stripe_subscriptions')->where('user_id', $tenant->id)->exists()
            || Reservation::query()->where('account_id', $tenant->id)->exists();
    }
}
