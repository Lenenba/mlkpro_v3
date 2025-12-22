<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class CompanySettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $companyLogo = null;
        if ($user->company_logo) {
            $path = $user->company_logo;
            $companyLogo = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
                ? $path
                : Storage::disk('public')->url($path);
        }

        return Inertia::render('Settings/Company', [
            'company' => [
                'company_name' => $user->company_name,
                'company_logo' => $companyLogo,
                'company_description' => $user->company_description,
                'company_country' => $user->company_country,
                'company_province' => $user->company_province,
                'company_city' => $user->company_city,
                'company_type' => $user->company_type,
            ],
            'categories' => ProductCategory::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'company_logo' => 'nullable|image|max:2048',
            'company_description' => 'nullable|string|max:2000',
            'company_country' => 'nullable|string|max:255',
            'company_province' => 'nullable|string|max:255',
            'company_city' => 'nullable|string|max:255',
            'company_type' => 'required|string|in:services,products',
        ]);

        $companyLogoPath = $user->company_logo;
        if ($request->hasFile('company_logo')) {
            $companyLogoPath = $request->file('company_logo')->store('company/logos', 'public');

            if ($user->company_logo && !str_starts_with($user->company_logo, 'http://') && !str_starts_with($user->company_logo, 'https://')) {
                Storage::disk('public')->delete($user->company_logo);
            }
        }

        $user->update([
            'company_name' => $validated['company_name'],
            'company_logo' => $companyLogoPath,
            'company_description' => $validated['company_description'] ?? null,
            'company_country' => $validated['company_country'] ?? null,
            'company_province' => $validated['company_province'] ?? null,
            'company_city' => $validated['company_city'] ?? null,
            'company_type' => $validated['company_type'],
        ]);

        return redirect()->back()->with('success', 'Company settings updated.');
    }
}
