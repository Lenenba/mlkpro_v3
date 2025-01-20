<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

class ProductsSearchController extends Controller
{
        /**
     * Search for products by name.
     */
    public function __invoke(Request $request)
    {
        $query = $request->input('query');
        $products = Product::where('name', 'like', "%{$query}%")
            ->limit(10)
            ->get(['id', 'name', 'price', 'image']); // Sélectionne uniquement les colonnes nécessaires

        return response()->json($products);
    }
}
