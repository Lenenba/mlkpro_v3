<?php

namespace App\Services;

use App\Enums\CurrencyCode;
use App\Models\Product;
use App\Models\User;

class AssignCurrencyToCatalogItem
{
    public function execute(Product $product, User $tenant): Product
    {
        if ($product->currency_code) {
            return $product;
        }

        $currency = CurrencyCode::tryFromMixed($tenant->currency_code) ?? CurrencyCode::default();
        $product->currency_code = $currency->value;

        return $product;
    }
}
