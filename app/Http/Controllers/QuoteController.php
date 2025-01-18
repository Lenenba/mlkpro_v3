<?php

namespace App\Http\Controllers;

use App\Models\Tax;
use Inertia\Inertia;
use App\Models\Product;
use App\Models\Customer;
use App\Http\Controllers\Controller;
use App\Models\Quote;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class QuoteController extends Controller
{
    use AuthorizesRequests;

    public function create(Customer $customer)
    {
        $quote = new Quote();
        $this->authorize('create', $quote);

        return Inertia::render('Quote/Create', [
            'lastQuotesNumber' => $this->generateNextNumber($customer->quotes->last()->number ?? null),
            'customer' => $customer->with(['properties'])->first(),
            'products' => Product::all(),
            'taxes' => Tax::all(),
        ]);
    }

    public static function generateNextNumber($lastNumber): string
    {
        // Si aucun numéro précédent, retourner le premier
        if (is_null($lastNumber)) {
            return 'Q001';
        }

        // Extraire la partie numérique du dernier numéro
        preg_match('/Q(\d+)/', $lastNumber, $matches);

        if (!isset($matches[1])) {
            throw new \Exception("Invalid number format: $lastNumber");
        }

        $lastNumericPart = (int) $matches[1];

        // Incrémenter la partie numérique
        $nextNumericPart = $lastNumericPart + 1;

        // Générer le nouveau numéro en format "Q" suivi de 3 chiffres
        return 'Q' . str_pad($nextNumericPart, 3, '0', STR_PAD_LEFT);
    }
}
