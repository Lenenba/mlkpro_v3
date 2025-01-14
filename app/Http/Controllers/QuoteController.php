<?php

namespace App\Http\Controllers;

use App\Models\Tax;
use Inertia\Inertia;
use App\Models\Product;
use App\Models\Customer;
use App\Http\Controllers\Controller;

class QuoteController extends Controller
{
    public function create (Customer $customer)
    {
        return Inertia::render('Quote/Create', [
            'customer' => $customer,
            'products' => Product::all(),
            'taxes' => Tax::all(),
        ]);
    }
}
