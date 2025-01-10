<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Utils\FileHandler;
use Illuminate\Http\Request;
use App\Models\ProductCategory;
use App\Http\Requests\ProductRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class ProductController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of products with pagination and categories.
     */
    public function index(?Request $request)
    {
        $filters = $request->only(['name']);
        $user = Auth::user();
        $count = Product::byUser($user->id)->count();
        return inertia('Product/Index', [
            'user' => $user,
            'count' => $count,
            'filters' => $filters,
            'products' => Product::mostRecent()
                ->filter($filters)
                ->with(['category', 'user'])
                ->byUser(Auth::user()->id)
                ->simplePaginate(8)
                ->withQueryString()
        ]);
    }

    /**
     * Show the form for creating a new product.
     */
    public function create()
    {
        $user = Auth::user();

        return inertia('Product/Create', [
            'user' => $user,
            'categories' => ProductCategory::all()
        ]);
    }

    /**
     * Store a newly created product in the database.
     */
    public function store(ProductRequest $request)
    {
        $validated = $request->validated();
        $validated['image'] = FileHandler::handleImageUpload($request, 'image', 'products/product.jpg');

        $product = $request->user()->products()->create($validated);

        $product->price = $validated['price'];
        $product->number = 'PROD' . str_pad($product->id, 6, '0', STR_PAD_LEFT);
        $product->image = $validated['image'];
        $product->save();

        return redirect()->route('product.index')->with('success', 'Product created successfully.');
    }

    /**
     * Display the specified product.
     */
    public function show(Product $product)
    {
        try {
            $this->authorize('update', $product); // Vérification d'autorisation
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            // Redirection avec un message d'erreur si l'autorisation échoue
            return redirect()->back()->with('error', 'You are not authorized to edit this product.');
        }

        return inertia('Product/Show', [
            'product' => $product->load(['category', 'user']),
            'categories' => ProductCategory::all()
        ]);
    }

    /**
     * Update the specified product in the database.
     */
    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        try {
            $this->authorize('update', $product); // Vérification d'autorisation
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            // Redirection avec un message d'erreur si l'autorisation échoue
            return redirect()->back()->with('error', 'You are not authorized to edit this product.');
        }

        $validated = $request->validated();
        $validated['image'] = FileHandler::handleImageUpload($request, 'image', 'products/product.jpg', $product->image);
        $product->price = $validated['price'];
        $product->image = $validated['image'];
        $product->update($validated);

        return redirect()->route('product.index')->with('success', 'Product updated successfully.');
    }

    /**
     * Remove the specified product from the database.
     */
    public function destroy(Product $product)
    {
        $this->authorize('delete', $product);

        FileHandler::deleteFile($product->image, 'products/product.jpg');
        $product->delete();

        return redirect()->route('product.index')->with('success', 'Product deleted successfully.');
    }
}
