<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductCategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $name = preg_replace('/\s+/', ' ', trim($validated['name'] ?? ''));
        if ($name === '') {
            return redirect()->back()->withErrors(['name' => 'Category name is required.']);
        }

        ProductCategory::firstOrCreate(['name' => $name]);

        return redirect()->back()->with('success', 'Category added successfully.');
    }
}
