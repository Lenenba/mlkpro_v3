<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Product;
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

        $accountId = $user->accountOwnerId();
        $category = ProductCategory::resolveForAccount($accountId, $user->id, $name);
        if (!$category) {
            return redirect()->back()->withErrors(['name' => 'Category name is required.']);
        }

        if (!$category->user_id) {
            $otherUsage = Product::query()
                ->where('category_id', $category->id)
                ->where('user_id', '!=', $accountId)
                ->exists();

            if (!$otherUsage) {
                $category->update([
                    'user_id' => $accountId,
                    'created_by_user_id' => $category->created_by_user_id ?? $user->id,
                ]);
            }
        }

        if ($category->archived_at && $category->user_id === $accountId) {
            $category->update(['archived_at' => null]);
        }

        return redirect()->back()->with('success', 'Category added successfully.');
    }

    public function archive(Request $request, ProductCategory $category): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $accountId = $user->accountOwnerId();
        if ((int) $category->user_id !== (int) $accountId) {
            abort(403);
        }

        if (!$category->archived_at) {
            $category->update(['archived_at' => now()]);
        }

        return redirect()->back()->with('success', 'Category archived successfully.');
    }

    public function update(Request $request, ProductCategory $category): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $accountId = $user->accountOwnerId();
        if ((int) $category->user_id !== (int) $accountId) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $name = ProductCategory::normalizeName($validated['name'] ?? '');
        if ($name === '') {
            return redirect()->back()->withErrors(['name' => 'Category name is required.']);
        }

        $existing = ProductCategory::query()
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->where(function ($query) use ($accountId) {
                $query->where('user_id', $accountId)
                    ->orWhereNull('user_id');
            })
            ->where('id', '!=', $category->id)
            ->first();

        if ($existing) {
            return redirect()->back()->withErrors(['name' => 'Category name already exists.']);
        }

        $category->update(['name' => $name]);

        return redirect()->back()->with('success', 'Category updated successfully.');
    }

    public function restore(Request $request, ProductCategory $category): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $accountId = $user->accountOwnerId();
        if ((int) $category->user_id !== (int) $accountId) {
            abort(403);
        }

        if ($category->archived_at) {
            $category->update(['archived_at' => null]);
        }

        return redirect()->back()->with('success', 'Category restored successfully.');
    }
}
