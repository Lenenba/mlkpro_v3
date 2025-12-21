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
            ->byUser($request->user()->id)
            ->where('is_active', true)
            ->limit(10)
            ->get(['id', 'name', 'price', 'image']); // Sélectionne uniquement les colonnes nécessaires

        return response()->json($products);
    }
}
